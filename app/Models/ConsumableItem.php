<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Barang Habis Pakai — lihat catatan lengkap di migrasi
 * create_consumable_items_table. Method-method di sini SENGAJA identik
 * dengan RawMaterial (recordMovement/adjustStock/isLowStock) karena
 * kelakuannya memang sama — cuma domain datanya beda.
 */
class ConsumableItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'code',
        'category',
        'received_date',
        'unit',
        'current_stock',
        'reorder_point',
        'unit_cost',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'current_stock' => 'decimal:2',
        'reorder_point' => 'decimal:2',
        'unit_cost'     => 'decimal:2',
        'received_date' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ConsumableItemMovement::class)->latest();
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point !== null && $this->current_stock <= $this->reorder_point;
    }

    /**
     * Sama persis pola RawMaterial::recordMovement().
     *
     * @throws \InvalidArgumentException kalau stok keluar melebihi stok yang tersedia.
     */
    public function recordMovement(string $type, float $quantity, ?int $userId, ?string $note = null): ConsumableItemMovement
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($type, $quantity, $userId, $note) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($type === 'out' && $item->current_stock < $quantity) {
                throw new \InvalidArgumentException("Stok tidak cukup — sisa stok saat ini {$item->current_stock} {$item->unit}.");
            }

            $item->update([
                'current_stock' => $type === 'in'
                    ? $item->current_stock + $quantity
                    : $item->current_stock - $quantity,
            ]);

            $movement = $item->movements()->create([
                'type' => $type,
                'quantity' => $quantity,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());

            return $movement;
        });
    }

    /**
     * Sama persis pola RawMaterial::adjustStock() — stock opname.
     */
    public function adjustStock(float $actualQuantity, ?int $userId, ?string $note = null): ?ConsumableItemMovement
    {
        return DB::transaction(function () use ($actualQuantity, $userId, $note) {
            $item = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
            $delta = round($actualQuantity - (float) $item->current_stock, 2);

            if (abs($delta) < 0.01) {
                return null;
            }

            $item->update(['current_stock' => $actualQuantity]);

            $movement = $item->movements()->create([
                'type' => 'adjustment',
                'quantity' => $delta,
                'note' => $note,
                'user_id' => $userId,
            ]);

            $this->setRawAttributes($item->getAttributes());

            return $movement;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'category', 'received_date', 'unit', 'current_stock', 'reorder_point', 'unit_cost', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('consumable_item')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Barang habis pakai \"{$this->name}\" didaftarkan",
                'updated' => "Barang habis pakai \"{$this->name}\" diubah",
                'deleted' => "Barang habis pakai \"{$this->name}\" dihapus",
                default => "Barang habis pakai \"{$this->name}\" — {$eventName}",
            });
    }
}
