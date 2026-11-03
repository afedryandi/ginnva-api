<?php

namespace App\Filament\Resources\StockWriteOffResource\Pages;

use App\Filament\Resources\StockWriteOffResource;
use Filament\Resources\Pages\ViewRecord;

/** Detail satu Stok Terbuang: barang, jumlah, nilai (sesuai izin), jurnal, pencatat, dan keterangan pembatalan. */
class ViewStockWriteOff extends ViewRecord
{
    protected static string $resource = StockWriteOffResource::class;
}