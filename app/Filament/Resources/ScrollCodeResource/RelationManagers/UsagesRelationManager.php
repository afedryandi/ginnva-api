<?php

namespace App\Filament\Resources\ScrollCodeResource\RelationManagers;

use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    protected static ?string $title = 'Riwayat Pemakaian';

    /**
     * Baris di sini cuma pernah dibuat lewat "Catat Pemakaian"
     * (ScrollCode::recordUsage()) — TIDAK bisa diedit manual, tapi admin
     * (full-access) tetap bisa membatalkan 1 baris yang salah input lewat
     * aksi "Batalkan" di bawah (lihat ScrollCode::reverseUsage()).
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('meters')
                    ->label('Meter Dipakai')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2) . ' m')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->actions([
                // Koreksi kalau staff salah input meter di lapangan —
                // TERBATAS full-access, mengembalikan meternya ke sisa
                // panjang gulungan dan menghapus baris ini (lihat
                // ScrollCode::reverseUsage()). Sebelumnya satu-satunya
                // jalan koreksi adalah "Edit Panjang" manual yang membuat
                // remaining_length_meters tidak sinkron lagi dengan
                // riwayat pemakaian.
                Tables\Actions\Action::make('reverse')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Batalkan baris pemakaian ini? Meternya akan dikembalikan ke sisa panjang gulungan, dan baris ini akan dihapus dari riwayat. Tindakan ini untuk mengoreksi salah input, bukan pembatalan pekerjaan.')
                    ->action(function (ScrollCodeUsage $record) {
                        /** @var ScrollCode $scrollCode */
                        $scrollCode = $record->scrollCode;
                        $scrollCode->reverseUsage($record);

                        Notification::make()->title('Pemakaian dibatalkan, meter dikembalikan')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat pemakaian')
            ->emptyStateDescription('Riwayat otomatis muncul di sini setiap kali "Catat Pemakaian" dicatat.');
    }
}
