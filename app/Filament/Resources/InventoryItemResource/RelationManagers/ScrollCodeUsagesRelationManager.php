<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Duplikat tampilan dari ScrollCodeResource\RelationManagers\UsagesRelationManager
 * — supaya staff yang kerja dari Produk PPF/WF tidak perlu pindah menu
 * untuk lihat riwayat pemakaian meter kode gulungan terkait barang ini.
 */
class ScrollCodeUsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'scrollCodeUsages';

    protected static ?string $title = 'Riwayat Pemakaian (Meter)';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Cuma relevan kalau barangnya punya kode gulungan terkait — tanpa
     * ini tab tetap muncul kosong untuk Produk PPF/WF yang belum
     * dikaitkan ke kode gulungan sama sekali.
     */
    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->scroll_code_id !== null;
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
