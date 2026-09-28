<?php

namespace App\Filament\Resources\FinanceCategoryResource\Pages;

use App\Filament\Resources\FinanceCategoryResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFinanceCategory extends CreateRecord
{
    protected static string $resource = FinanceCategoryResource::class;

    /**
     * Guard di FinanceCategory::booted() melempar RuntimeException (mis.
     * akun tidak valid untuk tipe) -- tampil sebagai notifikasi, bukan 500.
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
