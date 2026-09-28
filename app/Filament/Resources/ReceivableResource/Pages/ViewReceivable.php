<?php

namespace App\Filament\Resources\ReceivableResource\Pages;

use App\Filament\Resources\ReceivableResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail piutang: riwayat pelunasan (termasuk yang dibatalkan), nomor bukti & jurnal,
 * link ke booking asal. Isi infolist ada di ReceivableResource::infolist().
 */
class ViewReceivable extends ViewRecord
{
    protected static string $resource = ReceivableResource::class;

    public function getTitle(): string
    {
        return 'Piutang ' . $this->record->receivable_number;
    }
}
