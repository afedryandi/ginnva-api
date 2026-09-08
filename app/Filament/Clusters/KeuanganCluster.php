<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class KeuanganCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Keuangan';

    protected static ?int $navigationSort = 50;
}
