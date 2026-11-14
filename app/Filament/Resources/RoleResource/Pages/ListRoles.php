<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    public function getSubheading(): ?string
    {
        return 'Jabatan hanya nama; akses menu diatur di menu User. Nama bergembok membawa wewenang khusus dan tidak bisa diubah.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
