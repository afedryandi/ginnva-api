<?php

namespace App\Filament\Resources\AttendanceDeductionRuleResource\Pages;

use App\Filament\Resources\AttendanceDeductionRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceDeductionRules extends ListRecords
{
    protected static string $resource = AttendanceDeductionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
