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
    // Audit framework 2026-09-14, "Auto-polling widget Livewire" --
    // sebelumnya ikut default Filament (poll tiap 60 detik walau
    // halaman idle), tidak perlu real-time untuk widget ini.
    protected static ?string $pollingInterval = null;

    protected static ?string $heading = 'Grafik Jenis Order';

    // Sort ditambahkan 2026-09-25 (audit Dashboard Utama) — SEBELUMNYA
    // widget ini TIDAK PUNYA $sort sama sekali, beda dari 12 widget lain
    // di folder ini yang semuanya eksplisit diberi angka 0-10. Posisinya
    // di antara widget lain jadi tidak terprediksi (tie-break ke urutan
    // discovery class). Ditaruh paling akhir (setelah MasterDataStatsWidget
    // = 10) karena full-width, wajar jadi penutup grid. Lihat urutan
    // lengkap di BookingRevenueByCategoryChart.php.
    protected static ?int $sort = 11;

    // Full-width (diminta 2026-09-14) — di Dashboard utama /admin, widget
    // ini SEKARANG jadi salah satu dari cuma 2 chart yang tersisa (4
    // lainnya dipindah ke App\Filament\ReportWidgets, lihat audit "Tab
    // Dashboard"), jadi grid 2-kolom bawaan Filament menyisakan banyak
    // area kosong janggal di sebelahnya. Full-width bikin tiap chart
    // menumpuk 1 baris penuh, tidak ada celah kosong.
    protected int|string|array $columnSpan = 'full';

    // Full-width tanpa batas tinggi bikin kartu jadi terlalu besar
    // (canvas Chart.js ikut melebar proporsional) — dibatasi supaya
    // proporsinya wajar (diminta 2026-09-14).
    protected static ?string $maxHeight = '280px';

    /**
     * Override filter cabang (audit Dashboard Utama 2026-09-25) — SEBELUMNYA
     * full-access SELALU lihat company-wide di sini, tidak ada cara
     * override sama sekali (beda dari chart lain yang sudah dapat
     * $storeId lewat mount()). Sekarang diisi lewat
     * @livewire(..., ['storeId' => ...]) dari dashboard-home.blade.php.
     */
    public ?int $storeId = null;

    public function mount(?int $storeId = null): void
    {
        $this->storeId = $storeId;
    }

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

        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit Laporan Jenis
        // Order): grafik ini SEBELUMNYA SAMA SEKALI TIDAK ADA scoping
        // toko — manajer toko manapun lihat tren company-wide, baik dari
        // Laporan Jasa maupun Laporan Jenis Order (widget yang sama).
        $user = auth()->user();
        $storeId = ($user?->isFullAccess() ?? false) ? $this->storeId : $user?->store_id;

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
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
