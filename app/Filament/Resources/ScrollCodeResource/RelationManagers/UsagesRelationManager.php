<?php

namespace App\Filament\Resources\ScrollCodeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    protected static ?string $title = 'Riwayat Pemakaian';

    /**
     * Read-only — baris di sini cuma pernah dibuat lewat "Catat
     * Pemakaian" (ScrollCode::recordUsage()).
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat pemakaian')
            ->emptyStateDescription('Riwayat otomatis muncul di sini setiap kali "Catat Pemakaian" dicatat.');
    }
}
