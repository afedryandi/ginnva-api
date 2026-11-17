<?php

namespace App\Filament\Resources\ProductInquiryResource\Pages;

use App\Filament\Resources\ProductInquiryResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProductInquiries extends ListRecords
{
    protected static string $resource = ProductInquiryResource::class;

    // Lead dari formulir publik masuk lewat POST /api/inquiry/submit; lead dari WhatsApp/telepon/datang langsung dicatat staf lewat tombol ini.
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()->label('Catat Lead Manual'),
        ];
    }

    /** Tab status di atas daftar, dengan jumlah di tab (mengikuti pembatasan toko pengguna); "Semua" bawaan supaya tidak ada baris tersembunyi. */
    public function getTabs(): array
    {
        $base = fn () => ProductInquiryResource::getEloquentQuery()->withoutEagerLoads();

        return [
            'semua' => Tab::make('Semua'),
            'baru' => Tab::make('Baru')
                ->badge($base()->where('status', 'new')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'new')),
            'dihubungi' => Tab::make('Sudah Dihubungi')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'contacted')),
            'selesai' => Tab::make('Selesai')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}
