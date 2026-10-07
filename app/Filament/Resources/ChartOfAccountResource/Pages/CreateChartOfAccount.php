<?php

namespace App\Filament\Resources\ChartOfAccountResource\Pages;

use App\Filament\Resources\ChartOfAccountResource;
use App\Models\ChartOfAccount;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateChartOfAccount extends CreateRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // normal_balance BUKAN field form terpisah — selalu diturunkan
        // dari 'type' di sini, supaya tidak mungkin ada akun dengan
        // klasifikasi & saldo normal yang tidak konsisten.
        $data['normal_balance'] = ChartOfAccount::normalBalanceFor($data['type']);

        return $data;
    }

    /**
     * Aturan model (mis. induk harus akun header berklasifikasi sama) melempar RuntimeException;
     * halaman Edit sudah menangkapnya, di sini sebelumnya jadi error 500 mentah.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (\RuntimeException $e) {
            Notification::make()->title('Tidak bisa menyimpan')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }
}
