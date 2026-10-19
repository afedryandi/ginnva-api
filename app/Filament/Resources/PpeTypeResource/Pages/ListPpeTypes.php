<?php

namespace App\Filament\Resources\PpeTypeResource\Pages;

use App\Filament\Resources\PpeTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPpeTypes extends ListRecords
{
    protected static string $resource = PpeTypeResource::class;

    public function mount(): void
    {
        // Jenis bawaan dibuat bila belum ada (aman dipanggil berulang).
        \App\Models\PpeType::ensureDefaults();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
