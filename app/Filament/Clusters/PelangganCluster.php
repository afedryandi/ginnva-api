<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Pelanggan" — di bawah navigationGroup "Penjualan" (dibuat
 * 2026-09-10, ikut struktur Majoo: Penjualan > Pelanggan > Daftar
 * Pelanggan). Sejajar dengan cluster Produk / Laporan / Analisa Laporan
 * / Inventori.
 *
 * Isinya "Daftar Pelanggan" (CustomerResource, dipindah dari cluster
 * Marketing/Konten). Grup Pelanggan / Grup Harga Spesial / Kustom Data
 * Majoo TIDAK dibuat (skip — lihat memory project_penjualan_majoo_blocked_items).
 *
 * Band sort grup "Penjualan": Dashboard 14, Laporan 15, Analisa Laporan
 * 16, Produk 17, Inventori 18, Pelanggan 19 (diurutkan ulang 2026-09-11).
 */
class PelangganCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Pelanggan';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $clusterBreadcrumb = 'Pelanggan';

    protected static ?int $navigationSort = 19;
}
