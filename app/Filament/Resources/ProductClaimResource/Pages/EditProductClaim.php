<?php

namespace App\Filament\Resources\ProductClaimResource\Pages;

use App\Filament\Resources\ProductClaimResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductClaim extends EditRecord
{
    protected static string $resource = ProductClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
