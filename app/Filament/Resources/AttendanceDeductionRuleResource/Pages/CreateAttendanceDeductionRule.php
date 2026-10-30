<?php

namespace App\Filament\Resources\AttendanceDeductionRuleResource\Pages;

use App\Filament\Resources\AttendanceDeductionRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAttendanceDeductionRule extends CreateRecord
{
    protected static string $resource = AttendanceDeductionRuleResource::class;

    protected function afterCreate(): void
    {
        $drafts = $this->record->draftPayrollCount();

        if ($drafts > 0) {
            \Filament\Notifications\Notification::make()
                ->title("{$drafts} slip draf perlu dihitung ulang")
                ->body('Aturan baru ini berlaku untuk slip draf yang sudah digenerate. Buka Penggajian dan pakai Hitung Ulang.')
                ->warning()
                ->persistent()
                ->send();
        }
    }
}
