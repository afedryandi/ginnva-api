<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListLeaveRequests extends ListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => LeaveRequestResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'menunggu' => Tab::make('Menunggu')
                ->badge($base()->where('status', 'pending')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'disetujui' => Tab::make('Disetujui')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'ditolak' => Tab::make('Ditolak')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}
