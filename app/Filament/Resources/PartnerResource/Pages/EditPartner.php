<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Models\Partner;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditPartner extends EditRecord
{
    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->modalDescription(fn (Partner $record) => $record->hasHistory()
                    ? 'Partner ini sudah punya riwayat poin dan/atau booking referral — tidak bisa dihapus permanen. Gunakan "Nonaktifkan" (ubah Status) untuk mencabut akses tanpa kehilangan riwayat.'
                    : 'Yakin hapus partner ini? Tindakan ini tidak bisa dibatalkan.'),
        ];
    }

    // Email bukan kolom di tabel partners — ambil dari relasi user supaya
    // muncul terisi saat form edit dibuka.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Partner $record */
        $record = $this->record;
        $data['email'] = $record->user?->email;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Partner $record */
        $record = $this->record;

        $userUpdate = ['name' => $data['business_name']];
        if (! empty($data['password'])) {
            $userUpdate['password'] = Hash::make($data['password']);
        }
        $record->user?->update($userUpdate);

        // email/password/passwordConfirmation bukan kolom Partner — buang
        // supaya tidak error saat $record->update($data) dijalankan Filament.
        unset($data['email'], $data['password'], $data['passwordConfirmation']);

        return $data;
    }
}
