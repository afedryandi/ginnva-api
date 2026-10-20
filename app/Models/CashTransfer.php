<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pemindahan uang antar akun kas/bank (Keuangan Fase 1, 2026-11-05), mis. setor tunai toko ke bank atau memindahkan saldo lama akun 1101 ke
 * rekening baru. Dijurnal Debit akun tujuan, Kredit akun asal. Tidak diedit atau dihapus; koreksi lewat pembatalan (jurnal pembalik).
 */
class CashTransfer extends Model
{
    use HasStoreScope;
    use LogsActivity;

    protected $fillable = [
        'transfer_number', 'from_account_id', 'to_account_id', 'store_id', 'amount', 'transfer_date', 'notes', 'journal_entry_id', 'created_by',
        'voided_at', 'voided_by', 'void_reason', 'void_journal_entry_id',
    ];

    protected $casts = ['amount' => 'decimal:2', 'transfer_date' => 'date', 'voided_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['transfer_number', 'from_account_id', 'to_account_id', 'amount', 'transfer_date', 'voided_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('cash_transfer');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'to_account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
