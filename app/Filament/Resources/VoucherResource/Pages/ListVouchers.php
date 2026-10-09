<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Exports\VoucherExport;
use App\Filament\Resources\VoucherResource;
use App\Models\Voucher;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    /** Log ekspor, konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'voucher', 'format' => $format])
                ->log('Ekspor Voucher Promo (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ekspor Voucher" (audit 2026-09-14, temuan pola standar).
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
                        new VoucherExport,
                        'voucher-promo-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $vouchers = Voucher::query()->orderByDesc('created_at')->get();
                    $pdf = Pdf::loadView('pdf.vouchers', ['vouchers' => $vouchers])->setPaper('a4', 'landscape');
                    $filename = 'voucher-promo-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
