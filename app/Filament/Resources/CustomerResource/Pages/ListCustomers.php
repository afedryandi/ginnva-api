<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    // Tidak ada CreateAction — akun customer hanya dibuat lewat OTP di
    // mobile app, bukan dari panel admin.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
