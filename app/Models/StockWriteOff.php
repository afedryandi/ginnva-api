<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * 1 kejadian write-off stok (barang rusak / kedaluwarsa / hilang).
 * Lihat migrasi 2026_09_10_000010 & StockWriteOffService.
 */
class StockWriteOff extends Model
{
    use LogsActivity;

    protected $fillable = [
        'write_off_number',
        'writeoffable_type',
        'writeoffable_id',
        'item_name',
        'unit',
        'quantity',
        'unit_cost',
        'total_value',
        'reason',
        'note',
        'store_id',
        'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
    ];

    public const REASON_LABELS = [
        'damaged' => 'Rusak',
        'expired' => 'Kedaluwarsa',
        'lost' => 'Hilang',
        'other' => 'Lainnya',
    ];

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }

    /**
     * WO-YYYYMMDD-XXXX (urut per hari). Dipanggil di dalam transaction
     * oleh StockWriteOffService supaya tidak race-condition dobel nomor.
     */
    public static function generateNumber(): string
    {
        $datePart = now()->format('Ymd');
        $todayCount = static::where('write_off_number', 'like', "WO-{$datePart}-%")->count();

        return sprintf('WO-%s-%04d', $datePart, $todayCount + 1);
    }

    public function resolveItem(): RawMaterial|ConsumableItem|null
    {
        return match ($this->writeoffable_type) {
            'raw_material' => RawMaterial::find($this->writeoffable_id),
            'consumable_item' => ConsumableItem::find($this->writeoffable_id),
            default => null,
        };
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['item_name', 'quantity', 'reason', 'total_value', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('stock_write_off');
    }
}
