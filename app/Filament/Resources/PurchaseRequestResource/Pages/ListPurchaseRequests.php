<?php

namespace App\Filament\Resources\PurchaseRequestResource\Pages;

use App\Exports\PurchaseRequestExport;
use App\Filament\Resources\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    /**
     * "Ekspor Permohonan Pembelian" (audit 2026-09-12, temuan pola
     * standar) — scope store sama dengan getEloquentQuery() resource
     * ini.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new PurchaseRequestExport,
                    'permohonan-pembelian-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $query = PurchaseRequest::query()->with(['store', 'requester']);
                    if (($user = auth()->user()) && ! $user->isFullAccess()) {
                        $query->where('store_id', $user->store_id);
                    }
                    $requests = $query->orderByDesc('created_at')->get();

                    $pdf = Pdf::loadView('pdf.purchase_requests', ['requests' => $requests])->setPaper('a4', 'landscape');
                    $filename = 'permohonan-pembelian-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
