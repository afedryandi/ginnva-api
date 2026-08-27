<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLeaveRequest extends EditRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => auth()->user()?->isFullAccess() && $this->record->status === 'pending'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! auth()->user()?->isFullAccess()) {
            $data['store_id'] = auth()->user()->store_id;
        }

        // Status/reviewer terkunci di form Edit biasa, sama pola dengan
        // PurchaseRequest — cuma bisa berubah lewat tombol Setujui/Tolak.
        $data['status'] = $this->record->status;
        $data['reviewed_by'] = $this->record->reviewed_by;
        $data['reviewed_at'] = $this->record->reviewed_at;
        $data['review_note'] = $this->record->review_note;

        return $data;
    }
}
