<?php

namespace App\Filament\Resources\ProductInquiryResource\Pages;

use App\Filament\Resources\ProductInquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListProductInquiries extends ListRecords
{
    protected static string $resource = ProductInquiryResource::class;

    // Lead dari formulir publik masuk lewat POST /api/inquiry/submit; lead dari WhatsApp/telepon/datang langsung dicatat staf lewat tombol ini.
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()->label('Catat Lead Manual'),
        ];
    }
}