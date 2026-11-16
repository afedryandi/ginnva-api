<?php

namespace App\Filament\Resources\StoreReviewResource\Pages;

use App\Filament\Resources\StoreReviewResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStoreReviews extends ListRecords
{
    protected static string $resource = StoreReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Tab per kebutuhan: Semua (bawaan), "Perlu ditindaklanjuti" (negatif yang belum ditangani, dengan jumlah merah), Kurang Puas, dan Puas.
     * Jumlah mengikuti pembatasan toko pengguna.
     */
    public function getTabs(): array
    {
        $base = fn () => StoreReviewResource::getEloquentQuery();

        return [
            'semua' => Tab::make('Semua'),
            'tindak' => Tab::make('Perlu ditindaklanjuti')
                ->badge($base()->where('sentiment', 'negative')->whereNull('followed_up_at')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sentiment', 'negative')->whereNull('followed_up_at')),
            'negatif' => Tab::make('Kurang Puas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sentiment', 'negative')),
            'puas' => Tab::make('Puas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sentiment', 'positive')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}
