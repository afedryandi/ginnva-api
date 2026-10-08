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
            Actions\DeleteAction::make()
                ->action(function (\App\Models\FilmProduct $record) {
                    if (! FilmProductResource::guardDelete($record)) {
                        return;
                    }

                    $record->delete();
                    \Filament\Notifications\Notification::make()->title('Produk dihapus')->success()->send();

                    return redirect(FilmProductResource::getUrl('index'));
                }),
        ];
    }
}