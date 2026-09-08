<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class InventarisCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Inventaris';

    protected static ?int $navigationSort = 40;
}
