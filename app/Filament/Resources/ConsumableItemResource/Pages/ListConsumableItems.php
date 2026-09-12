<?php

namespace App\Filament\Resources\ConsumableItemResource\Pages;

use App\Exports\ConsumableItemExport;
use App\Filament\Resources\ConsumableItemResource;
use App\Models\ConsumableItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListConsumableItems extends ListRecords
{
    protected static string $resource = ConsumableItemResource::class;

    /**
     * "Ekspor Barang Habis Pakai" (audit 2026-09-12, temuan pola
     * standar) — beda dari "Download Template" (template kosong utk
     * import).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new ConsumableItemExport,
                    'barang-habis-pakai-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $items = ConsumableItem::query()->orderBy('name')->get();
                    $pdf = Pdf::loadView('pdf.consumable_items', ['items' => $items])->setPaper('a4', 'landscape');
                    $filename = 'barang-habis-pakai-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
