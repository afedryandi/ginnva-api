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

            // Lama pengerjaan baru diketahui setelah kondisi kendaraan diperiksa (keputusan 2026-11-27): atur dari SPK, tanpa membuka kunci
            // data inti booking. Pelanggan diberi tahu otomatis.
            Actions\Action::make('setDuration')
                ->label('Atur Lama Pengerjaan')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(function () {
                    $booking = $this->record->booking;

                    return $booking !== null && in_array($booking->status, ['pending', 'confirmed'], true) && ! $booking->isLightOnly();
                })
                ->modalDescription(fn () => 'Booking ' . $this->record->booking?->booking_number . ' saat ini ' . $this->record->booking?->effective_duration_days . ' hari kerja. Pelanggan menerima pemberitahuan estimasi selesai yang baru.')
                ->fillForm(fn () => ['duration_days' => (int) $this->record->booking?->effective_duration_days])
                ->form([
                    \Filament\Forms\Components\TextInput::make('duration_days')
                        ->label('Lama Pengerjaan (hari kerja)')
                        ->numeric()->integer()->required()
                        ->minValue(1)->maxValue(\App\Services\BookingDurationService::MAX_DAYS),
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Alasan (opsional, ikut dikirim ke pelanggan)')
                        ->rows(2)->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(\App\Services\BookingDurationService::class)->change($this->record->booking, (int) $data['duration_days'], $data['reason'] ?? null);
                    } catch (\RuntimeException $e) {
                        \Filament\Notifications\Notification::make()->title('Gagal mengubah lama pengerjaan')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    \Filament\Notifications\Notification::make()->title('Lama pengerjaan diubah')->body('Pelanggan diberi tahu.')->success()->send();
                    $this->record->refresh();
                }),

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
