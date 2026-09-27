<?php

namespace App\Models;

use App\Models\Concerns\Acknowledgeable;
use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * 1 baris = 1 karyawan per hari. Lihat catatan lengkap soal entry_type di
 * migration create_attendances_table & enhance_attendances_table (audit
 * 2026-08-26 — nambah 'alpha'/'leave', deteksi mock GPS, pulang cepat).
 */
class Attendance extends Model
{
    use LogsActivity;
    use Acknowledgeable;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di AttendanceResource::getEloquentQuery() SENGAJA
    // DIBIARKAN (bukan dihapus) sebagai defense-in-depth; filter dari
    // scope ini no-op/redundan di sana, tapi jadi satu-satunya proteksi
    // untuk query LANGSUNG ke Attendance:: di tempat lain (widget/
    // report/service) yang sebelumnya rawan lupa di-scope.
    use HasStoreScope;

    // Dipakai kalau Store::attendance_radius_meters/late_tolerance_minutes
    // kosong (belum diatur admin) — lihat migration
    // add_attendance_settings_to_stores_table.
    public const DEFAULT_RADIUS_METERS = 150;
    public const DEFAULT_LATE_TOLERANCE_MINUTES = 15;

    protected $fillable = [
        'user_id',
        'store_id',
        'date',
        'entry_type',
        'clock_in_at',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_distance_meters',
        'clock_in_is_mocked',
        'clock_out_at',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_is_mocked',
        'late_minutes',
        'early_leave_minutes',
        // Gap ditutup 2026-09-26 (audit Absensi Karyawan) -- lihat
        // App\Console\Commands\NotifyForgottenClockouts.
        'forgotten_clockout_notified_at',
        'note',
        'recorded_by',
    ];

