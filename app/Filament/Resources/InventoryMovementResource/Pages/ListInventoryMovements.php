<?php

namespace App\Filament\Resources\InventoryMovementResource\Pages;

use App\Filament\Resources\InventoryMovementResource;
use App\Filament\Resources\InventoryMovementResource\Widgets\InventoryMovementStatsOverview;
use Filament\Resources\Pages\ListRecords;

class ListInventoryMovements extends ListRecords
{
    protected static string $resource = InventoryMovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryMovementStatsOverview::class,
        ];
    }
}
