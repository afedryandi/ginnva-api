<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
{
    use LogsActivity;

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