<?php

namespace App\Filament\Resources\ConsumableItemMovementResource\Pages;

use App\Filament\Resources\ConsumableItemMovementResource;
use Filament\Resources\Pages\ViewRecord;

/** Detail satu pergerakan barang habis pakai: barang, jumlah, harga (sesuai izin), toko, dokumen sumber, dan keterangan pembatalan. */
class ViewConsumableItemMovement extends ViewRecord
{
    protected static string $resource = ConsumableItemMovementResource::class;
}