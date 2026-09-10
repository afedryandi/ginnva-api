<?php

namespace App\Filament\Resources\MasterResepResource\Pages;

use App\Filament\Resources\MasterResepResource;
use Filament\Resources\Pages\EditRecord;

class EditMasterResep extends EditRecord
{
    protected static string $resource = MasterResepResource::class;

    public function getTitle(): string
    {
        return 'Resep — '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        // Tidak ada Delete -- produk dihapus di "Produk Film", bukan di sini.
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
