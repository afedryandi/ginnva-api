<?php

namespace App\Filament\Resources\BookingServiceTypeResource\Pages;

use App\Filament\Resources\BookingServiceTypeResource;
use Filament\Resources\Pages\ListRecords;

class ListBookingServiceTypes extends ListRecords
{
    protected static string $resource = BookingServiceTypeResource::class;

    public function getSubheading(): ?string
    {
        return 'Empat layanan yang bisa dipesan pelanggan di aplikasi. Perubahan langsung terbaca aplikasi.';
    }
}
