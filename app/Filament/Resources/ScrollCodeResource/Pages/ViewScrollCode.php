<?php

namespace App\Filament\Resources\ScrollCodeResource\Pages;

use App\Filament\Resources\ScrollCodeResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman baru — sebelumnya ScrollCodeResource cuma punya halaman List,
 * jadi Riwayat Pemakaian (UsagesRelationManager) tidak ada tempat untuk
 * ditampilkan. Bukan EditRecord karena form()-nya sengaja semua field
 * disabled (data di sini cuma bisa diubah lewat action-action khusus,
 * bukan form edit biasa).
 */
class ViewScrollCode extends ViewRecord
{
    protected static string $resource = ScrollCodeResource::class;
}