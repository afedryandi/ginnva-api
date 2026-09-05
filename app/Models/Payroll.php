<?php

namespace App\Models;

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
        'deduction_per_violation',
        'total_deduction',
        'net_pay',
        'status',
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
        'deduction_per_violation'    => 'decimal:2',
        'total_deduction'            => 'decimal:2',
        'net_pay'                    => 'decimal:2',
        'paid_at'                    => 'datetime',
    ];

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
     * Cara hitung potongan telat (disepakati eksplisit, bukan tebakan):
     * toleransi bulanan (Store::late_tolerance_minutes) itu BUDGET MENIT
     * kumulatif, bukan jatah "boleh telat berapa hari". Selama menit telat
     * yang terkumpul (urut tanggal) masih di bawah budget, tidak kena
     * apa-apa. Begitu kumulatifnya lewat budget, HARI ITU dan setiap hari
     * telat SETELAHNYA (dalam bulan yang sama) masing-masing kena potongan
     * flat (Store::late_deduction_amount per hari).
     *
     * Cara hitung potongan Alpha & proporsi (disepakati 2026-08-27, audit
     * modul Penggajian): "no work no pay" — gaji per hari = base_salary /
     * jumlah hari toko BUKA bulan itu (working_days_in_month). Alpha
     * (mangkir tanpa keterangan) memotong 1x gaji per hari untuk tiap hari
     * alpha. Karyawan yang join_date-nya JATUH DI BULAN YANG SAMA cuma
     * dihitung gaji pokoknya proporsional dari hari kerja sejak join_date
     * (prorated_base_salary) — kalau join_date di bulan sebelumnya atau
     * tidak ada, dianggap kerja penuh sebulan. Izin/Sakit/Cuti yang SUDAH
     * DISETUJUI (entry_type 'leave') SENGAJA TIDAK memotong apa pun —
     * dianggap tetap dibayar penuh, konsisten dengan Cuti yang memang
     * berbayar menurut UU.
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

            $store = $user->store;

            $attendances = Attendance::query()
                ->where('user_id', $user->id)
                ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->get(['date', 'late_minutes', 'entry_type']);

            $lateDays = $attendances->where('late_minutes', '>', 0)->sortBy('date')->pluck('late_minutes', 'date');
            $alphaDaysCount = $attendances->where('entry_type', 'alpha')->count();

            $tolerance = $store?->late_tolerance_minutes ?? Attendance::DEFAULT_LATE_TOLERANCE_MINUTES;
            $deductionPerViolation = (float) ($store?->late_deduction_amount ?? 0);

            $runningTotal = 0;
            $violationDays = 0;
            foreach ($lateDays as $lateMinutes) {
                $runningTotal += $lateMinutes;
                if ($runningTotal > $tolerance) {
                    $violationDays++;
                }
            }

            // Hari kerja bulan itu = hari toko TIDAK tutup (isClosedOn) —
            // penyebut gaji per hari. Toko tanpa Store (seharusnya tidak
            // pernah terjadi karena user_id selalu ada store_id sebelum
            // digenerate, tapi dijaga) dianggap tidak ada hari kerja sama
            // sekali, gaji per hari = 0 (bukan div-by-zero).
            $workingDaysInMonth = 0;
            for ($day = $periodStart->copy(); $day->lte($periodEnd); $day->addDay()) {
                if (! $store?->isClosedOn($day)) {
                    $workingDaysInMonth++;
                }
            }

            $baseSalary = (float) ($user->base_salary ?? 0);
            $dailyRate = $workingDaysInMonth > 0 ? $baseSalary / $workingDaysInMonth : 0;

            // Proporsi HANYA kalau join_date jatuh DI DALAM bulan payroll
            // ini — join_date di bulan-bulan sebelumnya (atau kosong)
            // berarti karyawan sudah kerja penuh sebulan ini, tidak perlu
            // diproporsikan lagi.
            if ($user->join_date && $user->join_date->between($periodStart, $periodEnd)) {
                $employedWorkingDays = 0;
                for ($day = $user->join_date->copy(); $day->lte($periodEnd); $day->addDay()) {
                    if (! $store?->isClosedOn($day)) {
                        $employedWorkingDays++;
                    }
                }
                $proratedBaseSalary = round($dailyRate * $employedWorkingDays, 2);
            } else {
                $proratedBaseSalary = $baseSalary;
            }

            $alphaDeduction = round($dailyRate * $alphaDaysCount, 2);
            $lateDeduction = $violationDays * $deductionPerViolation;
            $totalDeduction = $alphaDeduction + $lateDeduction;

            // Potongan tidak boleh bikin gaji bersih negatif — sekadar
            // jaring pengaman tampilan, bukan validasi bisnis; kalau
            // sampai kejadian, itu tetap perlu ditinjau admin lewat kolom
            // total_deduction vs prorated_base_salary yang keduanya tetap
            // tersimpan apa adanya.
            $netPay = max(0, $proratedBaseSalary - $totalDeduction);

            $attributes = [
                'store_id'                 => $user->store_id,
                'base_salary'              => $baseSalary,
                'working_days_in_month'    => $workingDaysInMonth,
                'prorated_base_salary'     => $proratedBaseSalary,
                'total_late_minutes'       => (int) $lateDays->sum(),
                'late_violation_days'      => $violationDays,
                'alpha_days'               => $alphaDaysCount,
                'alpha_deduction'          => $alphaDeduction,
                'deduction_per_violation'  => $deductionPerViolation,
                'total_deduction'          => $totalDeduction,
                'net_pay'                  => $netPay,
            ];

            if ($existing) {
                $existing->update($attributes);

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
            ->logOnly(['status', 'net_pay', 'total_deduction', 'alpha_days', 'alpha_deduction', 'paid_by'])
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
