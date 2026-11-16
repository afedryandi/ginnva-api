<?php

namespace App\Filament\Resources\BookingReworkResource\Pages;

use App\Filament\Resources\BookingReworkResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListBookingReworks extends ListRecords
{
    protected static string $resource = BookingReworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** Tab "Berjalan" (belum selesai dikerjakan ulang) jadi bawaan, lalu Selesai dan Semua, dengan jumlah di tab (mengikuti pembatasan toko). */
    public function getTabs(): array
    {
        $base = fn () => BookingReworkResource::getEloquentQuery();

        return [
            'berjalan' => Tab::make('Berjalan')
                ->badge($base()->whereNull('resolved_on')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('resolved_on')),
            'selesai' => Tab::make('Selesai')
                ->badge($base()->whereNotNull('resolved_on')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('resolved_on')),
            'semua' => Tab::make('Semua'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'berjalan';
    }
}
