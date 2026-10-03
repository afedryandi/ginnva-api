<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Riwayat pengajuan jadwal ulang customer (2026-10-03, audit alur Booking) --
 * termasuk yang ditolak/ditutup, beserta siapa yang memutuskan & catatannya,
 * supaya bisa diaudit dari panel. Read-only; keputusan lewat aksi di halaman
 * View/Edit booking.
 */
class RescheduleRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'rescheduleRequests';

    protected static ?string $title = 'Riwayat Jadwal Ulang';

    protected static ?string $modelLabel = 'Pengajuan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with('decider'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Diajukan')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('requested_date')->label('Tanggal Diminta')->date('d M Y'),
                Tables\Columns\TextColumn::make('reason')->label('Alasan Customer')->placeholder('—')->wrap(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending'  => 'Menunggu',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak/Ditutup',
                        default    => $state,
                    })
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('decider.name')->label('Diputuskan Oleh')->placeholder('—'),
                Tables\Columns\TextColumn::make('decided_at')->label('Waktu Keputusan')->dateTime('d M Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('decision_note')->label('Catatan')->placeholder('—')->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
