<?php

namespace App\Filament\Resources\AssetResource\Pages;

use App\Filament\Resources\AssetResource;
use App\Models\Asset;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['asset_tag'] = Asset::generateAssetTag();
        $data['created_by'] = auth()->id();

        return $data;
    }
}
