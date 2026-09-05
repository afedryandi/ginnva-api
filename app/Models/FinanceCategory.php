<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model
{
    protected $fillable = [
        'name',
        'type',
        'chart_of_account_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    /**
     * Akun Bagan Akun yang didebit/dikredit saat transaksi kategori ini
     * diposting otomatis ke Jurnal Umum — lihat
     * FinanceTransactionPostingService.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    /**
     * Dipakai buat menutup jalur hapus permanen kategori yang sudah pernah
     * dipakai transaksi — restrictOnDelete() di DB sudah menjaga level
     * database, ini dicek juga di Resource supaya errornya jadi pesan
     * Filament yang jelas, bukan SQL constraint mentah. Sama pola dengan
     * Partner::hasHistory().
     */
    public function hasTransactions(): bool
    {
        return $this->transactions()->exists();
    }
}
