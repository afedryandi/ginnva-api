<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * "Penjualan per Kategori" ala Majoo (dashboard.majoo.id/sales-dashboard)
 * — breakdown pendapatan bulan ini per jenis produk (Kaca Film vs PPF).
 * Diminta 2026-09-08, pasangan BookingRevenueStatsWidget.
 *
 * Booking BISA punya product_kaca_film DAN product_ppf sekaligus tapi
 * cuma 1 transaction_amount (tidak ada rincian per produk di kolom) —
 * split 50/50 untuk baris begitu SENGAJA disamakan PERSIS dengan logika
 * BookingPostingService (jurnal Pendapatan sungguhan di Keuangan: akun
 * 4100 PPF & 4200 Kaca Film, 50/50 kalau dua-duanya true), supaya
 * breakdown di chart ini TIDAK PERNAH menyimpang dari angka yang
 * sebenarnya tercatat di Jurnal Umum.
 *
 * $storeId (audit 2026-09-11, temuan #2) — sama seperti
 * BookingRevenueTrendChart: override filter cabang, diisi lewat
 * @livewire(..., ['storeId' => ...]) dari sales-dashboard.blade.php.
 */
class BookingRevenueByCategoryChart extends ChartWidget
{
    protected static ?string $heading = 'Pendapatan per Kategori (Bulan Ini)';

    // Direnumber 2026-09-14 (audit "urutan metrics Dashboard") — SEBELUMNYA
    // sort=2 bentrok dengan WarrantyTrendChart (juga 2), bikin urutan
    // 2 widget itu tidak terprediksi (tie-break jatuh ke urutan discovery
    // class, gampang tidak sesuai ekspektasi — lihat memory
    // filament_cluster_navigation_group_order.md). Urutan Dashboard
    // sekarang: Revenue (0-3) > Booking count (implisit lewat
    // BookingStatsWidget) > Warranty (4-5) > Quotation (6) > Marketing
    // (7) > Karyawan (8) > Master Data (9).
    protected static ?int $sort = 3;

    // Sama alasan dengan BookingRevenueTrendChart — matikan auto-poll
    // default, data cuma berubah lewat aksi eksplisit "Proses Referral".
    protected static ?string $pollingInterval = null;

    /** Override filter cabang — lihat catatan di atas class. */
    public ?int $storeId = null;

    public function mount(?int $storeId = null): void
    {
        $this->storeId = $storeId;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        // Dipakai di Dashboard utama (/admin) DAN Dashboard Penjualan —
        // salah satu akses cukup.
        return ($user?->hasMenuAccess(\App\Filament\Pages\SalesDashboard::class) ?? false)
            || ($user?->hasMenuAccess(BookingResource::class) ?? false);
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->isFullAccess() ?? false;

        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        $query = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$start, $end]))
            ->where('transaction_amount', '>', 0);

        if (! $isSuperAdmin) {
            // store_id di bookings NOT NULL (migrasi create_bookings_table)
            // — orWhereNull() dulu itu dead code + bocor angka toko lain.
            $query->where('store_id', $user->store_id);
        } elseif ($this->storeId) {
            // Full-access override lewat filter cabang Dashboard Penjualan.
            $query->where('store_id', $this->storeId);
        }

        // Pembagian per jenis layanan DIAMBIL dari BookingRevenueSplitter::shares() -- aturan yang sama dengan Jurnal
        // Umum (bagi rata kalau lebih dari satu jenis), supaya chart ini tidak menyimpang dari pembukuan. Dihitung di PHP
        // (volume 1 bulan kecil) karena aturan pembulatannya tidak praktis diulang di SQL.
        $totals = ['kaca_film' => 0.0, 'ppf' => 0.0, 'detailing' => 0.0, 'premium_wash' => 0.0, 'lainnya' => 0.0];

        $query->get(['id', 'transaction_amount', 'product_kaca_film', 'product_ppf', 'product_detailing', 'product_premium_wash'])
            ->each(function (Booking $booking) use (&$totals) {
                foreach (\App\Services\BookingRevenueSplitter::shares($booking, (float) $booking->transaction_amount) as $key => $portion) {
                    $totals[$key] += $portion;
                }
            });

        $labels = ['Kaca Film', 'PPF', 'Detailing', 'Premium Wash'];
        $data = [round($totals['kaca_film']), round($totals['ppf']), round($totals['detailing']), round($totals['premium_wash'])];
        $colors = ['#3b82f6', '#ED1651', '#16a34a', '#f59e0b'];

        // Booking tanpa jenis layanan hanya ditampilkan kalau ada, supaya grafik tidak penuh batang kosong.
        if ($totals['lainnya'] > 0) {
            $labels[] = 'Lainnya';
            $data[] = round($totals['lainnya']);
            $colors[] = '#94a3b8';
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pendapatan',
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * RawJs -- diperbaiki 2026-09-25 (lihat catatan lengkap di
     * BookingRevenueTrendChart::getOptions(), percobaan pertama pakai
     * extraJsOptions() yang ternyata tidak ada di Filament v3.3.54).
     * 'indexAxis' ikut masuk sini juga (bar horizontal, nilai di sumbu X)
     * karena getOptions() cuma boleh return SATU tipe (array ATAU RawJs,
     * tidak bisa dipecah dua method lagi).
     */
    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
        {
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            const value = context.parsed.x ?? 0;
                            return 'Rp' + new Intl.NumberFormat('id-ID').format(value);
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        callback: function (value) {
                            return 'Rp' + new Intl.NumberFormat('id-ID', { notation: 'compact', compactDisplay: 'short' }).format(value);
                        }
                    },
                    grid: { color: 'rgba(148, 163, 184, 0.12)' }
                },
                y: {
                    grid: { display: false }
                }
            }
        }
        JS);
    }
}
