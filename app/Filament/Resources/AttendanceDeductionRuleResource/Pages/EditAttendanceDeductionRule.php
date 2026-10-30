<?php

namespace App\Filament\Resources\AttendanceDeductionRuleResource\Pages;

use App\Filament\Resources\AttendanceDeductionRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAttendanceDeductionRule extends EditRecord
{
    protected static string $resource = AttendanceDeductionRuleResource::class;

    protected function afterSave(): void
    {
        $drafts = $this->record->draftPayrollCount();

        if ($drafts > 0) {
            \Filament\Notifications\Notification::make()
                ->title("{$drafts} slip draf perlu dihitung ulang")
                ->body('Aturan ini berlaku untuk slip draf yang sudah digenerate. Buka Penggajian dan pakai Hitung Ulang (atau pilih beberapa slip lalu Hitung Ulang terpilih).')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
