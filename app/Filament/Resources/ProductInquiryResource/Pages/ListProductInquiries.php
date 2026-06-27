<?php

namespace App\Filament\Resources\ProductInquiryResource\Pages;

use App\Filament\Resources\ProductInquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListProductInquiries extends ListRecords
{
    protected static string $resource = ProductInquiryResource::class;

    // Tidak ada CreateAction di sini — inquiry hanya masuk lewat
    // form publik (POST /api/inquiry/submit), bukan dibuat manual admin.
    protected function getHeaderActions(): array
    {
        return [];
    }
}