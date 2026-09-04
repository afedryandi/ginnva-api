<?php

namespace App\Filament\Resources\PartnerNotificationResource\Pages;

use App\Filament\Resources\PartnerNotificationResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnerNotifications extends ListRecords
{
    protected static string $resource = PartnerNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
