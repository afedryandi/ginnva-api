<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Analisa Laporan" — SEJAJAR dengan cluster "Laporan"
 * (PenjualanCluster), sama-sama di bawah navigationGroup "Penjualan".
 *
 * Diminta 2026-09-10: struktur mesti Penjualan > Analisa Laporan >
 * Waktu Teramai Penjualan (3 tingkat) — BUKAN jadi sub-nav grup di
 * dalam cluster "Laporan". Jadi 4 halaman analisa (PeakSalesTimeReport,
 * PeakProductTimeReport, StockTurnoverReport, CustomerSatisfactionReport)
 * dipindah dari PenjualanCluster ke sini.
 *
 * Isi cluster ini tampil FLAT (tanpa $navigationGroup di tiap halaman)
 * — cuma 4 item, tidak perlu dikategorikan lagi.
 */
class AnalisaLaporanCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Analisa Laporan';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $clusterBreadcrumb = 'Analisa Laporan';

    // 16 — tepat setelah cluster "Laporan" (15), sebelum tab top-nav
    // lain. Grup "Penjualan" tetap mendarat di posisi lama karena sort
    // terkecil anggotanya = 14 (SalesDashboard).
    protected static ?int $navigationSort = 16;
}
