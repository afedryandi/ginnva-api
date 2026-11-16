<?php

namespace App\Filament\Resources\QcChecklistItemResource\Pages;

use App\Filament\Resources\QcChecklistItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListQcChecklistItems extends ListRecords
{
    protected static string $resource = QcChecklistItemResource::class;

    public function getSubheading(): ?string
    {
        return 'Butir yang diperiksa saat Quality Check tiap layanan. Seret baris lewat Urutkan Ulang untuk mengatur urutannya.';
    }

    // Tombol tambah butir tidak otomatis ada di halaman daftar Filament; sebelumnya hilang walau canCreate() dan halaman create sudah ada.
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Butir Periksa'),
        ];
    }
}
