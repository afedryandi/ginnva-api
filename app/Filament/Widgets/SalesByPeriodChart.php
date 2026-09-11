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
 * SINKRON dengan SalesByPeriodReport (audit 2026-09-11, temuan A) —
 * SEBELUMNYA widget ini punya filter sendiri (14/30/90 hari terakhir,
 * selalu harian), terputus dari form Dari/Sampai/Granularitas di
 * halamannya. Sekarang menerima $from/$to/$granularity/$storeId lewat
 * mount() (dipanggil @livewire(..., ['from'=>...]) dari blade halaman,
 * BUKAN <x-filament-widgets::widgets> yang tidak bisa kirim param
 * custom — pola sama yang sudah terbukti jalan di chart SalesDashboard)
 * dan mengelompokkan periode PERSIS sama dengan tabel di bawahnya lewat
 * SalesByPeriodReport::periodKeyFor() (satu implementasi, dua pemakai).
 * $storeId di-scope PERSIS sama dengan halaman (staff dikunci ke
 * tokonya, sudah di-resolve oleh pemanggil — lihat blade).
 */
class SalesByPeriodChart extends ChartWidget
{
    protected static ?string $heading = 'Grafik Penjualan Per Periode';

    protected static ?string $pollingInterval = null;

    public ?string $from = null;

    public ?string $to = null;

    public ?string $granularity = null;

    public ?int $storeId = null;

    public function mount(?string $from = null, ?string $to = null, ?string $granularity = null, ?int $storeId = null): void
    {
        $this->from = $from;
        $this->to = $to;
        $this->granularity = $granularity ?? 'harian';
        $this->storeId = $storeId;
    }

    public static function canView(): bool
    {
        return SalesByPeriodReport::canAccess();
    }

    protected function getData(): array
    {
        $start = $this->from ? Carbon::parse($this->from)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $this->to ? Carbon::parse($this->to)->endOfDay() : now()->endOfDay();
        $granularity = $this->granularity ?? 'harian';

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($this->storeId, fn ($q) => $q->where('store_id', $this->storeId))
            ->with('journalEntry:id,entry_date')
            ->get(['id', 'transaction_amount', 'journal_entry_id', 'product_kaca_film', 'product_ppf']);

        $buckets = [];
        $order = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            [$key, $label, $bucketEnd] = SalesByPeriodReport::periodKeyFor($cursor, $granularity);
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['label' => $label, 'revenue' => 0.0, 'count' => 0, 'products' => 0];
                $order[] = $key;
            }
            $cursor = $bucketEnd->copy()->addDay();
        }

        foreach ($bookings as $booking) {
            $entryDate = $booking->journalEntry?->entry_date;
            if (! $entryDate) continue;

            [$key] = SalesByPeriodReport::periodKeyFor(Carbon::parse($entryDate), $granularity);
            if (! isset($buckets[$key])) continue;

            $buckets[$key]['revenue'] += (float) $booking->transaction_amount;
            $buckets[$key]['count']++;
            $buckets[$key]['products'] += ($booking->product_kaca_film ? 1 : 0) + ($booking->product_ppf ? 1 : 0);
        }

        $labels = array_map(fn ($key) => $buckets[$key]['label'], $order);
        $revenueData = array_map(fn ($key) => $buckets[$key]['revenue'], $order);
        $countData = array_map(fn ($key) => $buckets[$key]['count'], $order);
        $productsData = array_map(fn ($key) => $buckets[$key]['products'], $order);

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
