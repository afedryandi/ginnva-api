<?php

namespace App\Models;

use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payroll extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'store_id',
        'period_month',
        'base_salary',
        'working_days_in_month',
        'prorated_base_salary',
        'total_late_minutes',
        'late_violation_days',
        'alpha_days',
        'alpha_deduction',
        'total_commission',
        'has_unrated_commission',
        'deduction_per_violation',
        'late_blocks',
        'late_block_minutes',
        'deduction_per_block',
        'late_deduction',
        'scheduled_hours_in_month',
        'hourly_rate',
        'alpha_hours',
        'alpha_fine',
        'total_additions',
        'total_item_deductions',
        'total_deduction',
        'net_pay',
        'status',
        'payment_requested_by',
        'payment_requested_at',
        'paid_by',
        'paid_at',
        'journal_entry_id',
    ];

    protected $casts = [
        'period_month'              => 'date',
        'base_salary'                => 'decimal:2',
        'working_days_in_month'      => 'integer',
        'prorated_base_salary'       => 'decimal:2',
        'total_late_minutes'         => 'integer',
        'late_violation_days'        => 'integer',
        'alpha_days'                 => 'integer',
        'alpha_deduction'            => 'decimal:2',
        'total_commission'           => 'decimal:2',
        'has_unrated_commission'     => 'boolean',
        'deduction_per_violation'    => 'decimal:2',
        'total_deduction'            => 'decimal:2',
        'late_blocks'                => 'integer',
        'late_block_minutes'         => 'integer',
        'deduction_per_block'        => 'decimal:2',
        'late_deduction'             => 'decimal:2',
        'scheduled_hours_in_month'   => 'decimal:2',
        'hourly_rate'                => 'decimal:2',
        'alpha_hours'                => 'decimal:2',
        'alpha_fine'                 => 'decimal:2',
        'total_additions'            => 'decimal:2',
        'total_item_deductions'      => 'decimal:2',
        'net_pay'                    => 'decimal:2',
        'payment_requested_at'       => 'datetime',
        'paid_at'                    => 'datetime',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'pending_approval' => 'Menunggu Persetujuan Direksi',
        'paid' => 'Sudah Dibayar',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** Komponen gaji (tunjangan, THR, BPJS, PPh 21, kasbon, dst.) yang dicatat di slip ini (Karyawan Fase 1). */
    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /**
     * Hitung ulang jumlah tambahan, potongan komponen, dan gaji bersih dari komponen yang tercatat. Dipanggil setiap komponen
     * berubah. total_deduction (potongan telat/Alpha dari absensi) tidak disentuh.
     */
    public function recalculateTotals(): void
    {
        [$additions, $deductions] = self::itemTotals($this->items()->with('category:id,type')->get());

        $this->forceFill([
            'total_additions' => $additions,
            'total_item_deductions' => $deductions,
            'net_pay' => self::netPayFor((float) $this->prorated_base_salary, (float) $this->total_commission, $additions, (float) $this->total_deduction, $deductions),
        ])->save();
    }

    /** @return array{0: float, 1: float} [jumlah tambahan, jumlah potongan] dari kumpulan komponen */
    public static function itemTotals(\Illuminate\Support\Collection $items): array
    {
        $additions = (float) $items->filter(fn ($i) => $i->category?->isEarning())->sum('amount');
        $deductions = (float) $items->filter(fn ($i) => $i->category && ! $i->category->isEarning())->sum('amount');

        return [round($additions, 2), round($deductions, 2)];
    }

    /** Gaji bersih: gaji berjalan + komisi + tambahan - potongan absensi - potongan komponen; tidak pernah negatif. */
    public static function netPayFor(float $proratedBase, float $commission, float $additions, float $attendanceDeduction, float $itemDeductions): float
    {
        return max(0, round($proratedBase + $commission + $additions - $attendanceDeduction - $itemDeductions, 2));
    }

    /**
     * Subquery "ada absensi di bulan slip ini yang berubah setelah slip dihitung", dipasang di getEloquentQuery() sebagai kolom
     * stale_attendance supaya daftar tidak menjalankan satu query per baris.
     */
    public static function staleAttendanceSubquery(): \Illuminate\Database\Eloquent\Builder
    {
        return Attendance::withoutGlobalScopes()
            ->selectRaw('1')
            ->whereColumn('attendances.user_id', 'payrolls.user_id')
            ->whereRaw('attendances.date >= payrolls.period_month')
            ->whereRaw('attendances.date <= LAST_DAY(payrolls.period_month)')
            ->whereColumn('attendances.updated_at', '>', 'payrolls.updated_at')
            ->limit(1);
    }

    /** Subquery "aturan potongan yang berlaku untuk bulan slip ini diubah/dibuat setelah slip dihitung" (kolom stale_rule). */
    public static function staleRuleSubquery(): \Illuminate\Database\Eloquent\Builder
    {
        return AttendanceDeductionRule::query()
            ->selectRaw('1')
            ->whereRaw('attendance_deduction_rules.effective_from = (select max(r2.effective_from) from attendance_deduction_rules r2 where r2.effective_from <= payrolls.period_month)')
            ->whereColumn('attendance_deduction_rules.updated_at', '>', 'payrolls.updated_at')
            ->limit(1);
    }

    /**
     * Slip draf yang angkanya mungkin sudah tertinggal: absensi bulan itu berubah (izin, koreksi, Alpha otomatis) atau gaji pokok
     * karyawan diubah sesudah slip dihitung. Hanya draf; slip yang sudah diajukan atau dibayar terkunci dari perhitungan ulang.
     */
    public function isStale(): bool
    {
        if ($this->status !== 'draft') {
            return false;
        }

        if ($this->user && (float) $this->user->base_salary !== (float) $this->base_salary) {
            return true;
        }

        if (array_key_exists('stale_rule', $this->attributes) && $this->attributes['stale_rule']) {
            return true;
        }

        if (array_key_exists('stale_attendance', $this->attributes)) {
            return (bool) $this->attributes['stale_attendance'];
        }

        $rule = AttendanceDeductionRule::effectiveFor($this->period_month->copy()->startOfMonth());
        if ($rule && $rule->updated_at && $rule->updated_at->gt($this->updated_at)) {
            return true;
        }

        return Attendance::withoutGlobalScopes()
            ->where('user_id', $this->user_id)
            ->whereBetween('date', [$this->period_month->copy()->startOfMonth()->toDateString(), $this->period_month->copy()->endOfMonth()->toDateString()])
            ->where('updated_at', '>', $this->updated_at)
            ->exists();
    }

    /**
     * Bagian potongan yang TIDAK terpotong karena gaji bersih tidak boleh negatif (mis. kasbon lebih besar dari gaji bulan itu).
     * Dihitung dari kolom tersimpan, jadi berlaku juga untuk baris lama.
     */
    public function getUncollectedDeductionAttribute(): float
    {
        $raw = (float) $this->prorated_base_salary + (float) $this->total_commission + (float) $this->total_additions
            - (float) $this->total_deduction - (float) $this->total_item_deductions;

        return $raw < 0 ? round(-$raw, 2) : 0.0;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function paymentRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_requested_by');
    }

    /**
     * Jurnal Umum yang otomatis dibuat saat payroll ini ditandai
     * "Dibayar" — lihat PayrollPostingService. Null untuk baris 'draft'.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Hitung/generate payroll 1 karyawan untuk 1 bulan dari data Attendance
     * — TIDAK pernah diketik manual, supaya potongan telat/alpha selalu
     * konsisten dengan riwayat absensi asli.
     *
     * Cara hitung potongan (aturan baru, keputusan pemilik 2026-10-17; nominal dari AttendanceDeductionRule yang diisi staf di panel):
     * - Telat: TOTAL menit telat sebulan dibagi ukuran blok (bawaan 15 menit), hanya blok PENUH yang dipotong, masing-masing sebesar
     *   potongan per blok. Tidak ada toleransi harian maupun menit bebas: 14 menit = 0 blok, 40 menit = 2 blok.
     * - Alpha ("no work no pay"): tarif per jam = gaji pokok / total jam kerja terjadwal bulan itu (jam buka-tutup toko dikurangi istirahat);
     *   potongan = tarif per jam x jam kerja hari Alpha itu, ditambah denda tetap per hari Alpha. Toko tanpa jam buka/tutup terisi memakai
     *   tarif harian (gaji / hari toko buka).
     * - Karyawan yang join_date-nya JATUH DI BULAN YANG SAMA hanya dihitung gaji pokoknya proporsional dari jam kerja sejak join_date.
     * - Izin/Sakit/Cuti yang SUDAH DISETUJUI (entry_type 'leave') TIDAK memotong apa pun (tetap dibayar penuh). Pulang cepat hanya dicatat.
     * - Tanpa aturan potongan yang berlaku, generate gagal dengan pesan jelas (bukan potongan Rp 0 diam-diam).
     *
     * Komisi teknisi (integrasi 2026-09-23) -- kalau $user punya baris
     * Technician (Technician::user_id), komisi dari SELURUH booking yang
     * ditugaskan ke dia (installers()) dengan journal entry di periode
     * ini DITAMBAHKAN ke net_pay. Aturan hitungnya SAMA PERSIS dengan
     * Laporan Komisi Teknisi (Technician::commissionForBooking(), sumber
     * booking whereHas('journalEntry') + transaction_amount > 0) supaya
     * angkanya konsisten di mana pun ditampilkan. Booking yang tarif
     * komisinya belum lengkap diatur TIDAK ikut disumkan (ditandai
     * has_unrated_commission, BUKAN dihitung Rp 0 yang menyesatkan).
     * Karyawan yang bukan Technician (mis. admin/kasir) -> total_commission
     * tetap 0, tidak ada query tambahan.
     *
     * Baris yang sudah 'paid' TIDAK bisa di-generate ulang (harus dibatalkan
     * status paid-nya dulu secara manual kalau memang perlu dikoreksi) —
     * supaya angka yang sudah ditandai dibayar tidak diam-diam berubah.
     *
     * @throws \InvalidArgumentException kalau payroll bulan ini sudah 'paid', atau karyawan belum terhubung ke toko mana pun.
     */
    public static function generateForMonth(User $user, Carbon $month): self
    {
        // store_id di tabel payrolls itu kolom WAJIB (foreignId biasa,
        // bukan nullable) — sebelum ada guard ini, karyawan tanpa
        // store_id (mis. super_admin yang kebetulan punya base_salary
        // terisi) bikin generate gagal dengan error SQL constraint mentah
        // yang membingungkan, bukan pesan yang jelas. Lihat audit modul
        // Penggajian 2026-08-27.
        if (! $user->store_id) {
            throw new \InvalidArgumentException(
                "{$user->name} belum terhubung ke toko mana pun, tidak bisa digenerate payroll-nya."
            );
        }

        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();

        return DB::transaction(function () use ($user, $periodStart, $periodEnd) {
            $existing = self::where('user_id', $user->id)
                ->whereDate('period_month', $periodStart->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'paid') {
                throw new \InvalidArgumentException(
                    "Payroll {$user->name} bulan {$periodStart->translatedFormat('F Y')} sudah ditandai dibayar, tidak bisa digenerate ulang."
                );
            }

            // Approval berjenjang (audit 2026-09-27): baris yang sedang
            // 'pending_approval' juga TIDAK boleh digenerate ulang diam-diam
            // -- kalau boleh, angka yang sedang ditinjau direksi bisa berubah
            // tanpa sepengetahuannya sebelum dia sempat approve/tolak.
            if ($existing && $existing->status === 'pending_approval') {
                throw new \InvalidArgumentException(
                    "Payroll {$user->name} bulan {$periodStart->translatedFormat('F Y')} sedang menunggu persetujuan direksi, tidak bisa digenerate ulang."
                );
            }

            $store = $user->store;

            // Aturan potongan absensi (nominal diisi staf di panel, bukan di kode). Tanpa aturan, payroll TIDAK digenerate: lebih baik
            // berhenti dengan pesan jelas daripada diam-diam menghasilkan potongan Rp 0 atau potongan memakai aturan lama yang sudah diganti.
            $rule = AttendanceDeductionRule::effectiveFor($periodStart);

            if (! $rule) {
                throw new \InvalidArgumentException(
                    'Aturan Potongan Absensi belum diisi atau belum berlaku untuk bulan ini. Isi dulu di menu Karyawan > Aturan Potongan Absensi.'
                );
            }

            // Payroll dihitung PER KARYAWAN (user_id eksplisit) dan boleh dijalankan akun yang
            // bukan full-access & tanpa toko (spv_finance): StoreScope akun pelaksana tidak boleh ikut
            // menyaring -- sebelumnya query jadi "store_id = NULL" dan potongan telat/alpha serta
            // komisi teknisi diam-diam terhitung 0.
            $attendances = Attendance::withoutGlobalScope(StoreScope::class)
                ->where('user_id', $user->id)
                ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->get(['date', 'late_minutes', 'entry_type']);

            // Telat: TOTAL menit telat sebulan (dicatat apa adanya, tanpa toleransi harian) dibagi ukuran blok, hanya blok PENUH yang dipotong.
            $totalLateMinutes = (int) $attendances->sum('late_minutes');
            $lateBlocks = $rule->lateBlocksFor($totalLateMinutes);
            $deductionPerBlock = (float) $rule->late_deduction_per_block;
            $lateDeduction = round($lateBlocks * $deductionPerBlock, 2);

            // Hari kerja dan jam kerja terjadwal bulan itu dari jam operasional toko (jam istirahat dikurangi). Toko tutup
            // (libur rutin atau tanggal diblokir) tidak dihitung. Toko tanpa jam buka/tutup terisi jatuh ke tarif harian.
            $workingDaysInMonth = 0;
            $monthMinutes = 0;
            $minutesByDate = [];
            for ($day = $periodStart->copy(); $day->lte($periodEnd); $day->addDay()) {
                if ($store?->isClosedOn($day)) {
                    continue;
                }

                $workingDaysInMonth++;
                $minutes = $store ? $store->scheduledMinutesOn($day) : 0;
                $minutesByDate[$day->toDateString()] = $minutes;
                $monthMinutes += $minutes;
            }

            $hoursBased = $monthMinutes > 0;
            $baseSalary = (float) ($user->base_salary ?? 0);
            $hourlyRate = $hoursBased ? $baseSalary / ($monthMinutes / 60) : null;
            $dailyRate = $workingDaysInMonth > 0 ? $baseSalary / $workingDaysInMonth : 0;

            // Proporsi HANYA kalau join_date jatuh DI DALAM bulan payroll ini -- join_date di bulan-bulan sebelumnya (atau kosong)
            // berarti karyawan sudah kerja penuh sebulan ini.
            // Hari kerja terakhir (last_work_date) di dalam bulan ini bekerja sama seperti join_date: gaji pokok dihitung hanya untuk hari
            // sejak mulai (bila join di bulan ini) sampai hari terakhir (bila berhenti di bulan ini).
            $joinsThisMonth = $user->join_date && $user->join_date->between($periodStart, $periodEnd);
            $leavesThisMonth = $user->last_work_date && $user->last_work_date->between($periodStart, $periodEnd);

            if ($joinsThisMonth || $leavesThisMonth) {
                $from = $joinsThisMonth ? $user->join_date->toDateString() : $periodStart->toDateString();
                $to = $leavesThisMonth ? $user->last_work_date->toDateString() : $periodEnd->toDateString();

                if ($hoursBased) {
                    $employedMinutes = 0;
                    foreach ($minutesByDate as $date => $minutes) {
                        if ($date >= $from && $date <= $to) {
                            $employedMinutes += $minutes;
                        }
                    }
                    $proratedBaseSalary = round(($hourlyRate / 60) * $employedMinutes, 2);
                } else {
                    $employedWorkingDays = 0;
                    for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
                        if (! $store?->isClosedOn($day)) {
                            $employedWorkingDays++;
                        }
                    }
                    $proratedBaseSalary = round($dailyRate * $employedWorkingDays, 2);
                }
            } else {
                $proratedBaseSalary = $baseSalary;
            }

            // Alpha: potongan = tarif per jam x jam kerja hari itu (Sabtu pendek dipotong lebih kecil dari hari penuh), ditambah denda
            // tetap per hari Alpha (nominal dari aturan). Tanpa jam kerja terisi, tarif harian seperti sebelumnya.
            $alphaRows = $attendances->where('entry_type', 'alpha')
                ->filter(fn ($row) => ! $user->last_work_date || $row->date->lte($user->last_work_date));
            $alphaDaysCount = $alphaRows->count();
            $alphaHours = 0.0;
            $alphaWage = 0.0;

            foreach ($alphaRows as $row) {
                if ($hoursBased) {
                    $hours = ($minutesByDate[$row->date->toDateString()] ?? 0) / 60;
                    $alphaHours += $hours;
                    $alphaWage += $hours * $hourlyRate;
                } else {
                    $alphaWage += $dailyRate;
                }
            }

            $alphaFine = round($alphaDaysCount * (float) $rule->alpha_fine_per_day, 2);
            $alphaDeduction = round($alphaWage, 2) + $alphaFine;
            $totalDeduction = $alphaDeduction + $lateDeduction;

            $totalCommission = 0.0;
            $hasUnratedCommission = false;

            $technician = Technician::withoutGlobalScope(StoreScope::class)->where('user_id', $user->id)->first();
            if ($technician) {
                $jobs = Booking::withoutGlobalScope(StoreScope::class)
                    ->whereHas('installers', fn ($q) => $q->where('users.id', $user->id))
                    ->soldBetween($periodStart->toDateString(), $periodEnd->toDateString())
                    ->where('transaction_amount', '>', 0)
                    ->get(['id', 'transaction_amount', 'product_ppf', 'product_kaca_film', 'product_detailing', 'product_premium_wash']);

                foreach ($jobs as $job) {
                    $commission = $technician->commissionForBooking($job);
                    if ($commission === null) {
                        $hasUnratedCommission = true;

                        continue;
                    }

                    $totalCommission += $commission;
                }
            }

            // Potongan tidak boleh bikin gaji bersih negatif — sekadar
            // jaring pengaman tampilan, bukan validasi bisnis; kalau
            // sampai kejadian, itu tetap perlu ditinjau admin lewat kolom
            // total_deduction vs prorated_base_salary yang keduanya tetap
            // tersimpan apa adanya.
            // Komponen gaji yang sudah dicatat di draft ini ikut dihitung; generate ulang tidak menghapusnya.
            [$totalAdditions, $totalItemDeductions] = $existing
                ? self::itemTotals($existing->items()->with('category:id,type')->get())
                : [0.0, 0.0];

            $netPay = self::netPayFor($proratedBaseSalary, $totalCommission, $totalAdditions, $totalDeduction, $totalItemDeductions);

            $attributes = [
                'store_id'                 => $user->store_id,
                'base_salary'              => $baseSalary,
                'working_days_in_month'    => $workingDaysInMonth,
                'prorated_base_salary'     => $proratedBaseSalary,
                'total_late_minutes'       => $totalLateMinutes,
                // late_violation_days dan deduction_per_violation dipertahankan untuk pembaca lama (aplikasi/laporan lama): sejak
                // 2026-10-28 isinya jumlah blok telat dan potongan per blok.
                'late_violation_days'      => $lateBlocks,
                'alpha_days'               => $alphaDaysCount,
                'alpha_deduction'          => $alphaDeduction,
                'total_commission'         => $totalCommission,
                'has_unrated_commission'   => $hasUnratedCommission,
                'deduction_per_violation'  => $deductionPerBlock,
                'late_blocks'              => $lateBlocks,
                'late_block_minutes'       => (int) $rule->late_block_minutes,
                'deduction_per_block'      => $deductionPerBlock,
                'late_deduction'           => $lateDeduction,
                'scheduled_hours_in_month' => $hoursBased ? round($monthMinutes / 60, 2) : null,
                'hourly_rate'              => $hoursBased ? round($hourlyRate, 2) : null,
                'alpha_hours'              => $hoursBased ? round($alphaHours, 2) : null,
                'alpha_fine'               => $alphaFine,
                'total_additions'          => $totalAdditions,
                'total_item_deductions'    => $totalItemDeductions,
                'total_deduction'          => $totalDeduction,
                'net_pay'                  => $netPay,
            ];

            if ($existing) {
                $existing->update($attributes);
                // Hitung ulang tanpa perubahan angka tetap menandai slip "baru dihitung" (dasar isStale()).
                $existing->touch();

                return $existing;
            }

            return self::create($attributes + [
                'user_id'      => $user->id,
                'period_month' => $periodStart->toDateString(),
                'status'       => 'draft',
            ]);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'net_pay', 'total_deduction', 'alpha_days', 'alpha_deduction', 'total_commission', 'payment_requested_by', 'paid_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payroll')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Payroll {$this->user?->name} ({$this->period_month?->format('M Y')}) digenerate",
                'updated' => "Payroll {$this->user?->name} ({$this->period_month?->format('M Y')}) diubah",
                'deleted' => "Payroll {$this->user?->name} ({$this->period_month?->format('M Y')}) dihapus",
                default   => "Payroll {$this->user?->name} — {$eventName}",
            });
    }
}
