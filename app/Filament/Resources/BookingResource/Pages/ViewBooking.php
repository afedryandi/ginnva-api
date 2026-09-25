<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\SpkResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Booking::spk() sudah ada di model sejak SPK dibangun
            // (2026-09-16), tapi TIDAK PERNAH ditautkan ke UI Booking --
            // sebelumnya cuma bisa dicari manual dari menu SPK sendiri
            // pakai nomor booking/nama customer. Tombol ini buka
            // langsung ke SPK terkait, kalau sudah ada.
            Actions\Action::make('lihatSpk')
                ->label('Lihat SPK')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn () => $this->record->spk !== null)
                ->url(fn () => $this->record->spk ? SpkResource::getUrl('edit', ['record' => $this->record->spk]) : null),

            Actions\EditAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ];
    }
}
