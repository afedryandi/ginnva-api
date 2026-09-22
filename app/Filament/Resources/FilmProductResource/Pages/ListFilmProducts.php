<?php

namespace App\Filament\Resources\FilmProductResource\Pages;

use App\Exports\FilmProductExport;
use App\Exports\FilmProductImportTemplateExport;
use App\Filament\Resources\FilmProductResource;
use App\Models\FilmProduct;
use App\Services\ProductBulkImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
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

            // "Import Harga Massal" (audit Majoo, f27: "Impor/Ekspor bulk
            // data produk + riwayat impor") — BEDA dari export di atas
            // (itu laporan lengkap read-only). Cuma UPDATE produk yang
            // sudah ada (match by SKU), tidak pernah membuat produk baru.
            // Lihat ProductBulkImportService untuk aturan lengkap.
            Actions\Action::make('downloadImportTemplate')
                ->label('Download Template Import')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->action(fn () => Excel::download(
                    new FilmProductImportTemplateExport,
                    'template-import-harga-produk-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Actions\Action::make('importPrices')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('File Excel')
                        ->required()
                        ->disk('local')
                        ->directory('product-imports')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->helperText('Pakai format dari "Download Template Import". Kolom kosong tidak mengubah data yang ada. SKU yang tidak ditemukan di katalog dilewati (import ini tidak membuat produk baru).'),
                ])
                ->action(function (array $data) {
                    $path = $data['file'];
                    $absolutePath = Storage::disk('local')->path($path);

                    try {
                        $log = app(ProductBulkImportService::class)->importFromFile(
                            $absolutePath,
                            basename($path),
                            auth()->id(),
                        );
                    } catch (\Throwable $e) {
                        Storage::disk('local')->delete($path);

                        Notification::make()
                            ->title('Gagal membaca file')
                            ->body('File tidak bisa dibaca sebagai Excel/CSV yang valid: ' . $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Storage::disk('local')->delete($path);

                    Notification::make()
                        ->title('Import selesai')
                        ->body("{$log->updated_count} produk diperbarui, {$log->skipped_count} baris dilewati dari {$log->total_rows} baris. Lihat detail di menu \"Riwayat Impor Produk\".")
                        ->success()
                        ->send();
                })
                ->modalSubmitActionLabel('Import'),

            Actions\CreateAction::make(),
        ];
    }
}