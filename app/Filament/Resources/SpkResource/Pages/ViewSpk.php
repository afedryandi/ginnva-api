<?php

namespace App\Filament\Resources\SpkResource\Pages;

use App\Filament\Resources\SpkResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSpk extends ViewRecord
{
    protected static string $resource = SpkResource::class;

    /** Konteks di bawah judul: siapa, kendaraan apa, dan di toko mana. */
    public function getSubheading(): ?string
    {
        return collect([$this->record->customer_name, $this->record->vehicle_plate, $this->record->store?->name])
            ->filter()
            ->implode(' · ') ?: null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Alur Booking -> SPK -> Memo: memo dibuat dari SPK ini (nomor, kendaraan, dan toko terisi otomatis).
            Actions\Action::make('createMemo')
                ->label('Buat Memo Barang')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('gray')
                ->visible(fn () => \App\Filament\Resources\MaterialMemoResource::canCreate())
                ->url(fn () => \App\Filament\Resources\MaterialMemoResource::getUrl('create', ['spk' => $this->record->id])),

            Actions\Action::make('print')
                ->label(fn () => $this->record->checked_out_at ? 'Cetak Ulang (Arsip)' : 'Cetak PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(function () {
                    $record = $this->record->loadMissing(['checklistItems', 'damageMarks', 'store', 'booking']);
                    $reprint = $record->checked_out_at !== null;
                    $pdf = Pdf::loadView('pdf.spk', ['spk' => $record, 'isReprint' => $reprint])->setPaper('a4', 'portrait');
                    $filename = str_replace('/', '-', $record->spk_number) . ($reprint ? '-arsip' : '') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),

            Actions\EditAction::make(),
        ];
    }
}
