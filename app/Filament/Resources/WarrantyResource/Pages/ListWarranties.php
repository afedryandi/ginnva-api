<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWarranties extends ListRecords
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Tab per kebutuhan sehari-hari: Semua (bawaan), Menunggu review (QA), Aktif, Akan berakhir (30 hari), dan Berakhir. Aturannya sama dengan
     * filter Status Garansi dan Akan Berakhir di tabel, jadi angka di tab sama dengan hasil filternya. Jumlah mengikuti pembatasan toko.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn () => WarrantyResource::getEloquentQuery()->withoutEagerLoads();

        $pending = fn (Builder $q) => $q->where('review_status', 'pending_review');
        $active = fn (Builder $q) => $q->where('review_status', 'approved')->where('status', 'active')->whereDate('expiry_date', '>=', now());
        $soon = fn (Builder $q) => $q->where('review_status', 'approved')->whereDate('expiry_date', '>=', now())->whereDate('expiry_date', '<=', now()->addDays(30));
        $expired = fn (Builder $q) => $q->where('review_status', 'approved')->where('status', '!=', 'revoked')->whereDate('expiry_date', '<', now());

        return [
            'semua' => Tab::make('Semua'),
            'menunggu' => Tab::make('Menunggu review')
                ->badge($pending($base())->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $pending($query)),
            'aktif' => Tab::make('Aktif')
                ->badge($active($base())->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $active($query)),
            'segera' => Tab::make('Akan berakhir')
                ->badge($soon($base())->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $soon($query)),
            'berakhir' => Tab::make('Berakhir')
                ->modifyQueryUsing(fn (Builder $query) => $expired($query)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}
