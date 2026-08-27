<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use Filament\Resources\Pages\CreateRecord;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        $data['recorded_by'] = auth()->id();

        return $data;
    }

    /**
     * updateOrCreate pada [user_id, date] (bukan create polos) — kalau
     * karyawan ini sudah punya baris hari itu (mis. sempat clock-in via
     * app sebelum device mati), entri manual admin MELENGKAPI baris yang
     * sama, bukan gagal kena unique constraint atau bikin duplikat.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return Attendance::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            $data
        );
    }
}
