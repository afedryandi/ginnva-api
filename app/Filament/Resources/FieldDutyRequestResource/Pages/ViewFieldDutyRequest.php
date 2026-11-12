<?php

namespace App\Filament\Resources\FieldDutyRequestResource\Pages;

use App\Filament\Resources\FieldDutyRequestResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewFieldDutyRequest extends ViewRecord
{
    protected static string $resource = FieldDutyRequestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('user.name')->label('Karyawan'),
            TextEntry::make('store.name')->label('Toko'),
            TextEntry::make('date')->label('Tanggal Dinas Luar')->date('d M Y'),
            TextEntry::make('start_time')->label('Jam')->getStateUsing(fn ($record) => $record->windowLabel()),
            TextEntry::make('status')->label('Status'),
            TextEntry::make('description')->label('Tujuan / Pekerjaan')->columnSpanFull(),
            TextEntry::make('reviewedBy.name')->label('Diputuskan Oleh')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Waktu Putusan')->dateTime('d M Y, H:i')->placeholder('—'),
            TextEntry::make('review_notes')->label('Catatan Putusan')->placeholder('—')->columnSpanFull(),
        ]);
    }
}
