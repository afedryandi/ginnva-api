<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Asset extends Model
{
    use LogsActivity;

    protected $fillable = [
        'asset_tag',
        'name',
        'category',
        'status',
        'assigned_to',
        'store_id',
        'purchase_date',
        'purchase_cost',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:2',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Kode unik per aset — dikodekan ke QR (sama pola dengan
     * InventoryItem::generateCode()) supaya bisa ditempel & dicari fisik.
     */
    public static function generateAssetTag(): string
    {
        do {
            $tag = 'ASSET-' . strtoupper(Str::random(8));
        } while (self::where('asset_tag', $tag)->exists());

        return $tag;
    }

    /**
     * Riwayat perpindahan/perubahan kondisi cukup lewat activity log —
     * tidak ada tabel movement khusus di sini karena aset tidak
     * "habis"/berkurang seperti inventory_items atau raw_materials,
     * cuma berubah status/pemegang/lokasi.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'status', 'assigned_to', 'store_id', 'purchase_date', 'purchase_cost', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('asset')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Aset \"{$this->name}\" ({$this->asset_tag}) didaftarkan",
                'updated' => "Aset \"{$this->name}\" ({$this->asset_tag}) diubah",
                'deleted' => "Aset \"{$this->name}\" ({$this->asset_tag}) dihapus",
                default => "Aset \"{$this->name}\" — {$eventName}",
            });
    }
}
