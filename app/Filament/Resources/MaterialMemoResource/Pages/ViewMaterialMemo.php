<?php

namespace App\Filament\Resources\MaterialMemoResource\Pages;

use App\Filament\Resources\MaterialMemoResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman Lihat: memo dan barangnya hanya dibaca. Mengubah memo dan mencatat barang butuh izin Ubah (MaterialMemoResource::canEdit()).
 *
 * Memo terkunci begitu booking-nya Selesai (MaterialMemo::isLocked()). Akses penuh bisa membuka kunci dengan alasan wajib (tercatat di
 * Histori Aktivitas) dan mengunci lagi sesudah koreksi.
 */
class ViewMaterialMemo extends ViewRecord
{
    protected static string $resource = MaterialMemoResource::class;

    public function getSubheading(): ?string
    {
        if ($this->record->isLocked()) {
            return 'Terkunci: booking-nya sudah Selesai, jadi memo dan barangnya tidak bisa diubah. Akses penuh bisa membuka kunci.';
        }

        if ($this->record->isUnlockedAfterCompletion()) {
            return 'Kunci dibuka oleh akses penuh' . ($this->record->unlock_reason ? ': ' . $this->record->unlock_reason : '.') . ' Kunci lagi setelah koreksi selesai.';
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('unlock')
                ->label('Buka Kunci')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn () => $this->record->isLocked() && (auth()->user()?->isFullAccess() ?? false))
                ->modalHeading('Buka kunci memo?')
                ->modalDescription('Pemakaian bahan di memo ini sudah dibukukan saat booking selesai. Gunakan hanya untuk koreksi, dan kunci lagi sesudahnya.')
                ->form([
                    Forms\Components\Textarea::make('reason')->label('Alasan')->required()->maxLength(500),
                ])
                ->action(function (array $data) {
                    $this->record->update(['unlocked_at' => now(), 'unlocked_by' => auth()->id(), 'unlock_reason' => $data['reason']]);

                    activity('material_memo')->performedOn($this->record)->causedBy(auth()->user())
                        ->withProperties(['alasan' => $data['reason']])
                        ->log('Kunci memo dibuka: ' . $data['reason']);

                    Notification::make()->title('Kunci memo dibuka.')->success()->send();
                    $this->record->refresh();
                }),

            Actions\Action::make('relock')
                ->label('Kunci Lagi')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->visible(fn () => $this->record->isUnlockedAfterCompletion() && (auth()->user()?->isFullAccess() ?? false))
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['unlocked_at' => null, 'unlocked_by' => null, 'unlock_reason' => null]);

                    activity('material_memo')->performedOn($this->record)->causedBy(auth()->user())->log('Memo dikunci lagi');

                    Notification::make()->title('Memo dikunci.')->success()->send();
                    $this->record->refresh();
                }),
        ];
    }
}
