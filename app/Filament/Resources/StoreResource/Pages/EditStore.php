<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sama seperti tabel — hapus toko cascade ke seluruh booking,
            // teknisi, dan review-nya. Cuma super_admin/direksi.
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess()),
        ];
    }
}
