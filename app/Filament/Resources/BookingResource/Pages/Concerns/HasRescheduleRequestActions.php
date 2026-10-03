<?php

namespace App\Filament\Resources\BookingResource\Pages\Concerns;

use App\Filament\Resources\BookingResource;
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
