<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialBatch extends Model
{
    protected $fillable = [
        'raw_material_id',
        'quantity',
        'unit_cost',
        'received_date',
        'expiry_date',
        'is_adjustment',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'received_date' => 'date',
        'expiry_date' => 'date',
        'is_adjustment' => 'boolean',
    ];

    /**
     * Nilai stok batch: sisa x harga beli batch itu sendiri; kalau batch tidak punya harga (mis. batch hasil
     * penyesuaian stok) jatuh ke harga terakhir bahan bakunya -- bukan 0, supaya nilai tidak diam-diam terlalu rendah.
     */
    public function stockValue(): float
    {
        return (float) $this->quantity * (float) ($this->unit_cost ?? $this->rawMaterial?->unit_cost ?? 0);
    }

    /** Selisih hari ke tanggal kedaluwarsa: positif = belum, 0 = hari ini, negatif = sudah lewat. */
    public function daysUntilExpiry(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
