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
                ->action(fn () => Excel::download(
                    new VoucherExport,
                    'voucher-promo-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $vouchers = Voucher::query()->orderByDesc('created_at')->get();
                    $pdf = Pdf::loadView('pdf.vouchers', ['vouchers' => $vouchers])->setPaper('a4', 'landscape');
                    $filename = 'voucher-promo-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
