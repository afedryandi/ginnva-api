<?php

namespace App\Filament\Resources\StoreReviewResource\Pages;

use App\Filament\Resources\StoreReviewResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStoreReview extends ViewRecord
{
    protected static string $resource = StoreReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
