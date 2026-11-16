<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Status sebagai tab di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna). "Perlu ditagih" (draf dan belum lunas)
     * jadi tab bawaan karena itulah yang dikerjakan sehari-hari; yang lunas dan di-void ada di tab sendiri.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn () => InvoiceResource::getEloquentQuery();

        return [
            'ditagih' => Tab::make('Perlu ditagih')
                ->badge($base()->whereIn('status', ['draft', 'unpaid'])->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['draft', 'unpaid'])),
            'lunas' => Tab::make('Lunas')
                ->badge($base()->where('status', 'paid')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid')),
            'void' => Tab::make('Void')
                ->badge($base()->where('status', 'void')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'void')),
            'semua' => Tab::make('Semua'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'ditagih';
    }
}
