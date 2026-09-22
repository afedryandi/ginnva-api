<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\EmployeeCareerHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Riwayat Karir" (audit Majoo f57) — daftar perpindahan toko/outlet
 * karyawan ini, dicatat OTOMATIS oleh User::booted() begitu store_id
 * berubah lewat jalur mana pun. Read-only murni (tidak ada
 * create/edit/delete manual) — ini adalah LOG, bukan data yang diedit.
 */
class CareerHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'careerHistories';

    protected static ?string $title = 'Riwayat Karir';

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
                    ->label('Tanggal')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('previousStore.name')
                    ->label('Dari Toko')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('newStore.name')
                    ->label('Ke Toko')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('changedBy.name')
                    ->label('Diubah Oleh')
                    ->placeholder('Sistem'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat perpindahan')
            ->emptyStateDescription('Baris muncul otomatis di sini setiap kali karyawan ini dipindahkan ke toko lain.');
    }
}
