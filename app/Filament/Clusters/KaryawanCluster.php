<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class KaryawanCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Karyawan';

    protected static ?int $navigationSort = 30;
}
