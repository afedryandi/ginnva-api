<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialMemoItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'material_memo_id',
        'item_type',
        'item_id',
        'item_name',
        'unit',
        'qty_taken',
        'qty_returned',
        'qty_used',
        'meters_used',
        'scroll_code_usage_id',
        'condition_notes',
    ];

    protected $casts = [
        'qty_taken'    => 'decimal:2',
        'qty_returned' => 'decimal:2',
        'qty_used'     => 'decimal:2',
        'meters_used'  => 'decimal:2',
    ];

    public function memo(): BelongsTo
    {
        return $this->belongsTo(MaterialMemo::class, 'material_memo_id');
    }

    /**
     * Resolve model asli di balik item_type/item_id — BUKAN relasi
     * Eloquent morphTo() beneran (codebase ini belum pernah pakai
     * morphMap), cukup lookup manual berdasarkan string jenisnya.
     */
    public function resolveItem(): RawMaterial|ConsumableItem|InventoryItem|null
    {
        return match ($this->item_type) {
            'raw_material' => RawMaterial::find($this->item_id),
            'consumable_item' => ConsumableItem::find($this->item_id),
            'inventory_item' => InventoryItem::find($this->item_id),
            default => null,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['qty_taken', 'qty_returned', 'qty_used', 'meters_used', 'condition_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('material_memo_item')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Barang \"{$this->item_name}\" ditambahkan ke memo",
                'updated' => "Barang \"{$this->item_name}\" di memo diubah",
                'deleted' => "Barang \"{$this->item_name}\" di memo dihapus",
                default => "Barang \"{$this->item_name}\" — {$eventName}",
            });
    }
}
