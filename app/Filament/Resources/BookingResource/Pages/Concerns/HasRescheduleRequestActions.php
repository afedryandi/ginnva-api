<?php

namespace App\Filament\Resources\BookingResource\Pages\Concerns;

use App\Filament\Resources\BookingResource;
use App\Services\BookingCancellationService;
use App\Services\BookingRescheduleService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Aksi Setujui/Tolak pengajuan jadwal ulang customer -- dipakai bersama oleh
 * halaman View dan Edit booking (2026-10-03: sebelumnya hanya View).
 */
trait HasRescheduleRequestActions
{
    /**
     * Setujui/Tolak pengajuan PEMBATALAN customer (booking confirmed,
     * 2026-10-05) -- hanya Store Manager / akses penuh, sama dengan aksi Batalkan.
     */
    protected function cancellationRequestActions(): array
    {
        $allowed = fn () => $this->record->pendingCancellationRequest !== null
            && (auth()->user()?->isFullAccess() || auth()->user()?->isStoreManager());

        return [
            Actions\Action::make('approveCancellation')
                ->label('Setujui Pembatalan')
                ->icon('heroicon-o-check')
                ->color('danger')
                ->visible($allowed)
                ->requiresConfirmation()
                ->modalHeading('Setujui pembatalan booking?')
                ->modalDescription(fn () => 'Alasan customer: ' . $this->record->pendingCancellationRequest?->reason . ' — booking akan dibatalkan dan DP (kalau ada) dikembalikan penuh.')
                ->action(function () {
                    try {
                        app(BookingCancellationService::class)->approve($this->record->pendingCancellationRequest, auth()->id(), null);
                        Notification::make()->title('Booking dibatalkan.')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                    }
                    $this->record->refresh();
                }),

            Actions\Action::make('rejectCancellation')
                ->label('Tolak Pembatalan')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->visible($allowed)
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('Alasan penolakan (dikirim ke customer)')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(BookingCancellationService::class)->reject($this->record->pendingCancellationRequest, auth()->id(), $data['note']);
                        Notification::make()->title('Pengajuan pembatalan ditolak.')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                    }
                    $this->record->refresh();
                }),
        ];
    }

    protected function rescheduleRequestActions(): array
    {
        return [
            Actions\Action::make('approveReschedule')
                ->label(fn () => 'Setujui Jadwal Ulang (' . $this->record->pendingRescheduleRequest?->requested_date?->format('d M Y') . ')')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn () => $this->record->pendingRescheduleRequest !== null && BookingResource::canEdit($this->record))
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->pendingRescheduleRequest?->reason
                    ? 'Alasan customer: ' . $this->record->pendingRescheduleRequest->reason
                    : null)
                ->action(function () {
                    try {
                        app(BookingRescheduleService::class)->approve($this->record->pendingRescheduleRequest, auth()->id(), null);
                        Notification::make()->title('Jadwal ulang disetujui.')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                    }
                    $this->record->refresh();
                }),

            Actions\Action::make('rejectReschedule')
                ->label('Tolak Jadwal Ulang')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->pendingRescheduleRequest !== null && BookingResource::canEdit($this->record))
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('Alasan penolakan (dikirim ke customer)')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(BookingRescheduleService::class)->reject($this->record->pendingRescheduleRequest, auth()->id(), $data['note']);
                        Notification::make()->title('Pengajuan ditolak.')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                    }
                    $this->record->refresh();
                }),
        ];
    }
}
