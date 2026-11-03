<?php

namespace App\Filament\Resources\RawMaterialMovementResource\Pages;

use App\Filament\Resources\RawMaterialMovementResource;
use Filament\Resources\Pages\ViewRecord;

/** Detail satu pergerakan bahan baku: bahan, jumlah, harga (sesuai izin), toko, dokumen sumber, dan keterangan pembatalan. */
class ViewRawMaterialMovement extends ViewRecord
{
    protected static string $resource = RawMaterialMovementResource::class;
}