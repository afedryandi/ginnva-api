<?php

namespace App\Filament\Resources\BookingReworkResource\Pages;

use App\Filament\Resources\BookingReworkResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBookingRework extends CreateRecord
{
    protected static string $resource = BookingReworkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reported_by'] = auth()->id();

        return $data;
    }
}
