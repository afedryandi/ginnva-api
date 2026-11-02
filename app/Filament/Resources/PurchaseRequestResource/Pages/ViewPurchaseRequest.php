<?php

namespace App\Filament\Resources\PurchaseRequestResource\Pages;

use App\Filament\Resources\PurchaseRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman Lihat: seluruh isi permohonan, termasuk yang sudah diproses (alasan, catatan peninjau, jumlah disetujui dan diterima).
 * Tombol Ubah hanya selagi menunggu dan bagi yang boleh mengubah (PurchaseRequestResource::canEdit()).
 */
class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
