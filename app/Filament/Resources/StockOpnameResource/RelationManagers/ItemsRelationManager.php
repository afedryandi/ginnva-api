<?php

namespace App\Filament\Resources\StockOpnameResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only -- baris cuma dibuat lewat aksi "Buat Sesi Stok Opname"
 * (StockOpnameService), sama pola dengan UsagesRelationManager/
 * TransfersRelationManager di ScrollCodeResource.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Item yang Dihitung';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item_name')
            ->columns([
                Tables\Columns\TextColumn::make('item_name')
                    ->label('Item'),

                Tables\Columns\TextColumn::make('item_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'raw_material' => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('system_quantity')
                    ->label('Stok Sistem')
                    ->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                Tables\Columns\TextColumn::make('actual_quantity')
                    ->label('Hasil Hitung Fisik')
                    ->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                Tables\Columns\TextColumn::make('delta')
                    ->label('Selisih')
                    ->formatStateUsing(fn ($state, $record) => ($state > 0 ? '+' : '') . number_format((float) $state, 2) . ' ' . $record->unit)
                    ->color(fn ($state) => match (true) {
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default => 'gray',
                    })
                    ->badge(),
            ])
            ->emptyStateHeading('Belum ada item');
    }
}
