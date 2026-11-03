<?php

namespace App\Filament\Resources\ConsumableItemResource\RelationManagers;

use App\Models\ConsumableItem;
use App\Models\ConsumableItemMovement;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Riwayat Keluar/Masuk';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->withoutGlobalScope('effective')->with(['store', 'reverser']))
            ->recordClasses(fn ($record) => $record->reversed_at !== null ? 'opacity-60' : null)
            ->columns([
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'in',
                        'danger' => 'out',
                        'warning' => 'adjustment',
                        'gray' => 'correction',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in' => 'Masuk',
                        'out' => 'Keluar',
                        'adjustment' => 'Penyesuaian (Opname)',
                        'correction' => 'Koreksi',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Jumlah')
                    // Satuan dari owner record — lihat catatan sama di
                    // RawMaterialResource\RelationManagers\MovementsRelationManager.
                    ->formatStateUsing(fn ($state, $record) => ($state > 0 && $record->type === 'adjustment' ? '+' : '') . number_format((float) $state, 2) . ' ' . $this->getOwnerRecord()->unit),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->visible(fn () => \App\Filament\Resources\ConsumableItemResource::canSeeCost())
                    ->money('IDR')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reversed_state')
                    ->label('Status')
                    ->state(fn ($record) => $record->reversed_at !== null ? 'Dibatalkan' : null)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn ($record) => $record->reversed_reason),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(40),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->actions([
                \App\Filament\Resources\ConsumableItemMovementResource::reverseAction(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat')
            ->emptyStateDescription('Riwayat otomatis muncul di sini setiap kali "Catat Stok" dicatat. Baris di sini permanen — kalau ada salah catat, gunakan aksi "Batalkan" pada baris terakhir, atau "Sesuaikan Stok" (opname) di daftar utama.');
    }
}