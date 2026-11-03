<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Exports\SupplierExport;
use App\Filament\Resources\SupplierResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Mengekspor daftar sesuai filter dan pencarian yang sedang aktif')
                ->action(fn ($livewire) => Excel::download(
                    new SupplierExport(SupplierResource::canSeeBanking(), $livewire->getFilteredSortedTableQuery()),
                    'supplier-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\CreateAction::make(),
        ];
    }
}