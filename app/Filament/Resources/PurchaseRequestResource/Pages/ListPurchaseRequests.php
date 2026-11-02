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
                ->tooltip('Mengekspor daftar sesuai filter dan pencarian yang sedang aktif')
                ->action(fn ($livewire) => Excel::download(
                    new PurchaseRequestExport(\App\Filament\Resources\PurchaseRequestResource::canSeeCost(), $livewire->getFilteredSortedTableQuery()),
                    'permohonan-pembelian-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->tooltip('Mengekspor daftar sesuai filter dan pencarian yang sedang aktif')
                ->action(function ($livewire) {
                    // Query tabel sudah dibatasi ke toko akun ini (PurchaseRequestResource::getEloquentQuery()) dan mengikuti filter/urutan layar.
                    $requests = $livewire->getFilteredSortedTableQuery()->with(['store', 'requester'])->get();

                    $pdf = Pdf::loadView('pdf.purchase_requests', ['requests' => $requests, 'showCost' => \App\Filament\Resources\PurchaseRequestResource::canSeeCost()])->setPaper('a4', 'landscape');
                    $filename = 'permohonan-pembelian-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\CreateAction::make(),
        ];
    }
}
