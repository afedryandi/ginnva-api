<?php

namespace App\Filament\Resources\GuideNoteResource\Pages;

use App\Filament\Resources\GuideNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGuideNotes extends ListRecords
{
    protected static string $resource = GuideNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Catatan'),
        ];
    }
}
