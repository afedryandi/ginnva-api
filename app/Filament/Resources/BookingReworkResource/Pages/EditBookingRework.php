<?php

namespace App\Filament\Resources\BookingReworkResource\Pages;

use App\Filament\Resources\BookingReworkResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBookingRework extends EditRecord
{
    protected static string $resource = BookingReworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
