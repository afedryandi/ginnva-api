<?php

namespace App\Filament\Resources\FeaturedProductResource\Pages;

use App\Filament\Resources\FeaturedProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFeaturedProduct extends EditRecord
{
    protected static string $resource = FeaturedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
