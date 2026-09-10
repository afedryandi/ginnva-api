<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Inventori" — di bawah navigationGroup "Penjualan" (dipindah
 * 2026-09-10, mengikuti struktur Majoo: Penjualan > Inventori > Daftar
 * Bahan Baku). Sejajar dengan cluster Produk / Laporan / Analisa Laporan.
 *
 * Isi cluster (RawMaterial, ConsumableItem, InventoryItem + riwayat,
 * Aset, Memo Barang, Permohonan Pembelian, Dashboard Inventaris) tidak
 * berubah — cuma penempatan top-nav-nya.
 *
 * Band sort grup "Penjualan": Dashboard 14, Produk 15, Laporan 16,
 * Analisa Laporan 17, Inventori 18.
 */
class InventarisCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Inventori';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $clusterBreadcrumb = 'Inventori';

    protected static ?int $navigationSort = 18;
}
