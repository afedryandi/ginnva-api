<?php

namespace App\Filament\Resources\ChecklistTemplateResource\Pages;

use App\Filament\Resources\ChecklistTemplateResource;
use App\Models\ChecklistTemplate;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChecklistTemplates extends ListRecords
{
    protected static string $resource = ChecklistTemplateResource::class;

    public function mount(): void
    {
        // Butir bawaan dibuat bila belum ada (aman dipanggil berulang).
        ChecklistTemplate::ensureDefaults();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
