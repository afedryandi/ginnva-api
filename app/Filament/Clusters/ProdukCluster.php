<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Produk" — SEJAJAR dengan cluster "Laporan" & "Analisa
 * Laporan", sama-sama di bawah navigationGroup "Penjualan".
 *
 * Diminta 2026-09-10: replikasi menu "Produk" Majoo. Dari 15 sub-item
 * Majoo, cuma yang RELEVAN untuk Ginnva yang masuk sini:
 *   - Daftar Produk (FilmProductResource, dipindah dari Lainnya > Master
 *     Data) = "Daftar Produk" Majoo
 *   - Master Resep (MasterResepResource) = "Master Resep" Majoo
 * Sisanya (Departemen, Kategori, Layanan, Fasilitas, Ekstra, Paket,
 * Deposit, Harga Ojek Online, Penjadwalan/Berdasarkan-Waktu Harga,
 * Cetak Barcode, dst) TIDAK relevan / terkunci keputusan harga —
 * lihat memory project_penjualan_majoo_blocked_items.
 *
 * Struktur: Penjualan > Produk > Daftar Produk / Master Resep.
 */
class ProdukCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Produk';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $clusterBreadcrumb = 'Produk';

    // Band top-nav grup "Penjualan": SalesDashboard 14, Produk 15,
    // Laporan 16, Analisa Laporan 17. Sort terkecil grup = 14, jadi
    // posisi "Penjualan" di top-nav tetap seperti dulu.
    protected static ?int $navigationSort = 15;
}
