<?php

namespace App\Filament\Resources\ConsumableItemMovementResource\Pages;

use App\Filament\Resources\ConsumableItemMovementResource;
use App\Filament\Resources\ConsumableItemMovementResource\Widgets\ConsumableItemMovementStatsOverview;
use Filament\Resources\Pages\ListRecords;

class ListConsumableItemMovements extends ListRecords
{
    protected static string $resource = ConsumableItemMovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ConsumableItemMovementStatsOverview::class,
        ];
    }
}
