<?php

namespace App\Filament\Resources\PayableResource\Pages;

use App\Filament\Resources\PayableResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail tagihan: riwayat pembayaran (termasuk yang dibatalkan), nomor jurnal,
 * sumber tagihan, lampiran. Isi infolist ada di PayableResource::infolist().
 */
class ViewPayable extends ViewRecord
{
    protected static string $resource = PayableResource::class;

    public function getTitle(): string
    {
        return 'Tagihan ' . $this->record->payable_number;
    }
}
