<?php

namespace App\Filament\Resources\AssetResource\Pages;

use App\Exports\AssetExport;
use App\Filament\Resources\AssetResource;
use App\Models\Asset;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    /**
     * "Ekspor Aset" (audit 2026-09-12, temuan pola standar) — pakai
     * `visibleTo($user)` yang sama dengan getEloquentQuery() resource
     * ini, supaya staff non-full-access cuma dapat aset toko sendiri.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new AssetExport,
                    'aset-tetap-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $query = Asset::query()->with(['assignee', 'store']);
                    if ($user = auth()->user()) {
                        $query->visibleTo($user);
                    }
                    $assets = $query->orderByDesc('created_at')->get();

                    $pdf = Pdf::loadView('pdf.assets', ['assets' => $assets])->setPaper('a4', 'landscape');
                    $filename = 'aset-tetap-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
