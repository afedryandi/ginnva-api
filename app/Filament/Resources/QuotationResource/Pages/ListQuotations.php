<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Resources\QuotationResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Status Follow-up sebagai tab di atas daftar (menggantikan filter Status): satu klik untuk melihat lead New yang menunggu, dengan
     * jumlah di tiap tab. Jumlah mengikuti pembatasan toko pengguna yang sama dengan daftar.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $count = fn (string $status): int => QuotationResource::getEloquentQuery()->where('quotations.status', $status)->count();

        $tabs = ['all' => Tab::make('Semua')];

        foreach ([
            'new'       => ['New', 'info'],
            'contacted' => ['Contacted', 'warning'],
            'closed'    => ['Closed', 'success'],
            'cancelled' => ['Cancelled', 'danger'],
        ] as $status => [$label, $color]) {
            $tabs[$status] = Tab::make($label)
                ->badge($count($status))
                ->badgeColor($color)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('quotations.status', $status));
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
