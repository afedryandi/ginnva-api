<?php

namespace App\Filament\Resources\FinanceTransactionApprovalRequestResource\Pages;

use App\Filament\Resources\FinanceTransactionApprovalRequestResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Tab per status (gap audit Persetujuan Pengeluaran 2026-09-28) --
     * default "Menunggu" supaya approver langsung melihat yang perlu
     * diproses, bukan riwayat lama.
     */
    public function getTabs(): array
    {
        return [
            'menunggu' => Tab::make('Menunggu')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('status', ['pending_manager', 'pending_direksi'])),
            'disetujui' => Tab::make('Disetujui')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'approved')),
            'ditolak' => Tab::make('Ditolak / Dibatalkan')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('status', ['rejected', 'cancelled'])),
            'semua' => Tab::make('Semua'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'menunggu';
    }
}
