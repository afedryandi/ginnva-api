<?php

namespace App\Filament\Resources\FinanceTransactionApprovalRequestResource\Pages;

use App\Filament\Resources\FinanceTransactionApprovalRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListFinanceTransactionApprovalRequests extends ListRecords
{
    protected static string $resource = FinanceTransactionApprovalRequestResource::class;

    // Tidak ada Create -- pengajuan cuma dibuat otomatis lewat
    // CreateFinanceTransaction saat staff non-full-access catat
    // pengeluaran (type='out').
    protected function getHeaderActions(): array
    {
        return [];
    }
}