    protected $casts = [
        'date'                 => 'date',
        'clock_in_at'          => 'datetime',
        'clock_in_latitude'    => 'float',
        'clock_in_longitude'   => 'float',
        'clock_in_is_mocked'   => 'boolean',
        'clock_out_at'         => 'datetime',
        'clock_out_latitude'   => 'float',
        'clock_out_longitude'  => 'float',
        'clock_out_is_mocked'  => 'boolean',
        'late_minutes'         => 'integer',
        'early_leave_minutes'  => 'integer',
        'forgotten_clockout_notified_at' => 'datetime',
        'reviewed_at'          => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Alias reviewedBy() dari trait Acknowledgeable, nama lebih pendek
     * dipakai di kolom AttendanceResource.
     */
    public function reviewer(): BelongsTo
    {
        return $this->reviewedBy();
    }

    /**
     * Absen masuk hari ini lebih jauh dari radius toko (Store::
     * attendance_radius_meters, fallback DEFAULT_RADIUS_METERS)? Dipakai
     * admin buat tinjau cepat di AttendanceResource — TIDAK memblokir
     * absen (lihat catatan clockIn()), cuma penanda visual.
     */
    public function isOutsideRadius(): bool
    {
        if ($this->clock_in_distance_meters === null) {
            return false;
        }

        $radius = $this->store?->attendance_radius_meters ?? self::DEFAULT_RADIUS_METERS;

        return $this->clock_in_distance_meters > $radius;
    }

    /**
     * Absen masuk via app (entry_type 'clock') — dipanggil dari endpoint
     * mobile. MEMBLOKIR kalau di luar radius toko (lihat
     * assertWithinRadius()) — staff yang benar-benar dinas luar/device
     * absen mati TIDAK lewat jalur ini sama sekali, itu dicatat admin
     * manual lewat AttendanceResource (lihat catatan migration
     * create_attendances_table), jadi blokir di sini tidak menghalangi
     * kasus pengecualian tersebut.
     *
     * @throws \InvalidArgumentException kalau sudah ada clock-in hari ini, di luar radius toko, atau lokasi terdeteksi palsu.
     */
    public static function clockIn(User $user, Store $store, float $lat, float $lng, ?bool $isMocked = null): self
    {
        $today = Carbon::today();

        return DB::transaction(function () use ($user, $store, $lat, $lng, $isMocked, $today) {
            $existing = self::where('user_id', $user->id)->where('date', $today->toDateString())
                ->lockForUpdate()->first();

            if ($existing && $existing->clock_in_at !== null) {
                throw new \InvalidArgumentException('Sudah absen masuk hari ini.');
            }

            self::assertNotMocked($isMocked);
            self::assertWithinRadius($store, $lat, $lng);

            $now = Carbon::now();
            $distance = $store->distanceMetersTo($lat, $lng);
            $lateMinutes = self::calculateLateMinutes($user, $store, $today, $now);

            $attributes = [
                'store_id'                 => $store->id,
                'entry_type'                => 'clock',
                'clock_in_at'               => $now,
                'clock_in_latitude'         => $lat,
                'clock_in_longitude'        => $lng,
                'clock_in_distance_meters'  => $distance !== null ? (int) round($distance) : null,
                'clock_in_is_mocked'        => $isMocked,
                'late_minutes'              => $lateMinutes,
            ];

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            return self::create($attributes + [
                'user_id' => $user->id,
                'date'    => $today->toDateString(),
            ]);
        });
    }

    /**
     * @throws \InvalidArgumentException kalau $isMocked true (Android
     * melaporkan lokasi ini berasal dari mock/fake-GPS provider, lihat
     * LocationObject.mocked di expo-location). $isMocked null (iOS —
     * platform ini TIDAK melaporkan status mock sama sekali lewat
     * expo-location, keterbatasan yang diketahui) TIDAK diblokir, cuma
     * tidak bisa dinilai — sama filosofinya dengan assertWithinRadius()
     * untuk toko tanpa koordinat.
     */
    protected static function assertNotMocked(?bool $isMocked): void
    {
        if ($isMocked === true) {
            throw new \InvalidArgumentException(
                'Lokasi terdeteksi dari aplikasi GPS palsu (mock location). Matikan mode lokasi palsu di pengaturan HP sebelum absen.'
            );
        }
    }

    /**
     * @throws \InvalidArgumentException kalau jarak ke toko melebihi radius.
     *
     * Kalau toko belum punya koordinat tersimpan (distanceMetersTo()
     * return null), TIDAK diblokir — tidak ada acuan buat menilai jaraknya
     * sama sekali, memblokir semua orang karena admin belum isi koordinat
     * toko akan lebih merugikan daripada membiarkan absen tanpa validasi
     * lokasi untuk sementara.
     */
    protected static function assertWithinRadius(Store $store, float $lat, float $lng): void
    {
        $distance = $store->distanceMetersTo($lat, $lng);
        if ($distance === null) {
            return;
        }

        $radius = $store->attendance_radius_meters ?? self::DEFAULT_RADIUS_METERS;

        if ($distance > $radius) {
            $roundedDistance = (int) round($distance);
            throw new \InvalidArgumentException(
                "Anda berada {$roundedDistance} m dari toko (maksimal {$radius} m). Absen hanya bisa dilakukan dari lokasi toko."
            );
        }
    }

    /**
     * Gap DIPERBAIKI 2026-09-26 (audit Absensi Karyawan) -- SEBELUMNYA
     * jam acuan SELALU jam buka TOKO generik (Store::openingTimeOn()),
     * bukan jam mulai Shift individual staff dari modul Jadwal Kerja.
     * Dua staff toko yang sama tapi shift beda (pagi 08:00 vs siang
     * 14:00) diukur telat terhadap jam buka toko yang SAMA -- staff
     * shift siang yang datang jam 14:00 bisa tercatat "telat" kalau
     * toko buka jam 08:00, padahal tepat waktu menurut jadwalnya
     * sendiri. Padahal AttendancePatternService (modul Jadwal Kerja,
     * dibangun hari yang sama) SUDAH punya logic resolusi Shift yang
     * benar -- sebelumnya cuma dipakai untuk kategorisasi laporan
     * read-only, TIDAK untuk late_minutes yang benar-benar dipakai
     * potongan gaji (Store::late_deduction_amount). resolveShiftFor()
     * di bawah pakai prioritas resolusi yang SAMA dengan
     * AttendancePatternService (override harian > assignment jadwal).
     * Fallback ke jam toko kalau staff belum di-assign jadwal sama
     * sekali (konsisten dengan filosofi "Tidak Ada Jadwal" = bukan
     * error, bukan 0 yang salah kaprah).
     */
    protected static function calculateLateMinutes(User $user, Store $store, Carbon $date, Carbon $clockInTime): int
    {
        $shift = self::resolveShiftFor($user->id, $date);
        $expectedStart = $shift
            ? $date->copy()->setTimeFromTimeString($shift->start_time)
            : ($store->openingTimeOn($date) !== null ? $date->copy()->setTimeFromTimeString($store->openingTimeOn($date)) : null);

        if ($expectedStart === null) {
            return 0;
        }

        $toleranceMinutes = $store->late_tolerance_minutes ?? self::DEFAULT_LATE_TOLERANCE_MINUTES;

        $rawLateMinutes = $expectedStart->diffInMinutes($clockInTime, false);

        return max(0, $rawLateMinutes - $toleranceMinutes);
    }

    /**
     * Resolusi Shift yang berlaku untuk 1 user di 1 tanggal -- SAMA
     * prioritas dengan AttendancePatternService::classify() (override
     * harian ScheduleDayOverride menang atas EmployeeScheduleAssignment
     * biasa), tapi versi SATU-USER (bukan bulk) karena dipanggil sekali
     * per clock-in/out, bukan dalam loop laporan.
     */
    /**
     * Public (bukan protected) supaya bisa dipakai ulang di luar model ini
     * -- lihat App\Console\Commands\NotifyMissingClockins (audit Absensi
     * Karyawan 2026-09-26), yang butuh resolusi Shift yang SAMA persis
     * tanpa duplikasi logic.
     */
    public static function resolveShiftFor(int $userId, Carbon $date): ?\App\Models\Shift
    {
        $override = \App\Models\ScheduleDayOverride::where('user_id', $userId)
            ->whereDate('date', $date)
            ->first();

        if ($override) {
            return $override->shift_id ? \App\Models\Shift::find($override->shift_id) : null;
        }

        $assignment = \App\Models\EmployeeScheduleAssignment::where('user_id', $userId)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->with('workSchedule')
            ->first();

        if (! $assignment?->workSchedule) {
            return null;
        }

        $dayCodes = \App\Models\WorkSchedule::DAYS;
        $shiftId = $assignment->workSchedule->shiftIdFor($dayCodes[$date->dayOfWeekIso - 1]);

        return $shiftId ? \App\Models\Shift::find($shiftId) : null;
    }

    /**
     * @throws \InvalidArgumentException kalau belum absen masuk, sudah absen keluar hari ini, di luar radius toko, atau lokasi terdeteksi palsu.
     */
    public static function clockOut(User $user, float $lat, float $lng, ?bool $isMocked = null): self
    {
        $today = Carbon::today();

        return DB::transaction(function () use ($user, $lat, $lng, $isMocked, $today) {
            $attendance = self::where('user_id', $user->id)->where('date', $today->toDateString())
                ->lockForUpdate()->first();

            if (! $attendance || $attendance->clock_in_at === null) {
                throw new \InvalidArgumentException('Belum absen masuk hari ini.');
            }

            if ($attendance->clock_out_at !== null) {
                throw new \InvalidArgumentException('Sudah absen keluar hari ini.');
            }

            $now = Carbon::now();

            // Bug diperbaiki 2026-09-26 (audit Absensi Karyawan) --
            // SEBELUMNYA validasi radius/mock cuma jalan kalau
            // entry_type === 'clock', dengan asumsi "entri manual tidak
            // mungkin sampai sini lewat app". Asumsi itu SALAH: kalau
            // admin sempat buat entri 'manual' (mis. device toko mati
            // pagi hari, cuma isi clock_in_at) lalu staff BENAR-BENAR
            // pakai app untuk absen-keluar di hari yang sama (device
            // pulih sore), baris ini entry_type-nya sudah terlanjur
            // 'manual' -- validasi radius/mock jadi ke-skip padahal
            // request ini SUNGGUHAN datang dari endpoint mobile
            // (clockOut() cuma dipanggil dari Staff\AttendanceController,
            // tidak pernah dari Filament). Validasi sekarang SELALU
            // jalan di sini, lepas dari entry_type baris yang sudah ada
            // -- yang menentukan relevansinya adalah JALUR PEMANGGILAN
            // (selalu app), bukan bagaimana clock-in-nya tercatat.
            self::assertNotMocked($isMocked);
            self::assertWithinRadius($attendance->store, $lat, $lng);

            $attendance->update([
                'clock_out_at'         => $now,
                'clock_out_latitude'   => $lat,
                'clock_out_longitude'  => $lng,
                'clock_out_is_mocked'  => $isMocked,
                'early_leave_minutes'  => self::calculateEarlyLeaveMinutes($user, $attendance->store, $today, $now),
            ]);

            return $attendance;
        });
    }

    /**
     * Kebalikan calculateLateMinutes() — selisih jam selesai (Shift
     * individual, fallback jam tutup toko -- sama alasan dengan
     * calculateLateMinutes(), audit Absensi Karyawan 2026-09-26) vs jam
     * pulang, sudah dikurangi toleransi yang sama (late_tolerance_minutes
     * dipakai ulang, TIDAK ada pengaturan toleransi terpisah untuk pulang
     * cepat — di luar scope yang disepakati). SENGAJA tidak dipakai
     * potongan Payroll — murni data tinjauan admin.
     */
    protected static function calculateEarlyLeaveMinutes(User $user, Store $store, Carbon $date, Carbon $clockOutTime): int
    {
        $shift = self::resolveShiftFor($user->id, $date);

        if ($shift) {
            $shiftStart = $date->copy()->setTimeFromTimeString($shift->start_time);
            $expectedEnd = $date->copy()->setTimeFromTimeString($shift->end_time);
            // Shift lintas tengah malam (mis. 22:00-06:00) -- jam selesai
            // secara kalender jatuh di hari berikutnya, sama pola dengan
            // AttendancePatternService::buildRow().
            if ($expectedEnd->lt($shiftStart)) {
                $expectedEnd->addDay();
            }
        } else {
            $closingTime = $store->closingTimeOn($date);
            $expectedEnd = $closingTime !== null ? $date->copy()->setTimeFromTimeString($closingTime) : null;
        }

        if ($expectedEnd === null) {
            return 0;
        }

        $toleranceMinutes = $store->late_tolerance_minutes ?? self::DEFAULT_LATE_TOLERANCE_MINUTES;

        $rawEarlyMinutes = $clockOutTime->diffInMinutes($expectedEnd, false);

        return max(0, $rawEarlyMinutes - $toleranceMinutes);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['entry_type', 'clock_in_at', 'clock_out_at', 'late_minutes', 'early_leave_minutes', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('attendance')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Absensi {$this->user?->name} ({$this->date?->format('d M Y')}) dicatat",
                'updated' => "Absensi {$this->user?->name} ({$this->date?->format('d M Y')}) diubah",
                'deleted' => "Absensi {$this->user?->name} ({$this->date?->format('d M Y')}) dihapus",
                default   => "Absensi {$this->user?->name} — {$eventName}",
            });
    }
}
