<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Master Data" — di bawah navigationGroup "Lainnya" (dibuat
 * 2026-09-14, memecah LainnyaCluster lama jadi struktur dropdown top-nav
 * bertingkat, sama pola dengan "Penjualan" — lihat catatan lengkap di
 * PenjualanCluster.php). Isi: Kendaraan, Kode Gulungan (disembunyikan
 * dari nav, lihat ScrollCodeResource::shouldRegisterNavigation()),
 * Koefisien Harga (disembunyikan, lihat PriceRuleResource), Toko/Dealer.
 *
 * SEBELUMNYA item-item ini 1 tab top-nav "Lainnya" (LainnyaCluster) yang
 * digabung Master Data + Sistem, dibedakan cuma lewat sub-heading sidebar
 * ($navigationGroup='Master Data' di tiap resource). Sekarang jadi
 * dropdown top-nav sendiri, konsisten sama "Penjualan".
 */
class MasterDataCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Master Data';

    protected static ?string $navigationGroup = 'Lainnya';

    protected static ?string $clusterBreadcrumb = 'Master Data';

    // Sort terkecil di grup "Lainnya" — menjaga posisi top-nav "Lainnya"
    // tetap sama seperti LainnyaCluster lama (sort 60).
    protected static ?int $navigationSort = 60;
}
