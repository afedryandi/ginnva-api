<?php

namespace App\Filament\Resources\PartialLeaveRequestResource\Pages;

use App\Filament\Resources\PartialLeaveRequestResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPartialLeaveRequest extends ViewRecord
{
    protected static string $resource = PartialLeaveRequestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('user.name')->label('Karyawan'),
            TextEntry::make('store.name')->label('Toko'),
            TextEntry::make('date')->label('Tanggal')->date('d M Y'),
            TextEntry::make('category')->label('Kategori')->formatStateUsing(fn (string $state) => \App\Models\PartialLeaveRequest::CATEGORY_LABELS[$state] ?? $state),
            TextEntry::make('start_time')->label('Jam')->getStateUsing(fn ($record) => $record->windowLabel()),
            TextEntry::make('status')->label('Status'),
            TextEntry::make('reason')->label('Alasan')->columnSpanFull(),
            TextEntry::make('reviewedBy.name')->label('Diputuskan Oleh')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Waktu Putusan')->dateTime('d M Y, H:i')->placeholder('—'),
            TextEntry::make('review_notes')->label('Catatan Putusan')->placeholder('—')->columnSpanFull(),
        ]);
    }
}
