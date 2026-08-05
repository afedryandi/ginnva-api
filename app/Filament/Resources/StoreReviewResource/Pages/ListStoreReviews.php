<?php

namespace App\Filament\Resources\StoreReviewResource\Pages;

use App\Filament\Resources\StoreReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListStoreReviews extends ListRecords
{
    protected static string $resource = StoreReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
