<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        $data['status'] = 'pending';

        return $data;
    }

    /**
     * Bug diperbaiki 2026-09-27 (audit ulang Izin & Cuti) -- ->rule()
     * closure di form (overlap/kuota) cuma dicek SEKALI saat validasi,
     * TIDAK dikunci ulang saat benar-benar disimpan. createLocked()
     * (lihat App\Models\LeaveRequest) recheck overlap/kuota DI DALAM
     * lock tepat sebelum create(), sama pola dengan yang sudah dipakai
     * mobile (Staff\AttendanceController::leaveRequestsStore()).
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return LeaveRequest::createLocked($data);
        } catch (\RuntimeException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Tidak bisa disimpan')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
