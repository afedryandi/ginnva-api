<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Aturan potongan absensi (Karyawan Fase 0, 2026-10-28). Satu baris per tanggal berlaku; payroll sebuah bulan memakai aturan dengan
 * tanggal berlaku terbaru yang jatuh pada atau sebelum awal bulan itu. Nominal diisi staf di panel, bukan di kode.
 *
 *  - Telat: total menit telat sebulan dibagi ukuran blok (bulat ke bawah, hanya blok penuh) x potongan per blok.
 *  - Alpha: tarif per jam x jam kerja hari itu, ditambah denda tetap per hari Alpha.
 */
class AttendanceDeductionRule extends Model
{
    use LogsActivity;

    public const DEFAULT_BLOCK_MINUTES = 15;

    protected $fillable = [
        'effective_from', 'late_block_minutes', 'late_deduction_per_block', 'alpha_fine_per_day', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'late_block_minutes' => 'integer',
        'late_deduction_per_block' => 'decimal:2',
        'alpha_fine_per_day' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['effective_from', 'late_block_minutes', 'late_deduction_per_block', 'alpha_fine_per_day', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('attendance_deduction_rule');
    }

    /** Aturan yang berlaku untuk payroll bulan yang dimulai pada $monthStart (null bila belum ada aturan sama sekali). */
    public static function effectiveFor(Carbon $monthStart): ?self
    {
        return static::query()
            ->whereDate('effective_from', '<=', $monthStart->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /** Potongan telat untuk total menit telat sebulan: hanya blok penuh. */
    public function lateBlocksFor(int $totalLateMinutes): int
    {
        $block = max(1, (int) $this->late_block_minutes);

        return intdiv(max(0, $totalLateMinutes), $block);
    }

    /** Sudah dipakai payroll bulan-bulan sejak tanggal berlaku? Aturan yang sudah dipakai tidak boleh diubah atau dihapus. */
    public function isInUse(): bool
    {
        return Payroll::withoutGlobalScopes()
            ->whereDate('period_month', '>=', $this->effective_from->toDateString())
            ->exists();
    }
}
