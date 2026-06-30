<?php

namespace App\Filament\Resources\FilmProductResource\Pages;

use App\Filament\Resources\FilmProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFilmProduct extends EditRecord
{
    protected static string $resource = FilmProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}