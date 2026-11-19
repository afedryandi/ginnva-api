<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /** Konteks di bawah judul: pelanggan dan toko. */
    public function getSubheading(): ?string
    {
        return collect([$this->record->customer_name, $this->record->store?->name])->filter()->implode(' · ') ?: null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('Cetak PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(function () {
                    $record = $this->record->loadMissing(['items', 'store']);
                    $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $record])->setPaper('a4', 'portrait');

                    return response()->streamDownload(fn () => print($pdf->output()), str_replace('/', '-', $record->invoice_number) . '.pdf');
                }),

            // Aksi yang paling sering dipakai setelah melihat tagihan: mencatat pembayaran.
            Actions\Action::make('record_payment')
                ->label('Catat Pembayaran')
                ->icon('heroicon-o-banknotes')
                ->color('info')
                ->visible(fn () => in_array($this->record->status, ['draft', 'unpaid'], true))
                ->form([
                    TextInput::make('amount')
                        ->label('Nominal Dibayar')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->prefix('Rp')
                        ->helperText(fn () => 'Sisa tagihan saat ini: Rp' . number_format($this->record->remainingAmount(), 0, ',', '.')),
                ])
                ->action(function (array $data) {
                    try {
                        app(InvoiceService::class)->recordPayment($this->record, (float) $data['amount']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Pembayaran dicatat.')->success()->send();
                    $this->record->refresh();
                }),

            Actions\EditAction::make(),
        ];
    }
}
