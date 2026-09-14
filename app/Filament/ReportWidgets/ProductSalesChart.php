<?php

namespace App\Filament\ReportWidgets;

use App\Filament\Pages\ProductSalesReport;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * "Grafik Penjualan Produk" — diminta 2026-09-09, analog grafik
 * perbandingan produk di halaman Penjualan Produk Majoo. 1 garis per
 * SKU -- DIBATASI top 6 produk terlaris (by revenue) dalam rentang
 * filter widget, supaya grafik tidak penuh puluhan garis kalau katalog
 * FilmProduct berkembang. Booking yang belum diisi SKU TIDAK ikut
 * grafik (tidak ada nama produk yang bisa ditampilkan sebagai garis).
 *
 * SINKRON dengan ProductSalesReport (audit 2026-09-11, temuan A) —
 * SEBELUMNYA widget ini punya filter sendiri (14/30/90 hari terakhir),
 * terputus dari form Dari/Sampai di halamannya. Sekarang menerima
 * $from/$to/$storeId lewat mount() (dipanggil @livewire(..., ['from'=>...])
 * dari blade halaman, BUKAN <x-filament-widgets::widgets> — pola sama
 * yang sudah terbukti jalan di chart Penjualan lain).
 *
 * PINDAH ke namespace App\Filament\ReportWidgets (audit 2026-09-14,
 * temuan 🔴) — SEBELUMNYA di App\Filament\Widgets yang di-auto-discover
 * panel-wide (lihat AdminPanelProvider::discoverWidgets()), jadi widget
 * ini ikut nongol OTOMATIS di Dashboard utama /admin (bukan cuma di
 * ProductSalesReport tempat dia seharusnya). Karena mount() dipanggil
 * TANPA argumen oleh Dashboard generik, $storeId selalu null di sana —
 * staff/manajer toko manapun yang buka Dashboard utama melihat data
 * PENJUALAN PRODUK SELURUH PERUSAHAAN, bukan cuma tokonya. Fallback
 * auth()->user() di bawah (sama pola LayananChart/SalesByOutletChart)
 * menutup celahnya untuk defense-in-depth, TAPI akar masalahnya
 * (auto-discovery) yang diperbaiki dengan pindah namespace ini — sama
 * pola InventoryWidgets (lihat catatan di InventoryStatsOverview.php).
 */
class ProductSalesChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Penjualan Produk';

    protected static ?string $pollingInterval = null;

    private const TOP_N = 6;

    public ?string $from = null;

    public ?string $to = null;

    public ?int $storeId = null;

    public function mount(?string $from = null, ?string $to = null, ?int $storeId = null): void
    {
        $this->from = $from;
        $this->to = $to;
        $this->storeId = $storeId;
    }

    public static function canView(): bool
    {
        return ProductSalesReport::canAccess();
    }

    protected function getData(): array
    {
        $start = $this->from ? Carbon::parse($this->from)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $this->to ? Carbon::parse($this->to)->endOfDay() : now()->endOfDay();

        // Fallback defense-in-depth (audit 2026-09-14) — kalau widget ini
        // somehow dirender tanpa lewat @livewire(['storeId' => ...])
        // (mis. auto-discovery lama/cache), staff non-full-access TETAP
        // dikunci ke tokonya sendiri, tidak pernah diam-diam company-wide.
        $user = auth()->user();
        $storeId = $this->storeId ?? (($user?->isFullAccess() ?? false) ? null : $user?->store_id);

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->whereNotNull('film_product_id')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['journalEntry:id,entry_date', 'filmProduct:id,name'])
            ->get(['transaction_amount', 'journal_entry_id', 'film_product_id']);

        // Top N produk by total revenue dalam rentang ini -- urutan
        // dataset & warnanya ditentukan dari sini.
        $topProductIds = $bookings->groupBy('film_product_id')
            ->map(fn ($group) => (float) $group->sum('transaction_amount'))
            ->sortDesc()
            ->take(self::TOP_N)
            ->keys();

        $labels = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->format('d M');
            $cursor->addDay();
        }

        $palette = ['#ED1651', '#2563eb', '#f59e0b', '#16a34a', '#7c3aed', '#0891b2'];

        $datasets = $topProductIds->values()->map(function ($productId, $index) use ($bookings, $start, $end, $palette) {
            $productBookings = $bookings->where('film_product_id', $productId);
            $name = $productBookings->first()?->filmProduct?->name ?? '—';

            $byDate = [];
            foreach ($productBookings as $booking) {
                $date = $booking->journalEntry?->entry_date?->toDateString();
                if (! $date) continue;
                $byDate[$date] = ($byDate[$date] ?? 0) + (float) $booking->transaction_amount;
            }

            $data = [];
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $data[] = $byDate[$cursor->toDateString()] ?? 0;
                $cursor->addDay();
            }

            $color = $palette[$index % count($palette)];

            return [
                'label' => $name,
                'data' => $data,
                'borderColor' => $color,
                'backgroundColor' => 'transparent',
                'pointRadius' => 2,
                'tension' => 0.3,
                'fill' => false,
            ];
        })->values()->all();

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top', 'align' => 'end'],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.12)'],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
