<?php

namespace App\Filament\Resources\FeaturedProductResource\Pages;

use App\Filament\Resources\FeaturedProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFeaturedProducts extends ListRecords
{
    protected static string $resource = FeaturedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
