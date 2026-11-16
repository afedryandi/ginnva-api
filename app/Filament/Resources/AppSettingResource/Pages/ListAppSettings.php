<?php

namespace App\Filament\Resources\AppSettingResource\Pages;

use App\Filament\Resources\AppSettingResource;
use Filament\Resources\Pages\ListRecords;

class ListAppSettings extends ListRecords
{
    protected static string $resource = AppSettingResource::class;

    public function getSubheading(): ?string
    {
        return 'Teks yang dilihat pelanggan di aplikasi. Perubahan langsung terbaca tanpa rilis aplikasi.';
    }
}
