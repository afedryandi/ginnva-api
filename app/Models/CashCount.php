<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Hitung kas fisik harian satu rekening kas (Keuangan Fase 4, 2026-11-07): uang yang dihitung di laci dibandingkan dengan saldo sistem pada
 * tanggal itu. Satu hitungan per rekening per hari. Hitungan tidak diubah atau dihapus; selisih yang benar-benar terjadi dijurnalkan
 * lewat aksi Jurnal Selisih oleh akses penuh (kekurangan = beban non-operasional, kelebihan = pendapatan non-operasional).
 */
class CashCount extends Model
{
    use HasStoreScope;
    use LogsActivity;

    protected $fillable = [
        'payment_account_id', 'store_id', 'count_date', 'counted_amount', 'system_balance', 'difference', 'notes', 'counted_by',
        'adjustment_journal_entry_id', 'adjusted_by', 'adjusted_at',
    ];

    protected $casts = [
        'count_date' => 'date',
        'counted_amount' => 'decimal:2',
        'system_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'adjusted_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['payment_account_id', 'count_date', 'counted_amount', 'system_balance', 'difference', 'adjusted_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('cash_count');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function hasDifference(): bool
    {
        return abs((float) $this->difference) >= 0.005;
    }

    public function isAdjusted(): bool
    {
        return $this->adjusted_at !== null;
    }
}
