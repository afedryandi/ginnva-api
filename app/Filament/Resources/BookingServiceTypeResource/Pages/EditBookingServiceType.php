<?php

namespace App\Filament\Resources\BookingServiceTypeResource\Pages;

use App\Filament\Resources\BookingServiceTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditBookingServiceType extends EditRecord
{
    protected static string $resource = BookingServiceTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
