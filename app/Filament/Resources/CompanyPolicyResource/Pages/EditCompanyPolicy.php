<?php

namespace App\Filament\Resources\CompanyPolicyResource\Pages;

use App\Filament\Resources\CompanyPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCompanyPolicy extends EditRecord
{
    protected static string $resource = CompanyPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
