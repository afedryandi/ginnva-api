<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

/**
 * Cluster "Sistem" — di bawah navigationGroup "Lainnya" (dibuat
 * 2026-09-14, lihat catatan lengkap di MasterDataCluster.php). Isi:
 * Histori Aktivitas (ActivityResource).
 */
class SistemCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Sistem';

    protected static ?string $navigationGroup = 'Lainnya';

    protected static ?string $clusterBreadcrumb = 'Sistem';

    protected static ?int $navigationSort = 62;
}
