<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SalesByOutletReport;
use App\Models\Booking;
use App\Models\Store;
use Filament\Widgets\ChartWidget;

/**
 * "Grafik Penjualan Outlet" — diminta 2026-09-09, analog grafik
 * perbandingan outlet di halaman Penjualan Outlet Majoo. 1 garis per
 * toko (bukan per metrik seperti SalesByPeriodChart), supaya tren
 * antar-cabang bisa dibandingkan langsung.
 *
 * Warna garis di-generate deterministik dari nama toko (hash), bukan
 * palet manual per toko -- supaya otomatis nambah warna kalau toko
 * baru dibuka, tidak perlu update kode.
 *
 * Filter rentang hari sendiri (getFilters() bawaan ChartWidget), sama
 * alasan dengan SalesByPeriodChart -- widget & Page 2 komponen Livewire
 * terpisah, tidak disinkronkan ke form Dari/Sampai di halamannya.
 */
class SalesByOutletChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Penjualan Outlet';

    public static function canView(): bool
    {
        return SalesByOutletReport::canAccess();
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
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        $stores = Store::query()
            ->when(! $isFullAccess, fn ($q) => $q->where('id', $user?->store_id))
            ->orderBy('name')
            ->get(['id', 'name']);

        $labels = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->format('d M');
            $cursor->addDay();
        }

        $datasets = $stores->map(function (Store $store) use ($start, $end) {
            $bookings = Booking::query()
                ->where('store_id', $store->id)
                ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
                ->where('transaction_amount', '>', 0)
                ->with('journalEntry:id,entry_date')
                ->get(['transaction_amount', 'journal_entry_id']);

            $byDate = [];
            foreach ($bookings as $booking) {
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

            $color = $this->colorForStore($store->name);

            return [
                'label' => $store->name,
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

    /**
     * Warna deterministik dari nama toko -- hash nama jadi hue HSL,
     * supaya toko yang sama SELALU dapat warna yang sama tiap render
     * (bukan acak tiap request), dan otomatis dapat warna baru kalau
     * ada toko baru tanpa perlu update daftar warna manual.
     */
    private function colorForStore(string $name): string
    {
        $hue = crc32($name) % 360;

        return "hsl({$hue}, 65%, 50%)";
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
