<?php

namespace App\Filament\Resources\OvertimeRequestResource\Pages;

use App\Filament\Resources\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewOvertimeRequest extends ViewRecord
{
    protected static string $resource = OvertimeRequestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('user.name')->label('Karyawan'),
            TextEntry::make('store.name')->label('Toko'),
            TextEntry::make('date')->label('Tanggal Lembur')->date('d M Y'),
            TextEntry::make('starts_at')
                ->label('Jam')
                ->formatStateUsing(fn (OvertimeRequest $record) => $record->starts_at->format('H:i') . '–' . $record->ends_at->format('H:i') . ' (' . $record->minutes . ' menit)'),
            TextEntry::make('booking.booking_number')->label('Booking Terkait')->placeholder('—'),
            TextEntry::make('description')->label('Uraian Pekerjaan')->placeholder('—')->columnSpanFull(),
            TextEntry::make('status')->label('Status'),
            TextEntry::make('reviewedBy.name')->label('Diputuskan Oleh')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Waktu Putusan')->dateTime('d M Y, H:i')->placeholder('—'),
            TextEntry::make('review_notes')->label('Catatan Putusan')->placeholder('—')->columnSpanFull(),
        ]);
    }
}
