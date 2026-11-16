<?php

namespace App\Filament\Resources\SpkResource\Pages;

use App\Filament\Resources\SpkResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSpks extends ListRecords
{
    protected static string $resource = SpkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah SPK'),
        ];
    }

    /**
     * Status sebagai tab di atas daftar: "Dikerjakan" bawaan (kendaraan belum keluar), lalu Selesai dan Semua, dengan jumlah di tab
     * (mengikuti pembatasan toko pengguna).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn () => SpkResource::getEloquentQuery();

        return [
            'dikerjakan' => Tab::make('Dikerjakan')
                ->badge($base()->whereNull('checked_out_at')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('checked_out_at')),
            'selesai' => Tab::make('Selesai')
                ->badge($base()->whereNotNull('checked_out_at')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('checked_out_at')),
            'semua' => Tab::make('Semua'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'dikerjakan';
    }
}
