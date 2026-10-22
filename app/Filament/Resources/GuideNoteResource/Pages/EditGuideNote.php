<?php

namespace App\Filament\Resources\GuideNoteResource\Pages;

use App\Filament\Resources\GuideNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGuideNote extends EditRecord
{
    protected static string $resource = GuideNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
