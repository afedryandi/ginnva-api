<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
{
    use LogsActivity;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di LeaveRequestResource::getEloquentQuery() SENGAJA
    // DIBIARKAN (bukan dihapus) sebagai defense-in-depth; filter dari
    // scope ini no-op/redundan di sana, tapi jadi satu-satunya proteksi
    // untuk query LANGSUNG ke LeaveRequest:: di tempat lain (widget/
    // report/service) yang sebelumnya rawan lupa di-scope.
    use HasStoreScope;

    // Standar minimum UU Ketenagakerjaan RI — 12 hari cuti/tahun, cuma
    // berlaku untuk type 'cuti' (Izin & Sakit TIDAK memotong jatah ini,
    // disepakati eksplisit saat audit modul ini 2026-08-27).
    public const ANNUAL_CUTI_QUOTA_DAYS = 12;

    // Batas atas sanity check — bukan aturan bisnis formal, sekadar jaring
    // pengaman supaya tidak ada pengajuan absurd (mis. 300 hari) lolos
    // tanpa sengaja.
    public const MAX_DURATION_DAYS = 30;

    protected $fillable = [
        'request_number',
        'user_id',
        'store_id',
        'type',
        'start_date',
        'end_date',
        'reason',
        'document',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Jumlah hari kalender inklusif (1 Agt - 3 Agt = 3 hari, bukan 2).
     */
    public function dayCount(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Jatah cuti untuk tahun $year — PROPORSIONAL dari join_date HANYA
     * selama tahun pertama masa kerja (1 hari per bulan kerja penuh sejak
     * join_date, dihitung TERUS-MENERUS dari join_date, BUKAN direset ke
     * 1 Januari tiap tahun). Begitu masa kerja sudah lewat 12 bulan
     * (kapan pun titik itu jatuh), min() di bawah otomatis mentok di
     * ANNUAL_CUTI_QUOTA_DAYS — jadi karyawan lama dapat kuota PENUH tiap
     * tahun, bukan terus dihitung prorata seolah-olah baru masuk.
     *
     * BUG SEBELUMNYA (ketauan 2026-08-27 dari kasus nyata: karyawan
     * join_date 26 Agt 2025, per 27 Agt 2026 harusnya sudah lewat 1
     * tahun penuh = kuota 12): versi lama pakai periodStart = max(
     * join_date, 1 Jan tahun ini) — jadi SETIAP tahun baru, hitungan
     * ke-reset dari 0 lagi, karyawan yang sudah bertahun-tahun kerja pun
     * kuotanya tetap keliatan prorata (baru 7/12 di Agustus), padahal
     * seharusnya sudah dapat 12 penuh sejak lama.
     *
     * Karyawan tanpa join_date tercatat dapat 0 (tidak ada dasar hitung
     * proporsi sama sekali — lebih aman daripada menebak).
     */
    public static function annualQuotaFor(User $user, int $year): int
    {
        if (! $user->join_date) {
            return 0;
        }

        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $periodEnd = Carbon::now()->lessThan($yearEnd) ? Carbon::now() : $yearEnd;

        if ($user->join_date->greaterThan($periodEnd)) {
            return 0;
        }

        $monthsWorked = $user->join_date->diffInMonths($periodEnd);

        return min(self::ANNUAL_CUTI_QUOTA_DAYS, $monthsWorked);
    }

    /**
     * Total hari 'cuti' yang SUDAH disetujui dalam tahun $year — cuma
     * status 'approved' yang dihitung terpakai (pending/rejected/cancelled
     * tidak mengurangi jatah). $excludeId dipakai saat mengedit pengajuan
     * yang sudah ada, supaya baris itu sendiri tidak dihitung dobel.
     */
    public static function usedCutiDaysFor(User $user, int $year, ?int $excludeId = null): int
    {
        return self::where('user_id', $user->id)
            ->where('type', 'cuti')
            ->where('status', 'approved')
            ->whereYear('start_date', $year)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->sum(fn (self $r) => $r->dayCount());
    }

    public static function remainingCutiFor(User $user, int $year): int
    {
        return max(0, self::annualQuotaFor($user, $year) - self::usedCutiDaysFor($user, $year));
    }

    /**
     * Ada pengajuan lain (pending/approved) milik karyawan yang sama dan
     * rentang tanggalnya tumpang tindih? $excludeId dipakai saat mengedit
     * baris yang sudah ada.
     */
    public static function hasOverlap(User $user, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        return self::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->exists();
    }

    /**
     * Bug diperbaiki 2026-09-27 (audit ulang Izin & Cuti) -- SEBELUMNYA
     * hasOverlap()/remainingCutiFor() dicek SEBELUM create() (di
     * controller mobile MAUPUN ->rule() closure form Filament) tanpa
     * lock, celah TOCTOU: 2 submit nyaris bersamaan untuk user yang sama
     * (2 device, atau race form Filament) bisa dua-duanya lolos cek
     * overlap/kuota sebelum salah satu commit. Tidak ada baris "saldo
     * cuti" tersendiri untuk dikunci, jadi baris User pemohon ITU
     * SENDIRI dipakai sebagai titik lock (lockForUpdate()) supaya 2
     * submit untuk user yang sama diserialisasi -- submit kedua akan
     * menunggu submit pertama commit, baru cek ulang overlap/kuota
     * dengan data yang SUDAH TERBARU (bukan snapshot sebelum lock).
     *
     * @throws RuntimeException kalau overlap atau kuota cuti tidak
     *         cukup -- dicek ULANG di dalam lock, pesan sama persis
     *         dengan validasi yang sudah ada di controller/form supaya
     *         staff tidak melihat pesan baru yang membingungkan.
     */
    public static function createLocked(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('id', $data['user_id'])->lockForUpdate()->first();

            if (! $user) {
                throw new RuntimeException('Karyawan tidak ditemukan.');
            }

            if (self::hasOverlap($user, $data['start_date'], $data['end_date'], $data['id'] ?? null)) {
                throw new RuntimeException('Karyawan ini sudah punya pengajuan izin/cuti lain yang tanggalnya tumpang tindih.');
            }

            if (($data['type'] ?? null) === 'cuti') {
                $dayCount = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;
                $remaining = self::remainingCutiFor($user, Carbon::parse($data['start_date'])->year);

                if ($dayCount > $remaining) {
                    throw new RuntimeException("Sisa jatah cuti tahun ini tinggal {$remaining} hari, tidak cukup untuk {$dayCount} hari yang diajukan.");
                }
            }

            return self::create($data);
        });
    }

    /**
     * Bug diperbaiki 2026-09-27 (audit ulang Izin & Cuti) -- pola SAMA
     * PERSIS dengan race condition yang baru diperbaiki di
     * AttendanceCorrectionService (approve()/reject()): SEBELUMNYA action
     * "Setujui"/"Tolak" di LeaveRequestResource langsung update() tanpa
     * lock/recheck, ->visible() cuma proteksi UI (status==='pending'
     * dicek SEKALI saat render tombol, bukan saat action jalan). 2 admin
     * approve/reject baris yang sama nyaris bersamaan bisa dua-duanya
     * lolos, staff bisa dapat 2 push notifikasi kontradiktif.
     *
     * @throws RuntimeException kalau baris ini sudah diputuskan sebelumnya.
     */
    public static function approveLocked(int $id, int $approvedBy): self
    {
        return DB::transaction(function () use ($id, $approvedBy) {
            $locked = self::whereKey($id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'pending') {
                throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
            }

            $locked->update([
                'status'      => 'approved',
                'reviewed_by' => $approvedBy,
                'reviewed_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * @throws RuntimeException kalau baris ini sudah diputuskan sebelumnya.
     */
    public static function rejectLocked(int $id, int $rejectedBy, string $reviewNote): self
    {
        return DB::transaction(function () use ($id, $rejectedBy, $reviewNote) {
            $locked = self::whereKey($id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'pending') {
                throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
            }

            $locked->update([
                'status'      => 'rejected',
                'reviewed_by' => $rejectedBy,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ]);

            return $locked;
        });
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveRequest $request) {
            if (empty($request->request_number)) {
                $request->request_number = static::generateRequestNumber($request->type);
            }
        });
    }

    private const REQUEST_NUMBER_PREFIXES = [
        'izin' => 'IZ',
        'sakit' => 'SK',
        'cuti'  => 'CT',
    ];

    protected static function generateRequestNumber(string $type): string
    {
        $prefix = self::REQUEST_NUMBER_PREFIXES[$type] ?? 'IZ';

        do {
            $candidate = "{$prefix}-" . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('request_number', $candidate)->exists());

        return $candidate;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'type', 'start_date', 'end_date', 'review_note', 'reviewed_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('leave_request')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Izin #{$this->request_number} diajukan",
                'updated' => "Izin #{$this->request_number} diubah",
                'deleted' => "Izin #{$this->request_number} dihapus",
                default   => "Izin #{$this->request_number} — {$eventName}",
            });
    }
}
