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
        'leave_type_id',
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

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * Pengajuan ini memotong jatah cuti tahunan? Pengajuan lama tanpa jenis dihitung dari kategorinya (cuti = memotong).
     */
    public function deductsAnnualQuota(): bool
    {
        return $this->leaveType ? $this->leaveType->deducts_annual_quota : $this->type === 'cuti';
    }

    /**
     * Beri tahu penyetuju (akses penuh yang aktif, kecuali karyawan pengaju sendiri) lewat lonceng panel bahwa ada pengajuan baru.
     */
    public function notifyApprovers(): void
    {
        User::where('is_active', true)
            ->where('id', '!=', $this->user_id)
            ->get()
            ->filter(fn (User $u) => $u->canDecideLeaveFor($this))
            ->each(fn (User $u) => \Filament\Notifications\Notification::make()
                ->title('Pengajuan izin/cuti baru')
                ->body("{$this->user?->name} mengajukan {$this->request_number} ({$this->start_date->format('d M')}–{$this->end_date->format('d M Y')}).")
                ->warning()
                ->sendToDatabase($u));
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
            // Hanya jenis yang memotong jatah tahunan (Cuti Haid, Menikah, Melahirkan, dst. tidak).
            ->where(fn ($q) => $q->whereNull('leave_type_id')->orWhereHas('leaveType', fn ($t) => $t->where('deducts_annual_quota', true)))
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

            // Jenis menentukan kategori; pengajuan lama yang hanya menyebut kategori dipetakan ke jenis dasarnya.
            $leaveType = ! empty($data['leave_type_id'])
                ? LeaveType::find($data['leave_type_id'])
                : (isset($data['type']) ? LeaveType::forCategory($data['type']) : null);

            if (! empty($data['leave_type_id']) && (! $leaveType || ! $leaveType->is_active)) {
                throw new RuntimeException('Jenis izin/cuti tidak valid atau sudah dinonaktifkan.');
            }

            if ($leaveType) {
                $data['leave_type_id'] = $leaveType->id;
                $data['type'] = $leaveType->category;
            }

            $dayCount = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;

            if ($leaveType?->max_days && $dayCount > $leaveType->max_days) {
                throw new RuntimeException("{$leaveType->name} maksimal {$leaveType->max_days} hari, tidak bisa mengajukan {$dayCount} hari.");
            }

            if ($leaveType?->requiresDocumentFor($dayCount) && empty($data['document'])) {
                throw new RuntimeException("{$leaveType->name} untuk {$dayCount} hari wajib melampirkan dokumen pendukung (mis. surat dokter).");
            }

            if (self::hasOverlap($user, $data['start_date'], $data['end_date'], $data['id'] ?? null)) {
                throw new RuntimeException('Karyawan ini sudah punya pengajuan izin/cuti lain yang tanggalnya tumpang tindih.');
            }

            if ($leaveType ? $leaveType->deducts_annual_quota : (($data['type'] ?? null) === 'cuti')) {
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

            self::assertMayDecide($locked, $approvedBy);

            // Jatah dicek ulang saat disetujui: saat diajukan, pengajuan lain yang masih menunggu belum dihitung, jadi dua pengajuan bisa
            // sama-sama lolos dan melewati jatah bila keduanya disetujui. Baris karyawan dikunci agar dua persetujuan tidak saling menyalip.
            if ($locked->deductsAnnualQuota()) {
                $employee = User::whereKey($locked->user_id)->lockForUpdate()->first();
                $year = $locked->start_date->year;
                $remaining = max(0, self::annualQuotaFor($employee, $year) - self::usedCutiDaysFor($employee, $year, $locked->id));

                if ($locked->dayCount() > $remaining) {
                    throw new RuntimeException(
                        "Jatah cuti {$employee->name} tahun {$year} tinggal {$remaining} hari, tidak cukup untuk {$locked->dayCount()} hari. Tolak pengajuan ini atau minta karyawan mengajukan ulang."
                    );
                }
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
     * Cabut persetujuan (salah setuju, atau rencana berubah). Statusnya menjadi 'cancelled' dengan reviewed_by terisi, itulah yang
     * membedakannya dari pembatalan oleh karyawan sendiri (isRevoked()). Jatah cuti otomatis kembali karena hanya yang 'approved' dihitung.
     * Absensi berjenis Izin/Cuti (tanpa jam) di rentangnya dikembalikan: tanggal yang sudah lewat menjadi Alpha, hari ini dan mendatang
     * dihapus (job harian membuatnya sendiri nanti).
     *
     * @throws RuntimeException kalau pengajuan ini tidak sedang berstatus disetujui.
     */
    public static function revokeLocked(int $id, int $revokedBy, string $note): self
    {
        return DB::transaction(function () use ($id, $revokedBy, $note) {
            $locked = self::whereKey($id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'approved') {
                throw new RuntimeException('Cuma pengajuan yang sedang berstatus disetujui yang bisa dicabut.');
            }

            $locked->update([
                'status'      => 'cancelled',
                'reviewed_by' => $revokedBy,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $rows = Attendance::where('user_id', $locked->user_id)
                ->whereBetween('date', [$locked->start_date->toDateString(), $locked->end_date->toDateString()])
                ->where('entry_type', 'leave')
                ->whereNull('clock_in_at');

            (clone $rows)->whereDate('date', '<', today())->update(['entry_type' => 'alpha']);
            (clone $rows)->whereDate('date', '>=', today())->delete();

            return $locked;
        });
    }

    /** Penegakan siapa yang boleh memutuskan, di dalam kunci (bukan hanya tombol yang disembunyikan). */
    private static function assertMayDecide(self $request, int $deciderId): void
    {
        $decider = User::find($deciderId);

        if (! $decider || ! $decider->canDecideLeaveFor($request)) {
            throw new RuntimeException('Anda tidak berhak memutuskan pengajuan ini (hanya akses penuh, atau Store Manager/Spv Finance toko yang sama untuk pengajuan orang lain).');
        }
    }

    /** Dicabut penyetuju (bukan dibatalkan karyawan sendiri). */
    public function isRevoked(): bool
    {
        return $this->status === 'cancelled' && $this->reviewed_by !== null;
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

            self::assertMayDecide($locked, $rejectedBy);

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
            // Pembuat yang hanya menyebut kategori (aplikasi lama, seeder, impor) otomatis dikaitkan ke jenis dasarnya.
            if (empty($request->leave_type_id) && ! empty($request->type)) {
                $request->leave_type_id = LeaveType::forCategory($request->type)?->id;
            }

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
