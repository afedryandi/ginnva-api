<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class MasterDataCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Master Data';

    protected static ?int $navigationSort = 60;
}
