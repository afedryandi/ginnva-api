<?php

namespace App\Filament\Resources\BlockedDateResource\Pages;

use App\Filament\Resources\BlockedDateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBlockedDates extends ListRecords
{
    protected static string $resource = BlockedDateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Blokir Tanggal'),
        ];
    }

    // Gap "tampilan kalender ringkas" diperbaiki 2026-09-25 (audit
    // Tanggal Tidak Tersedia) -- lihat App\Filament\Widgets\
    // BlockedDateCalendarWidget.
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\BlockedDateCalendarWidget::class,
        ];
    }
}
