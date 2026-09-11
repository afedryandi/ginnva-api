<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Promosi" — di bawah navigationGroup "Penjualan" (dibuat
 * 2026-09-10, ikut struktur menu "Promosi" Majoo). Sejajar dengan
 * cluster Produk / Laporan / Analisa Laporan / Inventori / Pelanggan.
 *
 * Isi: Voucher Promo (= "Kupon" Majoo), Katalog Reward + Klaim Reward
 * (= "Poin Reward" Majoo) — dipindah dari cluster Marketing/Konten.
 * "Promo bersyarat" Majoo (Per Total Pembelian / Per Produk) TIDAK
 * dibuat — tidak cocok model booking Ginnva (transaction_amount nego
 * manual, bukan dijumlah dari line item).
 *
 * Band sort grup "Penjualan": Dashboard 14, Laporan 15, Analisa Laporan
 * 16, Produk 17, Inventori 18, Pelanggan 19, Promosi 20 (diurutkan
 * ulang 2026-09-11).
 */
class PromosiCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Promosi';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $clusterBreadcrumb = 'Promosi';

    protected static ?int $navigationSort = 20;
}
