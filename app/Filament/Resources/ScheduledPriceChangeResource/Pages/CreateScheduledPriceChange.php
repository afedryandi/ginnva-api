<?php

namespace App\Filament\Resources\ScheduledPriceChangeResource\Pages;

use App\Filament\Resources\ScheduledPriceChangeResource;
use App\Models\ScheduledPriceChange;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduledPriceChange extends CreateRecord
{
    protected static string $resource = ScheduledPriceChangeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['status'] = ScheduledPriceChange::STATUS_SCHEDULED;
        // Pilihan produk kosong disimpan sebagai null (= semua produk aktif), bukan array kosong.
        $data['product_ids'] = empty($data['product_ids']) ? null : array_values($data['product_ids']);

        return $data;
    }
}
