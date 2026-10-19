<?php

namespace App\Filament\Resources\PerformanceReviewResource\Pages;

use App\Filament\Resources\PerformanceReviewResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePerformanceReview extends CreateRecord
{
    protected static string $resource = PerformanceReviewResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reviewer_id'] = auth()->id();

        return $data;
    }
}
