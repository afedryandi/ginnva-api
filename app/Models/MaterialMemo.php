<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MaterialMemo extends Model
{
    use LogsActivity;

    protected $fillable = [
        'memo_number',
        'store_id',
        'vehicle_info',
        'spk_number',
        'notes',
        'created_by',
    ];

    /**
     * Format: MEMO-YYYYMMDD-XXXX (urut per hari). Dipanggil di dalam
     * transaction oleh controller supaya tidak race-condition dobel nomor.
     */
    public static function generateMemoNumber(): string
    {
        $datePart = now()->format('Ymd');
        $todayCount = static::where('memo_number', 'like', "MEMO-{$datePart}-%")->count();

        return sprintf('MEMO-%s-%04d', $datePart, $todayCount + 1);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialMemoItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['memo_number', 'store_id', 'vehicle_info', 'spk_number', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
