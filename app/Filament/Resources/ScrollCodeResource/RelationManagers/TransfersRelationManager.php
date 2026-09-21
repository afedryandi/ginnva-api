<?php

namespace App\Filament\Resources\ScrollCodeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Riwayat Mutasi" -- keputusan atasan 2026-09-19 (Topik 4, Fase 1).
 * Read-only, sama pola dengan UsagesRelationManager (baris cuma dibuat
 * lewat aksi "Mutasi ke Cabang Lain" di ScrollCodeResource, tidak ada
 * edit/hapus manual -- riwayat mutasi harus tetap utuh sebagai jejak
 * audit perpindahan barang antar toko).
 */
class TransfersRelationManager extends RelationManager
{
    protected static string $relationship = 'transfers';

    protected static ?string $title = 'Riwayat Mutasi';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('fromStore.name')
                    ->label('Dari Toko')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('toStore.name')
                    ->label('Ke Toko'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('performedBy.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat mutasi')
            ->emptyStateDescription('Riwayat otomatis muncul di sini setiap kali "Mutasi ke Cabang Lain" dicatat.');
    }
}
