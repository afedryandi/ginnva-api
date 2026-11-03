<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableItemMovement extends Model
{
    protected $fillable = [
        'consumable_item_id',
        // Penanda cabang (Topik 4, Fase 1, 2026-09-19) -- OPSIONAL,
        // current_stock TETAP 1 angka nasional (lihat catatan lengkap di
        // migrasi add_store_id_to_material_movements_tables).
        'store_id',
        'type',
        'quantity',
        'unit_cost',
        'note',
        'source_type',
        'source_id',
        'user_id',
        'reversed_at',
        'reversed_by',
        'reversed_reason',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    /**
     * Pergerakan yang sudah DIBATALKAN disembunyikan dari semua kueri bawaan (laporan persediaan, perputaran stok, kartu stok,
     * rekonsiliasi, ringkasan), seolah dihapus seperti sebelum pembatalan menyimpan barisnya. Hanya Riwayat Barang Habis Pakai,
     * halaman detailnya, dan riwayat per barang di aplikasi yang menampilkannya (withoutGlobalScope('effective') / withReversed()).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('effective', fn (Builder $query) => $query->whereNull($query->getModel()->getTable() . '.reversed_at'));
    }

    public function scopeWithReversed(Builder $query): Builder
    {
        return $query->withoutGlobalScope('effective');
    }

    /** Akses penuh melihat semua; staf lain hanya baris bertanda tokonya sendiri. Akun tanpa toko (bukan akses penuh) tidak melihat apa pun. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null || (! $user->isFullAccess() && $user->store_id === null)) {
            return $query->whereRaw('1 = 0');
        }

        return $user->isFullAccess() ? $query : $query->where('consumable_item_movements.store_id', $user->store_id);
    }

    public function consumableItem(): BelongsTo
    {
        return $this->belongsTo(ConsumableItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Nomor dokumen asal (mis. "Memo Bahan MM-0001"), atau null bila tidak ada penanda. */
    public function sourceText(): ?string
    {
        if ($this->source_type === null) {
            return null;
        }

        $number = match ($this->source_type) {
            'memo' => MaterialMemo::withoutGlobalScopes()->whereKey($this->source_id)->value('memo_number'),
            'purchase_request' => PurchaseRequest::withoutGlobalScopes()->whereKey($this->source_id)->value('request_number'),
            'stock_write_off' => StockWriteOff::withoutGlobalScopes()->whereKey($this->source_id)->value('write_off_number'),
            default => null,
        };

        return RawMaterialMovement::sourceLabel($this->source_type) . ($number ? " {$number}" : '');
    }

    /**
     * Boleh dibatalkan dari riwayat: bukan koreksi, belum dibatalkan, tidak bersumber dokumen lain (Memo, penerimaan, Stok Terbuang
     * dibatalkan lewat dokumennya), dan tidak ada pergerakan berlaku yang lebih baru untuk barang yang sama.
     */
    public function isReversible(): bool
    {
        if ($this->type === 'correction' || $this->reversed_at !== null || $this->source_type !== null || $this->consumable_item_id === null) {
            return false;
        }

        return ! static::query()
            ->where('consumable_item_id', $this->consumable_item_id)
            ->where('id', '>', $this->id)
            ->where('type', '!=', 'correction')
            ->exists();
    }
}
