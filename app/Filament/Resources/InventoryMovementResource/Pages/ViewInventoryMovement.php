<?php

namespace App\Filament\Resources\InventoryMovementResource\Pages;

use App\Filament\Resources\InventoryMovementResource;
use Filament\Resources\Pages\ViewRecord;

/** Detail satu pergerakan: waktu, jenis, pelaku dan tokonya, barang beserta gulungannya, dan keterangan pembatalan bila ada. */
class ViewInventoryMovement extends ViewRecord
{
    protected static string $resource = InventoryMovementResource::class;
}