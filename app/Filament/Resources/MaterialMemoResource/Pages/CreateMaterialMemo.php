<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use App\Models\MaterialMemo;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterialMemo extends CreateRecord
{
    protected static string $resource = MaterialMemoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['memo_number'] = MaterialMemo::generateMemoNumber();

        return $data;
    }
}
