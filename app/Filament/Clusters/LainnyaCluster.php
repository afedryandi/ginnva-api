<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Gabungan Master Data + Sistem — diminta 2026-09-08 supaya top-nav
 * tidak terlalu lebar (ikon profil kepotong dengan 7 item). Isinya tetap
 * sama persis (Kendaraan, Kode Gulungan, Koefisien Harga, Produk Film,
 * Toko/Dealer, Histori Aktivitas, Kirim Notifikasi, Riwayat Notifikasi
 * (Customer & Partner)) — cuma nama & ikon top-nav-nya yang digabung
 * jadi 1 tab "Lainnya", sub-navigasi di sidebar kiri tetap menampilkan
 * semuanya sekaligus.
 */
class LainnyaCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-ellipsis-horizontal-circle';

    protected static ?string $navigationLabel = 'Lainnya';

    protected static ?int $navigationSort = 60;
}
