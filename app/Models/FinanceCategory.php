<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model
{
    protected $fillable = [
        'name',
        'type',
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
