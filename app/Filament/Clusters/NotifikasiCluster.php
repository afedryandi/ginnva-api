<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Notifikasi" — di bawah navigationGroup "Lainnya" (dibuat
 * 2026-09-14, lihat catatan lengkap di MasterDataCluster.php). Isi:
 * Kirim Notifikasi (SendNotification), Riwayat Notifikasi Customer &
 * Partner.
 */
class NotifikasiCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Notifikasi';

    protected static ?string $navigationGroup = 'Lainnya';

    protected static ?string $clusterBreadcrumb = 'Notifikasi';

    protected static ?int $navigationSort = 61;
}
