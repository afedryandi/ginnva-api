<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditShift extends EditRecord
{
    protected static string $resource = ShiftResource::class;

    private array $hoursBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ShiftResource::canDelete($this->record)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->hoursBeforeSave = $this->record->only(['start_time', 'end_time', 'break_start_time', 'break_end_time']);

        return $data;
    }

    /**
     * Audit Daftar Shift 2026-09-28: telat/pulang-cepat di Attendance &
     * payroll adalah snapshot saat absen dicatat (aman), TAPI laporan
     * pola absensi & kalender jadwal menghitung ulang dari jam Shift
     * yang berlaku sekarang -- mengubah jam shift yang sudah dipakai
     * bikin laporan periode lama tidak cocok lagi dengan potongan gaji
     * yang sudah dibayar. Bukan diblokir (koreksi jam memang perlu),
     * tapi admin diberi tahu dampaknya.
     */
    protected function afterSave(): void
    {
        $after = $this->record->only(['start_time', 'end_time', 'break_start_time', 'break_end_time']);
        if ($after == $this->hoursBeforeSave) {
            return;
        }

        $usage = $this->record->usageSummary();
        if ($usage['schedules'] === 0 && $usage['overrides'] === 0) {
            return;
        }

        Notification::make()
            ->title('Jam shift diubah')
            ->body("Shift ini dipakai {$usage['schedules']} Jadwal Kerja dan {$usage['overrides']} override harian. Laporan pola absensi & kalender periode lama akan dihitung ulang dengan jam baru, sehingga bisa berbeda dari potongan telat yang sudah tercatat/dibayar. Hitungan telat pada absensi yang sudah ada TIDAK berubah.")
            ->warning()
            ->persistent()
            ->send();

        $userIds = $this->record->affectedUserIds();
        if ($userIds) {
            $fmt = fn ($t) => \Illuminate\Support\Carbon::parse($t)->format('H:i');
            app(\App\Services\PushNotificationService::class)->sendToUsers(
                $userIds,
                'Jam Shift Berubah',
                "Jam shift \"{$this->record->name}\" kini {$fmt($this->record->start_time)} - {$fmt($this->record->end_time)}. Perhatikan jadwal kerja Anda."
            );
        }
    }
}
