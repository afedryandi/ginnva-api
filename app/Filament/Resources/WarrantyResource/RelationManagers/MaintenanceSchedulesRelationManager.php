<?php

namespace App\Filament\Resources\WarrantyResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF" (2026-10-01) -- read-only,
 * murni untuk staff lihat riwayat tiap occurrence (semua status berubah
 * OTOMATIS lewat sistem: command harian + aksi customer di app, lihat
 * WarrantyMaintenanceSchedule). Tidak ada create/edit manual -- sesuai
 * keputusan user, staff tidak override, cukup lihat.
 */
class MaintenanceSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceSchedules';

    protected static ?string $title = 'Jadwal Maintenance';

    protected static ?string $modelLabel = 'Jadwal';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence')
            ->columns([
                Tables\Columns\TextColumn::make('sequence')
                    ->label('Ke-'),

                Tables\Columns\TextColumn::make('scheduled_date')
                    ->label('Jadwal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Menunggu Jadwal',
                        'confirmation_sent' => 'Menunggu Konfirmasi',
                        'confirmed' => 'Dikonfirmasi',
                        'forfeited' => 'Hangus',
                        'completed' => 'Selesai',
                        default => $state,
                    })
                    ->colors([
                        'gray' => 'pending',
                        'warning' => 'confirmation_sent',
                        'info' => 'confirmed',
                        'danger' => 'forfeited',
                        'success' => 'completed',
                    ]),

                Tables\Columns\TextColumn::make('reminder_sent_at')
                    ->label('Konfirmasi Dikirim')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('responded_at')
                    ->label('Direspons')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                // Drill-down ke booking yang tercipta saat customer confirm
                // (lihat MaintenanceScheduleController::confirm()).
                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking')
                    ->placeholder('—')
                    ->url(fn ($record) => $record->booking
                        ? \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $record->booking->id])
                        : null),
            ])
            ->defaultSort('sequence', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
