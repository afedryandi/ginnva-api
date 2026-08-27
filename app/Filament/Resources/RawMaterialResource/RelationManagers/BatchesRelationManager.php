<?php

namespace App\Filament\Resources\RawMaterialResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'batches';

    protected static ?string $title = 'Batch Tersisa (FIFO)';

    /**
     * Read-only — batch cuma pernah dibuat/dikonsumsi lewat "Catat Stok"
     * (RawMaterial::recordMovement()) atau "Sesuaikan Stok"
     * (RawMaterial::adjustStock()), bukan diedit langsung di sini.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->where('quantity', '>', 0))
            ->columns([
                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Masuk')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn ($record) => $record->is_adjustment ? 'Dari penyesuaian stok (opname)' : null),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Sisa Kuantitas')
                    // Satuan diambil dari owner record (RawMaterial induk),
                    // bukan $record->rawMaterial->unit — semua baris batch
                    // di tabel ini pasti punya induk yang SAMA, jadi akses
                    // relasi per baris cuma bikin N+1 tanpa guna.
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2) . ' ' . $this->getOwnerRecord()->unit)
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Kedaluwarsa')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn ($record) => match (true) {
                        $record->expiry_date === null => 'gray',
                        $record->expiry_date->isPast() => 'danger',
                        $record->expiry_date->lte(now()->addDays(30)) => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),
            ])
            ->defaultSort('received_date')
            ->emptyStateHeading('Belum ada batch tersisa')
            ->emptyStateDescription('Batch baru otomatis muncul di sini setiap kali "Catat Stok → Masuk" dicatat.');
    }
}