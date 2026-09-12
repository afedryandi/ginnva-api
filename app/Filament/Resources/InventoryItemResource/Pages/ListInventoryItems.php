<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Exports\InventoryItemExport;
use App\Filament\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListInventoryItems extends ListRecords
{
    protected static string $resource = InventoryItemResource::class;

    /**
     * "Ekspor Produk PPF/WF" (audit 2026-09-12, temuan pola standar) —
     * beda dari "Export Kode Gulungan" (ScrollCode) yang sudah ada di
     * dropdown "Kode Gulungan"; ini daftar unit fisik (kardus).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new InventoryItemExport,
                    'produk-ppf-wf-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $items = InventoryItem::query()->with('scrollCode')->orderByDesc('created_at')->get();
                    $pdf = Pdf::loadView('pdf.inventory_items', ['items' => $items])->setPaper('a4', 'landscape');
                    $filename = 'produk-ppf-wf-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
