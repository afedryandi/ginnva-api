<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ProductSalesReport;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;

/**
 * "Grafik Penjualan Produk" — diminta 2026-09-09, analog grafik
 * perbandingan produk di halaman Penjualan Produk Majoo. 1 garis per
 * SKU -- DIBATASI top 6 produk terlaris (by revenue) dalam rentang
 * filter widget, supaya grafik tidak penuh puluhan garis kalau katalog
 * FilmProduct berkembang. Booking yang belum diisi SKU TIDAK ikut
 * grafik (tidak ada nama produk yang bisa ditampilkan sebagai garis).
 *
 * Filter rentang hari sendiri (getFilters() bawaan ChartWidget), sama
 * alasan dengan chart Penjualan lain -- widget & Page 2 komponen
 * Livewire terpisah.
 */
class ProductSalesChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Penjualan Produk';

    private const TOP_N = 6;

    public static function canView(): bool
    {
        return ProductSalesReport::canAccess();
    }

    protected function getFilters(): ?array
    {
        return [
            '14' => '14 Hari Terakhir',
            '30' => '30 Hari Terakhir',
            '90' => '90 Hari Terakhir',
        ];
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->whereNotNull('film_product_id')
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
