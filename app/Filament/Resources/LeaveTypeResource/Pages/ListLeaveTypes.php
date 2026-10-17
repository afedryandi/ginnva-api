<?php

namespace App\Filament\Resources\LeaveTypeResource\Pages;

use App\Filament\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLeaveTypes extends ListRecords
{
    protected static string $resource = LeaveTypeResource::class;

    public function mount(): void
    {
        // Jenis bawaan dibuat bila belum ada (aman dipanggil berulang, tidak menimpa yang sudah diubah staf).
        LeaveType::ensureDefaults();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
