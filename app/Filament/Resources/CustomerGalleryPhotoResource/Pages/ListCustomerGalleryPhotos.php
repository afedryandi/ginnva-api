<?php

namespace App\Filament\Resources\CustomerGalleryPhotoResource\Pages;

use App\Filament\Resources\CustomerGalleryPhotoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomerGalleryPhotos extends ListRecords
{
    protected static string $resource = CustomerGalleryPhotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
