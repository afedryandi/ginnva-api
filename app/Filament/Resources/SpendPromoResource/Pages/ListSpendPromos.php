<?php

namespace App\Filament\Resources\SpendPromoResource\Pages;

use App\Exports\SpendPromoExport;
use App\Filament\Resources\SpendPromoResource;
use App\Models\SpendPromo;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListSpendPromos extends ListRecords
{
    protected static string $resource = SpendPromoResource::class;

    /**
     * "Ekspor Promo Total Pembelian" (audit 2026-09-14, temuan pola
     * standar).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new SpendPromoExport,
                    'promo-total-pembelian-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $promos = SpendPromo::query()->withCount('bookings')->orderByDesc('created_at')->get();
                    $pdf = Pdf::loadView('pdf.spend_promos', ['promos' => $promos])->setPaper('a4', 'landscape');
                    $filename = 'promo-total-pembelian-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
