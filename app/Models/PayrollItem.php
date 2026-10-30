<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Satu komponen di slip gaji (Karyawan Fase 1, 2026-10-29). Hanya bisa diubah selama payroll masih draft; setiap perubahan menghitung
 * ulang jumlah tambahan, potongan, dan gaji bersih payroll itu.
 */
class PayrollItem extends Model
{
    use LogsActivity;

    protected $fillable = ['payroll_id', 'payroll_item_category_id', 'label', 'amount', 'is_recurring', 'repeat_until', 'notes', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'is_recurring' => 'boolean', 'repeat_until' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['payroll_id', 'payroll_item_category_id', 'label', 'amount', 'is_recurring', 'repeat_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payroll_item');
    }

    protected static function booted(): void
    {
        static::saving(fn (PayrollItem $item) => $item->assertPayrollIsDraft());
        static::deleting(fn (PayrollItem $item) => $item->assertPayrollIsDraft());

        static::saved(fn (PayrollItem $item) => Payroll::withoutGlobalScopes()->find($item->payroll_id)?->recalculateTotals());
        static::deleted(fn (PayrollItem $item) => Payroll::withoutGlobalScopes()->find($item->payroll_id)?->recalculateTotals());
    }

    private function assertPayrollIsDraft(): void
    {
        $payroll = Payroll::withoutGlobalScopes()->find($this->payroll_id);

        if ($payroll && $payroll->status !== 'draft') {
            throw new RuntimeException('Komponen gaji hanya bisa diubah selama payroll masih draft.');
        }
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PayrollItemCategory::class, 'payroll_item_category_id');
    }

    public function displayLabel(): string
    {
        return $this->label ?: ($this->category?->name ?? 'Komponen');
    }
}
