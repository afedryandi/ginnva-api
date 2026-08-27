<?php

namespace App\Models;

use App\Models\Concerns\Acknowledgeable;
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
            $lateMinutes = self::calculateLateMinutes($store, $today, $now);

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
     * Selisih menit antara $clockInTime dan jam buka toko di $date, sudah
     * dikurangi toleransi Store::late_tolerance_minutes (fallback
     * DEFAULT_LATE_TOLERANCE_MINUTES) — 0 kalau tidak telat atau toko
     * tidak punya jadwal untuk hari itu (mis. hari libur tapi tetap masuk
     * lembur, tidak relevan dihitung telat).
     */
    protected static function calculateLateMinutes(Store $store, Carbon $date, Carbon $clockInTime): int
    {
        $openingTime = $store->openingTimeOn($date);
        if ($openingTime === null) {
            return 0;
        }

        $expectedStart = $date->copy()->setTimeFromTimeString($openingTime);
        $toleranceMinutes = $store->late_tolerance_minutes ?? self::DEFAULT_LATE_TOLERANCE_MINUTES;

        $rawLateMinutes = $expectedStart->diffInMinutes($clockInTime, false);

        return max(0, $rawLateMinutes - $toleranceMinutes);
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

            // Entri 'manual'/'field_duty'/'alpha'/'leave' TIDAK mungkin
            // sampai sini lewat app (dibuat admin/sistem langsung, bukan
            // dari clock-in app), tapi jaga-jaga tetap dicek entry_type
            // === 'clock' supaya staff yang hari ini tercatat "Dinas Luar"
            // dsb tidak coba absen-keluar-app lalu ketolak gara-gara
            // radius/mock-check yang memang tidak relevan buat entri itu.
            if ($attendance->entry_type === 'clock') {
                self::assertNotMocked($isMocked);
                self::assertWithinRadius($attendance->store, $lat, $lng);
            }

            $attendance->update([
                'clock_out_at'         => $now,
                'clock_out_latitude'   => $lat,
                'clock_out_longitude'  => $lng,
                'clock_out_is_mocked'  => $isMocked,
                'early_leave_minutes'  => $attendance->entry_type === 'clock'
                    ? self::calculateEarlyLeaveMinutes($attendance->store, $today, $now)
                    : 0,
            ]);

            return $attendance;
        });
    }

    /**
     * Kebalikan calculateLateMinutes() — selisih jam tutup toko vs jam
     * pulang, sudah dikurangi toleransi yang sama (late_tolerance_minutes
     * dipakai ulang, TIDAK ada pengaturan toleransi terpisah untuk pulang
     * cepat — di luar scope yang disepakati). SENGAJA tidak dipakai
     * potongan Payroll — murni data tinjauan admin.
     */
    protected static function calculateEarlyLeaveMinutes(Store $store, Carbon $date, Carbon $clockOutTime): int
    {
        $closingTime = $store->closingTimeOn($date);
        if ($closingTime === null) {
            return 0;
        }

        $expectedEnd = $date->copy()->setTimeFromTimeString($closingTime);
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