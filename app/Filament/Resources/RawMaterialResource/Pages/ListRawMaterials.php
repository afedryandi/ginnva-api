<?php

namespace App\Filament\Resources\RawMaterialResource\Pages;

use App\Exports\RawMaterialExport;
use App\Filament\Resources\RawMaterialResource;
use App\Models\RawMaterial;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListRawMaterials extends ListRecords
{
    protected static string $resource = RawMaterialResource::class;

    /**
     * "Ekspor Bahan Baku" (audit 2026-09-12, temuan pola standar) —
     * beda dari "Download Template" (template kosong utk import).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new RawMaterialExport,
                    'daftar-bahan-baku-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $materials = RawMaterial::query()->orderBy('name')->get();
                    $pdf = Pdf::loadView('pdf.raw_materials', ['materials' => $materials])->setPaper('a4', 'landscape');
                    $filename = 'daftar-bahan-baku-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
