<?php

namespace App\Filament\Resources\AttendanceCorrectionRequestResource\Pages;

use App\Filament\Resources\AttendanceCorrectionRequestResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewAttendanceCorrectionRequest extends ViewRecord
{
    protected static string $resource = AttendanceCorrectionRequestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('user.name')->label('Karyawan'),
            TextEntry::make('date')->label('Tanggal Absensi')->date('d M Y'),
            TextEntry::make('entry_type')->label('Jenis'),
            TextEntry::make('clock_in_at')->label('Jam Masuk')->dateTime('H:i')->placeholder('—'),
            TextEntry::make('clock_out_at')->label('Jam Keluar')->dateTime('H:i')->placeholder('—'),
            TextEntry::make('reason')->label('Alasan')->columnSpanFull(),
            TextEntry::make('requestedBy.name')->label('Diajukan Oleh'),
            TextEntry::make('status')->label('Status'),
            TextEntry::make('reviewedBy.name')->label('Ditinjau Oleh')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Waktu Tinjau')->dateTime('d M Y, H:i')->placeholder('—'),
            TextEntry::make('review_notes')->label('Catatan Tinjauan')->placeholder('—')->columnSpanFull(),
        ]);
    }
}
