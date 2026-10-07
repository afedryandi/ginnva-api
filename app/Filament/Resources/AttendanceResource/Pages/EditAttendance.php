<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAttendance extends EditRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Baris 'clock' (absen asli via GPS) TIDAK BISA dihapus sama
            // sekali — sama aturan dengan DeleteAction di tabel listing
            // (AttendanceResource::table()), lihat catatan di sana.
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess()
                    && $this->record->entry_type !== 'clock'),
        ];
    }

    protected function afterSave(): void
    {
        // Jam diubah atasan -> hitung ulang telat/pulang cepat (lihat Attendance::recalculateTiming()).
        $this->record->recalculateTiming();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        $data['recorded_by'] = auth()->id();

        return $data;
    }
}
