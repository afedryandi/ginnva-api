<?php

namespace App\Filament\Resources\CompanyPolicyResource\Pages;

use App\Filament\Resources\CompanyPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCompanyPolicy extends EditRecord
{
    protected static string $resource = CompanyPolicyResource::class;

    private bool $wasPublished = false;

    protected function beforeSave(): void
    {
        $this->wasPublished = (bool) $this->getRecord()->getOriginal('is_published');
    }

    protected function afterSave(): void
    {
        CompanyPolicyResource::afterPublish($this->getRecord(), (int) ($this->form->getRawState()['replaces'] ?? 0) ?: null, $this->wasPublished);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
