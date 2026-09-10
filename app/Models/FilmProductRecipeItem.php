<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * 1 bahan dalam "Master Resep" (BOM) sebuah Produk Film. Lihat migrasi
 * 2026_09_10_000001_create_film_product_recipe_items_table.
 */
class FilmProductRecipeItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'film_product_id',
        'item_type',
        'item_id',
        'item_name',
        'unit',
        'standard_qty',
        'note',
    ];

    protected $casts = [
        'standard_qty' => 'decimal:2',
    ];

    public function filmProduct(): BelongsTo
    {
        return $this->belongsTo(FilmProduct::class);
    }

    /**
     * Resolve model asli di balik item_type/item_id — pola sama dengan
     * MaterialMemoItem::resolveItem() (lookup manual, bukan morphTo).
     * 'film_roll' tidak punya model tersendiri (roll = film produk itu
     * sendiri), jadi return null.
     */
    public function resolveItem(): RawMaterial|ConsumableItem|null
    {
        return match ($this->item_type) {
            'raw_material' => RawMaterial::find($this->item_id),
            'consumable_item' => ConsumableItem::find($this->item_id),
            default => null,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['item_type', 'item_id', 'item_name', 'unit', 'standard_qty', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('film_product_recipe_item')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Bahan \"{$this->item_name}\" ditambahkan ke resep",
                'updated' => "Bahan \"{$this->item_name}\" di resep diubah",
                'deleted' => "Bahan \"{$this->item_name}\" dihapus dari resep",
                default => "Bahan \"{$this->item_name}\" — {$eventName}",
            });
    }
}
