<?php

namespace App\Filament\Resources\WorkScheduleResource\Pages;

use App\Filament\Resources\WorkScheduleResource;
use App\Services\PushNotificationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditWorkSchedule extends EditRecord
{
    protected static string $resource = WorkScheduleResource::class;

    private mixed $daysBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => WorkScheduleResource::canDelete($this->record)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->daysBeforeSave = $this->record->days;

        // Toko jadwal tidak boleh berubah setelah dibuat (lihat catatan di form: disabled saja tidak
        // cukup). Jaring pengaman kedua selain ->dehydrated(false) di field-nya.
        unset($data['store_id']);

        return $data;
    }

    /**
     * Audit Daftar Jadwal Kerja 2026-09-28: mengubah pola `days` template
     * yang sudah dipakai berlaku LANGSUNG untuk semua karyawan yang
     * ditugaskan, dan laporan pola periode lama ikut dihitung ulang
     * (late_minutes yang sudah tersimpan tidak berubah). Tidak diblokir
     * (koreksi pola memang perlu), tapi admin diberi tahu jumlah
     * karyawan terdampak dan karyawan itu dapat push.
     */
    protected function afterSave(): void
    {
        if ($this->daysBeforeSave == $this->record->days) {
            return;
        }

        $userIds = $this->record->activeAssigneeIds();
        if (! $userIds) {
            return;
        }

        Notification::make()
            ->title('Pola jadwal diubah')
            ->body(count($userIds) . ' karyawan memakai jadwal ini dan langsung terdampak. Laporan pola absensi periode lama akan dihitung ulang dengan pola baru; hitungan telat pada absensi yang sudah ada TIDAK berubah.')
            ->warning()
            ->persistent()
            ->send();

        app(PushNotificationService::class)->sendToUsers(
            $userIds,
            'Jadwal Kerja Berubah',
            "Pola jadwal kerja \"{$this->record->name}\" diperbarui. Perhatikan jadwal Anda."
        );
    }
}
