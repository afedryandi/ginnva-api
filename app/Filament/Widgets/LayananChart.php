<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LayananReport;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;

/**
 * "Grafik Jenis Order" — diminta 2026-09-09, analog grafik tren di
 * halaman Laporan Jenis Order Majoo. 2 garis (Kaca Film, PPF) --
 * revenue harian, split 50/50 SAMA PERSIS logika LayananReport::getResult()
 * untuk booking 2 produk sekaligus.
 *
 * Filter rentang hari sendiri (getFilters() bawaan ChartWidget), sama
 * alasan dengan SalesByPeriodChart/SalesByOutletChart -- widget & Page
 * 2 komponen Livewire terpisah, tidak disinkronkan ke form Dari/Sampai.
 */
class LayananChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Jenis Order';

    public static function canView(): bool
    {
        return LayananReport::canAccess();
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
            ->with('journalEntry:id,entry_date')
            ->get(['transaction_amount', 'journal_entry_id', 'product_kaca_film', 'product_ppf']);

        $kacaFilmByDate = [];
        $ppfByDate = [];

        foreach ($bookings as $booking) {
            $date = $booking->journalEntry?->entry_date?->toDateString();
            if (! $date) continue;

            $amount = (float) $booking->transaction_amount;
            $bothProducts = $booking->product_ppf && $booking->product_kaca_film;

            if ($bothProducts) {
                $kacaFilmByDate[$date] = ($kacaFilmByDate[$date] ?? 0) + $amount / 2;
                $ppfByDate[$date] = ($ppfByDate[$date] ?? 0) + $amount / 2;
            } elseif ($booking->product_ppf) {
                $ppfByDate[$date] = ($ppfByDate[$date] ?? 0) + $amount;
            } elseif ($booking->product_kaca_film) {
                $kacaFilmByDate[$date] = ($kacaFilmByDate[$date] ?? 0) + $amount;
            }
        }

        $labels = [];
        $kacaFilmData = [];
        $ppfData = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('d M');
            $kacaFilmData[] = $kacaFilmByDate[$key] ?? 0;
            $ppfData[] = $ppfByDate[$key] ?? 0;
            $cursor->addDay();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Kaca Film',
                    'data' => $kacaFilmData,
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'fill' => true,
                ],
                [
                    'label' => 'PPF',
                    'data' => $ppfData,
                    'borderColor' => '#ED1651',
                    'backgroundColor' => 'rgba(237, 22, 81, 0.1)',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'fill' => true,
                ],
            ],
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
