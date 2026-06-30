<?php

namespace App\Filament\Resources\PartnershipInquiryResource\Pages;

use App\Filament\Resources\PartnershipInquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnershipInquiries extends ListRecords
{
    protected static string $resource = PartnershipInquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
