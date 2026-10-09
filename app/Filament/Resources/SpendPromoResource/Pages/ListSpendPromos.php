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

    /** Log ekspor, konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'spend_promo', 'format' => $format])
                ->log('Ekspor Promo Total Pembelian (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

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
                ->action(function () {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new SpendPromoExport,
                        'promo-total-pembelian-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $promos = SpendPromo::query()->withCount('bookings')->orderByDesc('created_at')->get();
                    $pdf = Pdf::loadView('pdf.spend_promos', ['promos' => $promos])->setPaper('a4', 'landscape');
                    $filename = 'promo-total-pembelian-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
