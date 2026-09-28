<?php

namespace App\Filament\Resources\TransactionApprovalRequestResource\Pages;

use App\Filament\Resources\TransactionApprovalRequestResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTransactionApprovalRequests extends ListRecords
{
    protected static string $resource = TransactionApprovalRequestResource::class;

    // Tidak ada Create -- permintaan cuma dibuat otomatis lewat
    // TransactionApprovalService::submitBookingReferral()/submitRefund().
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Tab per status (default "Menunggu"). PENTING: parameter closure HARUS
     * bernama $query -- Filament menyuntikkan query berdasarkan nama
     * parameter; nama lain membuat Builder kosong tanpa model.
     */
    public function getTabs(): array
    {
        return [
            'menunggu' => Tab::make('Menunggu')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'disetujui' => Tab::make('Disetujui')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'ditolak' => Tab::make('Ditolak / Dibatalkan')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['rejected', 'cancelled'])),
            'semua' => Tab::make('Semua'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'menunggu';
    }
}
