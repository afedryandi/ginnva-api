<?php

namespace App\Filament\Resources\AssetResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Rantai kepemilikan terstruktur — dari siapa/toko mana, ke siapa/toko
 * mana, kondisi fisik saat itu, dan alasannya. Beda dari histori activity
 * log generik (cuma diff before/after field polos) yang sebelumnya jadi
 * satu-satunya jejak perpindahan aset. Baris di sini cuma pernah dibuat
 * lewat aksi "Serah Terima" (lihat AssetResource), read-only.
 */
class TransfersRelationManager extends RelationManager
{
    protected static string $relationship = 'transfers';

    protected static ?string $title = 'Riwayat Kepemilikan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('fromUser.name')
                    ->label('Dari')
                    ->placeholder('— (tidak ada)'),

                Tables\Columns\TextColumn::make('toUser.name')
                    ->label('Ke')
                    ->placeholder('— (dilepas)'),

                Tables\Columns\TextColumn::make('toStore.name')
                    ->label('Toko')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('condition_at_transfer')
                    ->label('Kondisi Saat Itu')
                    ->colors([
                        'success' => 'baik',
                        'warning' => 'perlu_perhatian',
                        'danger'  => 'rusak',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'baik' => 'Baik',
                        'perlu_perhatian' => 'Perlu Perhatian',
                        'rusak' => 'Rusak',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->wrap()
                    ->limit(60),

                Tables\Columns\TextColumn::make('performedBy.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat serah terima')
            ->emptyStateDescription('Riwayat otomatis muncul di sini setiap kali aksi "Serah Terima" dipakai.');
    }
}
