<?php

namespace App\Filament\Resources\FilmProductResource\Pages;

use App\Filament\Resources\FilmProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFilmProduct extends ViewRecord
{
    protected static string $resource = FilmProductResource::class;

    /** SKU di bawah judul, supaya produk langsung dikenali. */
    public function getSubheading(): ?string
    {
        return $this->record->sku ? 'SKU ' . $this->record->sku : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
