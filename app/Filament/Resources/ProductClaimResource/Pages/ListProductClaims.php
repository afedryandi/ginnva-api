<?php

namespace App\Filament\Resources\ProductClaimResource\Pages;

use App\Filament\Resources\ProductClaimResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductClaims extends ListRecords
{
    protected static string $resource = ProductClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
