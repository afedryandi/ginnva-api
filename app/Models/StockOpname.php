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

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draf (menunggu diterapkan)',
        self::STATUS_APPLIED => 'Diterapkan',
        self::STATUS_CANCELLED => 'Dibuang',
    ];

    protected $fillable = [
        'opname_number',
        'store_id',
        'opname_date',
        'notes',
        'status',
        'snapshot_at',
        'applied_at',
        'applied_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'created_by',
    ];

    protected $casts = [
        'opname_date' => 'date',
        'snapshot_at' => 'datetime',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public static function generateOpnameNumber(): string
    {
        $datePart = now()->format('Ymd');
        $prefix = "OPN-{$datePart}-";

        // Urutan TERBESAR hari ini LINTAS TOKO (withoutGlobalScopes). SEBELUMNYA menghitung baris lewat Global Scope toko:
        // staf toko B menghitung 0 sesi (milik toko A tidak terlihat), menghasilkan nomor yang sama dengan toko A, lalu
        // ditolak constraint UNIQUE opname_number.
        $last = static::withoutGlobalScopes()
            ->where('opname_number', 'like', $prefix . '%')
            ->pluck('opname_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return sprintf('OPN-%s-%04d', $datePart, $last + 1);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['opname_number', 'store_id', 'opname_date', 'notes', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('stock_opname');
    }
}
