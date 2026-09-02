<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use App\Filament\Widgets\NotCheckedInTodayWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Entri Manual'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            NotCheckedInTodayWidget::class,
        ];
    }
}
