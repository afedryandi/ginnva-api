<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use RuntimeException;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    // Tombol Simpan dan Batal menempel di bawah layar saat form digulir.
    public static bool $formActionsAreSticky = true;

    /** Konteks di bawah judul: pelanggan dan toko. */
    public function getSubheading(): ?string
    {
        return collect([$this->record->customer_name, $this->record->store?->name])->filter()->implode(' · ') ?: null;
    }

    /** Aksi utama di kepala halaman, sejajar dengan judul: cetak, catat pembayaran, dan hapus (draf). */
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

            Actions\Action::make('record_payment')
                ->label('Catat Pembayaran')
                ->icon('heroicon-o-banknotes')
                ->color('info')
                ->visible(fn () => in_array($this->record->status, ['draft', 'unpaid'], true))
                ->form([
                    \Filament\Forms\Components\TextInput::make('amount')
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
                    $this->refreshFormData(['amount_paid', 'status']);
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Repeater "items" SENGAJA bukan ->relationship() bawaan Filament
     * (lihat InvoiceResource::form()) -- jadi WAJIB diisi manual di
     * sini dari relasi asli record, atau form muncul kosong saat edit.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = $this->record->items()->get()->map(fn ($item) => [
            'film_product_id' => $item->film_product_id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'price' => $item->price,
            'discount_percent' => $item->discount_percent,
            'note' => $item->note,
        ])->toArray();

        return $data;
    }

    protected function handleRecordUpdate($record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        try {
            return app(InvoiceService::class)->update($record, $data, $items);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Invoice tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
