<?php

namespace App\Filament\Resources\CompanyPolicyResource\Pages;

use App\Filament\Resources\CompanyPolicyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompanyPolicy extends CreateRecord
{
    protected static string $resource = CompanyPolicyResource::class;

    protected function afterCreate(): void
    {
        CompanyPolicyResource::afterPublish($this->getRecord(), (int) ($this->form->getRawState()['replaces'] ?? 0) ?: null, false);
    }
}
