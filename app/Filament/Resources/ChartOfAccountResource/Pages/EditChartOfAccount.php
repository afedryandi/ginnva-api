<?php

namespace App\Filament\Resources\ChartOfAccountResource\Pages;

use App\Filament\Resources\ChartOfAccountResource;
use App\Models\ChartOfAccount;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditChartOfAccount extends EditRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sama dengan aksi di tabel: akun sistem / yang masih dipakai
            // (jurnal, mutasi bank, kategori, aset, template, akun anak)
            // tidak bisa dihapus -- audit Bagan Akun 2026-09-28.
            Actions\DeleteAction::make()
                ->visible(fn () => ChartOfAccountResource::canDelete($this->record))
                ->before(function (Actions\DeleteAction $action) {
                    if ($reason = $this->record->deletionBlocker()) {
                        Notification::make()->title('Tidak bisa menghapus akun ini')->body($reason)->danger()->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['normal_balance'] = ChartOfAccount::normalBalanceFor($data['type']);

        return $data;
    }

    /**
     * Guard integritas di ChartOfAccount::booted() melempar RuntimeException
     * (mis. mengubah klasifikasi akun berjurnal, siklus induk) -- ditampilkan
     * sebagai notifikasi, bukan error 500.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (\RuntimeException $e) {
            Notification::make()->title('Tidak bisa menyimpan')->body($e->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
