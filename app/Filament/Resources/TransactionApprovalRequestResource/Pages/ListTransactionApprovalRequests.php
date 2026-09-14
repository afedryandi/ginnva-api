<?php

namespace App\Filament\Resources\TransactionApprovalRequestResource\Pages;

use App\Filament\Resources\TransactionApprovalRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListTransactionApprovalRequests extends ListRecords
{
    protected static string $resource = TransactionApprovalRequestResource::class;

    // Tidak ada Create -- permintaan cuma dibuat otomatis lewat
    // TransactionApprovalService::submitBookingReferral()/submitRefund().
    protected function getHeaderActions(): array
    {
        return [];
    }
}
