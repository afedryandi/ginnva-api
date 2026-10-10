<?php

namespace App\Filament\Resources\RewardResource\Pages;

use App\Filament\Resources\RewardResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\QueryException;

class EditReward extends EditRecord
{
    protected static string $resource = RewardResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['type'] ?? $this->record->type) !== 'voucher') {
            $data['voucher_discount'] = null;
            $data['voucher_valid_days'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Sama dengan tombol Hapus di daftar: reward yang punya riwayat penukaran ditolak database (restrictOnDelete);
            // tanpa penangkapan ini staf melihat error 500 mentah dari halaman ubah.
            Actions\DeleteAction::make()
                ->action(function () {
                    try {
                        $this->record->delete();
                    } catch (QueryException $e) {
                        Notification::make()
                            ->title('Tidak bisa menghapus reward ini')
                            ->body('Reward ini masih punya riwayat penukaran. Nonaktifkan saja lewat toggle "Aktif" supaya tidak bisa ditukar lagi, tanpa menghapus riwayatnya.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()->title('Reward dihapus')->success()->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
