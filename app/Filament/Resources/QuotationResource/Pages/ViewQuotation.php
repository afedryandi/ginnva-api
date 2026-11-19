<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Resources\QuotationResource;
use App\Models\Quotation;
use App\Support\PhoneFormatter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        // Aksi yang paling sering dipakai saat membuka sebuah lead ada di kepala halaman, sejajar dengan judul: hubungi lewat WhatsApp dan ubah
        // status (sama dengan ikon di baris daftar), lalu Ubah dan Hapus.
        return [
            Actions\Action::make('whatsapp')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('success')
                ->visible(fn (Quotation $record) => filled($record->customer_phone))
                ->url(fn (Quotation $record) => 'https://wa.me/' . PhoneFormatter::toWhatsAppNumber($record->customer_phone))
                ->openUrlInNewTab(),

            Actions\Action::make('quickStatus')
                ->label('Ubah Status')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->form([
                    Forms\Components\Select::make('status')
                        ->label('Status Follow-up')
                        ->options([
                            'new'       => 'New',
                            'contacted' => 'Contacted',
                            'closed'    => 'Closed',
                            'cancelled' => 'Cancelled',
                        ])
                        ->required(),
                ])
                ->fillForm(fn (Quotation $record) => ['status' => $record->status])
                // Memicu event 'updating' yang sama dengan ubah status di daftar, jadi contacted_at tetap terisi otomatis (QuotationObserver).
                ->action(fn (Quotation $record, array $data) => $record->update(['status' => $data['status']])),

            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
