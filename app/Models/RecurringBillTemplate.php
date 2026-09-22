<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * "Template tagihan rutin" (audit Majoo, f48) — lihat migrasi
 * create_recurring_bill_templates_table & RecurringBillGenerationService
 * untuk alur generate-nya.
 */
class RecurringBillTemplate extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'supplier_name',
        'store_id',
        'chart_of_account_id',
        'amount',
        'day_of_month',
        'next_run_date',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'day_of_month' => 'integer',
        'next_run_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tanggal jatuh tempo bulan berikutnya dari next_run_date SEKARANG
     * (dipanggil SETELAH generate berhasil, bukan sebelumnya) — clamp ke
     * hari terakhir bulan itu kalau day_of_month lebih besar dari jumlah
     * hari bulan tsb (mis. day_of_month=31 di bulan Februari -> 28/29).
     */
    public function computeNextRunDate(): Carbon
    {
        $next = $this->next_run_date->copy()->addMonthNoOverflow();
        $lastDayOfMonth = $next->daysInMonth;

        return $next->day(min($this->day_of_month, $lastDayOfMonth));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'supplier_name', 'amount', 'day_of_month', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('recurring_bill_template')
            ->setDescriptionForEvent(fn (string $eventName) => "Template Tagihan Rutin \"{$this->name}\" {$eventName}");
    }
}
