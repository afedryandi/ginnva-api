<?php

namespace App\Filament\Pages;

use App\Models\Invoice;
use App\Models\Store;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * "Laporan Penjualan" (keputusan pemilik 2026-11-23): penjualan dihitung dari INVOICE, bukan dari booking.
 *
 * Aturan hitung:
 *   - Yang dihitung: invoice berstatus Belum Lunas atau Lunas. Draf dan Void tidak.
 *   - Tanggal: tanggal terbit invoice (issue_date). Pembayaran tidak menggeser tanggal penjualan.
 *   - Nilai penjualan: Total Tagihan invoice (total). Terbayar dan Sisa ditampilkan terpisah.
 *
 * Berdiri sendiri: tidak bergantung pada saklar FEATURE_MONEY_MENUS (menu uang lama tetap tersembunyi) dan tidak membaca kolom booking.
 * Staf toko hanya melihat invoice tokonya (Invoice memakai StoreScope); pilihan toko khusus akses penuh.
 */
class InvoiceSalesReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Laporan Penjualan';

    protected static ?string $title = 'Laporan Penjualan';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.invoice-sales-report';

    /** Penjualan = invoice yang sudah terbit dan tidak di-void. */
    public const COUNTED_STATUSES = ['unpaid', 'paid'];

    private const MAX_RANGE_DAYS = 730;

    private const LIST_LIMIT = 200;

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'granularitas')]
    public ?string $granularity = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasExplicitMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->dateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->dateOrDefault($this->to, now());

        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! in_array($this->granularity, ['harian', 'mingguan', 'bulanan'], true)) {
            $this->granularity = 'harian';
        }

        if (! $this->isFullAccess()) {
            $this->storeId = null;
        }
    }

    public function updatedFrom(): void
    {
        $this->from = $this->dateOrDefault($this->from, now()->startOfMonth());
    }

    public function updatedTo(): void
    {
        $this->to = $this->dateOrDefault($this->to, now());
    }

    public function updatedStoreId(): void
    {
        if (! $this->isFullAccess() || blank($this->storeId)) {
            $this->storeId = null;
        }
    }

    public function isFullAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /** @return array<int, string> */
    public function storeOptions(): array
    {
        return $this->isFullAccess() ? Store::orderBy('name')->pluck('name', 'id')->all() : [];
    }

    private function dateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    /**
     * @return array{from: Carbon, to: Carbon, clamped: bool, count: int, total: float, paid: float, remaining: float, average: float, periods: array<int, array<string, mixed>>, stores: array<int, array<string, mixed>>, invoices: \Illuminate\Support\Collection, listTruncated: bool}
     */
    public function getResult(): array
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();
        $clamped = false;

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS)->startOfDay();
            $clamped = true;
        }

        $query = Invoice::query()
            ->whereIn('status', self::COUNTED_STATUSES)
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]);

        if ($this->isFullAccess() && filled($this->storeId)) {
            $query->where('store_id', $this->storeId);
        }

        $rows = $query->get(['id', 'invoice_number', 'store_id', 'customer_name', 'issue_date', 'status', 'total', 'amount_paid']);

        $total = round((float) $rows->sum('total'), 2);
        $paid = round((float) $rows->sum('amount_paid'), 2);

        $periods = $rows
            ->groupBy(fn (Invoice $i) => $this->bucketKey($i->issue_date))
            ->map(fn ($group, $key) => [
                'key' => $key,
                'label' => $this->bucketLabel($key),
                'count' => $group->count(),
                'total' => round((float) $group->sum('total'), 2),
                'paid' => round((float) $group->sum('amount_paid'), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $storeNames = Store::pluck('name', 'id');
        $stores = $rows
            ->groupBy('store_id')
            ->map(fn ($group, $id) => [
                'name' => $storeNames[$id] ?? 'Toko #' . $id,
                'count' => $group->count(),
                'total' => round((float) $group->sum('total'), 2),
                'paid' => round((float) $group->sum('amount_paid'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $sorted = $rows->sortByDesc(fn (Invoice $i) => $i->issue_date->format('Ymd') . str_pad((string) $i->id, 10, '0', STR_PAD_LEFT))->values();

        return [
            'from' => $from,
            'to' => $to,
            'clamped' => $clamped,
            'count' => $rows->count(),
            'total' => $total,
            'paid' => $paid,
            'remaining' => round($total - $paid, 2),
            'average' => $rows->count() > 0 ? round($total / $rows->count(), 2) : 0.0,
            'periods' => $periods,
            'stores' => $stores,
            'invoices' => $sorted->take(self::LIST_LIMIT),
            'listTruncated' => $sorted->count() > self::LIST_LIMIT,
        ];
    }

    private function bucketKey(Carbon $date): string
    {
        return match ($this->granularity) {
            'bulanan' => $date->format('Y-m'),
            'mingguan' => $date->copy()->startOfWeek()->toDateString(),
            default => $date->toDateString(),
        };
    }

    private function bucketLabel(string $key): string
    {
        return match ($this->granularity) {
            'bulanan' => Carbon::createFromFormat('Y-m', $key)->translatedFormat('F Y'),
            'mingguan' => 'Minggu ' . Carbon::parse($key)->translatedFormat('d M') . ' – ' . Carbon::parse($key)->addDays(6)->translatedFormat('d M Y'),
            default => Carbon::parse($key)->translatedFormat('D, d M Y'),
        };
    }
}
