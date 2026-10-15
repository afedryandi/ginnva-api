<?php

namespace App\Filament\Resources\ProductInquiryResource\Pages;

use App\Filament\Resources\ProductInquiryResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Catat lead manual (WhatsApp, telepon, datang langsung, DM) -- Marketing/Konten Fase 0.
 */
class CreateProductInquiry extends CreateRecord
{
    protected static string $resource = ProductInquiryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();
        $data['status'] = $data['status'] ?? 'new';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
