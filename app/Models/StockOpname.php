<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Sesi "Stok Opname" -- keputusan atasan 2026-09-19 (Topik 4, Fase 1).
 * Lihat catatan lengkap di migrasi create_stock_opnames_table &
 * StockOpnameService.
 */
class StockOpname extends Model
{
    use LogsActivity;
    use HasStoreScope;

    protected $fillable = [
        'opname_number',
        'store_id',
        'opname_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'opname_date' => 'date',
    ];

    public static function generateOpnameNumber(): string
    {
        $datePart = now()->format('Ymd');
        $todayCount = static::where('opname_number', 'like', "OPN-{$datePart}-%")->count();

        return sprintf('OPN-%s-%04d', $datePart, $todayCount + 1);
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
        return $this->hasMany(StockOpnameItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['opname_number', 'store_id', 'opname_date', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('stock_opname');
    }
}
