<?php

namespace App\Filament\Resources\RawMaterialMovementResource\Pages;

use App\Filament\Resources\RawMaterialMovementResource;
use App\Filament\Resources\RawMaterialMovementResource\Widgets\RawMaterialMovementStatsOverview;
use Filament\Resources\Pages\ListRecords;

class ListRawMaterialMovements extends ListRecords
{
    protected static string $resource = RawMaterialMovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            RawMaterialMovementStatsOverview::class,
        ];
    }
}