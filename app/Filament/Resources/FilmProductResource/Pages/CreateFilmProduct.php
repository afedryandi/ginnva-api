<?php

namespace App\Filament\Resources\FilmProductResource\Pages;

use App\Filament\Resources\FilmProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFilmProduct extends CreateRecord
{
    protected static string $resource = FilmProductResource::class;

    // Tombol simpan dan batal menempel di bawah layar saat form digulir.
    public static bool $formActionsAreSticky = true;

    /** Lebar penuh supaya tata letak 2:1 (data dan harga di kiri, pengaturan di kanan) muncul. */
    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth|string|null
    {
        return \Filament\Support\Enums\MaxWidth::SevenExtraLarge;
    }
}