<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'request_number',
        'store_id',
        'item_type',
        'item_id',
        'item_name',
        'unit',
        'quantity',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'fulfilled_at',
    ];

    protected $casts = [
        'quantity'     => 'decimal:2',
        'reviewed_at'  => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Resolve model katalog asli di balik item_type/item_id — null untuk
     * 'asset' karena itemnya belum ada (permintaan beli BARU), sama pola
     * manual-lookup seperti MaterialMemoItem::resolveItem().
     */
    public function resolveItem(): RawMaterial|ConsumableItem|null
    {
        return match ($this->item_type) {
            'raw_material'    => RawMaterial::find($this->item_id),
            'consumable_item' => ConsumableItem::find($this->item_id),
            default           => null,
        };
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseRequest $request) {
            if (empty($request->request_number)) {
                $request->request_number = static::generateRequestNumber();
            }
        });
    }

    protected static function generateRequestNumber(): string
    {
        do {
            $candidate = 'PR-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('request_number', $candidate)->exists());

        return $candidate;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'quantity', 'review_note', 'reviewed_by', 'fulfilled_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('purchase_request')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Permohonan pembelian #{$this->request_number} diajukan",
                'updated' => "Permohonan pembelian #{$this->request_number} diubah",
                'deleted' => "Permohonan pembelian #{$this->request_number} dihapus",
                default   => "Permohonan pembelian #{$this->request_number} — {$eventName}",
            });
    }
}