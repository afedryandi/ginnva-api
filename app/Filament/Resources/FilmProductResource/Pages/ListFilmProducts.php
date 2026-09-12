<?php

namespace App\Filament\Resources\FilmProductResource\Pages;

use App\Exports\FilmProductExport;
use App\Filament\Resources\FilmProductResource;
use App\Models\FilmProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListFilmProducts extends ListRecords
{
    protected static string $resource = FilmProductResource::class;

    /**
     * "Ekspor Katalog" (audit 2026-09-12, temuan pola standar B) —
     * ekspor semua produk (tidak ikut filter tabel aktif), sama pola
     * export laporan Penjualan lain.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new FilmProductExport,
                    'daftar-produk-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $products = FilmProduct::query()->with('prices')->orderBy('name')->get();
                    $pdf = Pdf::loadView('pdf.film_products', ['products' => $products])->setPaper('a4', 'landscape');
                    $filename = 'daftar-produk-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}