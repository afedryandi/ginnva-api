<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Laporan" — WADAH SEMUA LAPORAN di bawah tab "Penjualan".
 *
 * SEJARAH: kelas ini awalnya jadi tab top-nav "Penjualan" sendiri
 * (2026-09-08). Diubah 2026-09-10 (permintaan susulan): struktur mesti
 * sama persis Majoo — Penjualan > Laporan > Laporan Penjualan >
 * Ringkasan Penjualan (4 tingkat). Filament v3 mentok di 1 tingkat
 * navigationGroup, JADI:
 *   - "Penjualan" turun pangkat: dari Cluster jadi navigationGroup biasa
 *     (label dropdown top-nav). Isinya: SalesDashboard + cluster ini.
 *   - Cluster ini yang jadi "Laporan" ($navigationGroup = 'Penjualan',
 *     $navigationLabel = 'Laporan'). Klik "Laporan" -> masuk sidebar
 *     sub-nav cluster yang berisi grup kategori (Laporan Penjualan,
 *     Laporan Produk, dst) -> item laporannya.
 *
 * Nama kelas SENGAJA tidak diubah jadi LaporanCluster — ~24 file lain
 * refer `$cluster = PenjualanCluster::class`, tidak perlu disentuh.
 * Yang penting label & grup di bawah ini.
 *
 * navigationSort 15 dipertahankan supaya grup "Penjualan" muncul di
 * posisi top-nav yang kurang lebih sama seperti dulu (di antara
 * Booking dan Marketing/Konten).
 */
class PenjualanCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $clusterBreadcrumb = 'Laporan';

    protected static ?int $navigationSort = 15;
}
