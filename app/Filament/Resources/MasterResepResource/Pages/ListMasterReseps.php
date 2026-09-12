<?php

namespace App\Filament\Resources\MasterResepResource\Pages;

use App\Exports\MasterResepExport;
use App\Filament\Resources\MasterResepResource;
use App\Models\FilmProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListMasterReseps extends ListRecords
{
    protected static string $resource = MasterResepResource::class;

    protected static ?string $title = 'Master Resep';

    /**
     * "Ekspor Resep" (audit 2026-09-12, temuan pola standar) — ekspor
     * semua produk + bahan reseptnya, sama pola export lain.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new MasterResepExport,
                    'master-resep-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $products = FilmProduct::query()->with('recipeItems')->orderBy('name')->get();
                    $pdf = Pdf::loadView('pdf.master_resep', ['products' => $products])->setPaper('a4', 'portrait');
                    $filename = 'master-resep-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }
}
