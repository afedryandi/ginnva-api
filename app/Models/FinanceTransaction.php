<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FinanceTransaction extends Model
{
    use LogsActivity;

    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di FinanceTransactionResource::getEloquentQuery()
    // SENGAJA DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

    protected $fillable = [
        'transaction_number',
        'type',
        'finance_category_id',
        'store_id',
        'amount',
        'transaction_date',
        'description',
        'receipt',
        'created_by',
        'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    /**
     * SNAPSHOT akun kategori saat transaksi dibuat / kategorinya diganti
     * (audit Kategori Keuangan 2026-09-28) -- laporan berbasis akun tidak
     * lagi bergantung pada akun kategori "saat ini".
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    /**
     * Nomor bukti transaksi (audit Transaksi Keuangan 2026-09-28) --
     * TRX-YYYYMM-XXXX (bulan = tanggal transaksi), collision-safe
     * (do-while exists), pola sama dengan generateEntryNumber() jurnal.
     */
    public static function generateTransactionNumber(?Carbon $date = null): string
    {
        $month = ($date ?? now())->format('Ym');

        do {
            $candidate = 'TRX-' . $month . '-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4));
        } while (static::withoutGlobalScopes()->where('transaction_number', $candidate)->exists());

        return $candidate;
    }

    protected static function booted(): void
    {
        static::creating(function (FinanceTransaction $transaction) {
            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = static::generateTransactionNumber($transaction->transaction_date ? Carbon::parse($transaction->transaction_date) : null);
            }
        });

        static::saving(function (FinanceTransaction $transaction) {
            if (! $transaction->exists || $transaction->isDirty('finance_category_id') || ! $transaction->chart_of_account_id) {
                $transaction->chart_of_account_id = FinanceCategory::where('id', $transaction->finance_category_id)->value('chart_of_account_id');
            }
        });
    }

    /**
     * Jurnal Umum yang otomatis dibuat dari transaksi ini — lihat
     * FinanceTransactionPostingService. Null kalau transaksi ini dibuat
     * sebelum Fase 3 (integrasi otomatis) ada, sampai transaksinya
     * di-edit ulang (memicu backfill).
     */
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
            ->logOnly(['transaction_number', 'type', 'finance_category_id', 'chart_of_account_id', 'store_id', 'amount', 'transaction_date', 'description', 'receipt', 'journal_entry_id', 'created_by'])
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
