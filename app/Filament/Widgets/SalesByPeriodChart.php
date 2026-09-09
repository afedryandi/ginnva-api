<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SalesByPeriodReport;
use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * "Grafik Penjualan Per Periode" — diminta 2026-09-09, analog grafik
 * multi-metrik di halaman Penjualan Per Periode Majoo. 3 garis (bukan 4
 * seperti Majoo): Penjualan, Transaksi, Produk -- "Laba Kotor" SENGAJA
 * tidak ikut, sama alasan di seluruh laporan Penjualan lain (butuh HPP
 * yang belum tersedia, lihat SalesSummaryReport).
 *
 * "Toggle metrik" ala Majoo TIDAK perlu dikoding manual — itu perilaku
 * BAWAAN Chart.js (klik label di legend otomatis show/hide dataset
 * itu), sudah otomatis didapat cuma dengan mendaftarkan legend di
 * getOptions(), tidak ada JS kustom yang saya tulis sendiri.
 *
 * Penjualan (Rupiah) & Transaksi/Produk (hitungan satuan kecil) beda
 * skala jauh -- dipisah 2 sumbu-Y (dual axis, fitur resmi Chart.js)
 * supaya garis Transaksi/Produk tidak terlihat rata di dasar grafik.
 *
 * Filter rentang hari lewat getFilters() bawaan ChartWidget (BUKAN
 * disinkronkan ke form Dari/Sampai/Granularitas di SalesByPeriodReport
 * — widget & Page adalah 2 komponen Livewire terpisah, menyinkronkan
 * keduanya butuh wiring lintas-komponen yang tidak saya coba tebak di
 * sini; filter sendiri di widget ini lebih aman & tetap berguna).
 */
class SalesByPeriodChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Penjualan Per Periode';

    public static function canView(): bool
    {
        return SalesByPeriodReport::canAccess();
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
            ->get(['id', 'transaction_amount', 'journal_entry_id', 'product_kaca_film', 'product_ppf']);

        $byDate = [];
        foreach ($bookings as $booking) {
            $date = $booking->journalEntry?->entry_date?->toDateString();
            if (! $date) continue;

            $byDate[$date] ??= ['revenue' => 0.0, 'count' => 0, 'products' => 0];
            $byDate[$date]['revenue'] += (float) $booking->transaction_amount;
            $byDate[$date]['count']++;
            $byDate[$date]['products'] += ($booking->product_kaca_film ? 1 : 0) + ($booking->product_ppf ? 1 : 0);
        }

        $labels = [];
        $revenueData = [];
        $countData = [];
        $productsData = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('d M');
            $revenueData[] = $byDate[$key]['revenue'] ?? 0;
            $countData[] = $byDate[$key]['count'] ?? 0;
            $productsData[] = $byDate[$key]['products'] ?? 0;
            $cursor->addDay();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Penjualan (Rp)',
                    'data' => $revenueData,
                    'borderColor' => '#ED1651',
                    'backgroundColor' => 'rgba(237, 22, 81, 0.12)',
                    'yAxisID' => 'y',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'fill' => true,
                ],
                [
                    'label' => 'Transaksi',
                    'data' => $countData,
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'transparent',
                    'yAxisID' => 'y1',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'fill' => false,
                ],
                [
                    'label' => 'Produk',
                    'data' => $productsData,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'transparent',
                    'yAxisID' => 'y1',
                    'pointRadius' => 2,
                    'borderDash' => [4, 4],
                    'tension' => 0.3,
                    'fill' => false,
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
                // Legend klik = toggle dataset (bawaan Chart.js, TIDAK
                // ada JS kustom yang ditulis untuk ini).
                'legend' => ['display' => true, 'position' => 'top', 'align' => 'end'],
            ],
            'scales' => [
                'y' => [
                    'type' => 'linear',
                    'position' => 'left',
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => 'Penjualan (Rp)'],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.12)'],
                ],
                'y1' => [
                    'type' => 'linear',
                    'position' => 'right',
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'title' => ['display' => true, 'text' => 'Transaksi / Produk'],
                    'grid' => ['display' => false],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
