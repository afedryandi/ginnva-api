<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FinanceTransaction extends Model
{
    use LogsActivity;

    protected $fillable = [
        'type',
        'finance_category_id',
        'store_id',
        'amount',
        'transaction_date',
        'description',
        'receipt',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
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
     * Total pemasukan/pengeluaran/saldo bersih untuk 1 bulan — dipakai
     * FinanceReport (Laporan Keuangan). $storeId null = seluruh toko.
     *
     * @return array{in: float, out: float, net: float}
     */
    public static function totalsForMonth(Carbon $month, ?int $storeId = null): array
    {
        $query = static::query()
            ->whereYear('transaction_date', $month->year)
            ->whereMonth('transaction_date', $month->month)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $in = (float) (clone $query)->where('type', 'in')->sum('amount');
        $out = (float) (clone $query)->where('type', 'out')->sum('amount');

        return ['in' => $in, 'out' => $out, 'net' => $in - $out];
    }

    /**
     * Rincian per kategori untuk 1 bulan — diurut dari nominal terbesar,
     * supaya kategori paling signifikan (mis. "Gaji" atau "Booking")
     * langsung terlihat duluan tanpa perlu sort manual di UI.
     *
     * @return \Illuminate\Support\Collection<int, array{category: string, type: string, total: float}>
     */
    public static function byCategoryForMonth(Carbon $month, ?int $storeId = null, ?string $type = null): \Illuminate\Support\Collection
    {
        return static::query()
            ->selectRaw('finance_category_id, type, SUM(amount) as total')
            ->whereYear('transaction_date', $month->year)
            ->whereMonth('transaction_date', $month->month)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->groupBy('finance_category_id', 'type')
            ->with('category:id,name')
            ->get()
            ->map(fn (self $row) => [
                'category' => $row->category?->name ?? '(Kategori dihapus)',
                'type' => $row->type,
                'total' => (float) $row->total,
            ])
            ->sortByDesc('total')
            ->values();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'finance_category_id', 'store_id', 'amount', 'transaction_date', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('finance_transaction')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Transaksi keuangan #' . $this->id . ' dicatat',
                'updated' => 'Transaksi keuangan #' . $this->id . ' diubah',
                'deleted' => 'Transaksi keuangan #' . $this->id . ' dihapus',
                default => 'Transaksi keuangan #' . $this->id . " — {$eventName}",
            });
    }
}
