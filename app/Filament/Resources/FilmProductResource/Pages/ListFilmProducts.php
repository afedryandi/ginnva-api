<?php

namespace App\Filament\Resources\FilmProductResource\Pages;

use App\Filament\Resources\FilmProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFilmProducts extends ListRecords
{
    protected static string $resource = FilmProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}