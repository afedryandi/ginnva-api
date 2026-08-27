<?php

namespace App\Filament\Resources\RawMaterialResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Riwayat Keluar/Masuk';

    /**
     * Read-only — baris di sini cuma pernah dibuat lewat tombol "Catat
     * Stok" (RawMaterial::recordMovement()), bukan diedit langsung di
     * sini, supaya current_stock tetap selalu konsisten dengan riwayatnya.
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
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'in',
                        'danger' => 'out',
                        'warning' => 'adjustment',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in' => 'Masuk',
                        'out' => 'Keluar',
                        'adjustment' => 'Penyesuaian (Opname)',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Jumlah')
                    // Satuan dari owner record (RawMaterial induk), bukan
                    // $record->rawMaterial->unit — semua baris di tabel
                    // ini pasti induknya SAMA, akses relasi per baris cuma
                    // bikin N+1 tanpa guna (sama fix seperti BatchesRelationManager).
                    ->formatStateUsing(fn ($state, $record) => ($state > 0 && $record->type === 'adjustment' ? '+' : '') . number_format((float) $state, 2) . ' ' . $this->getOwnerRecord()->unit),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
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
            ->defaultSort('created_at', 'desc')
            // Riwayat ini permanen (tidak bisa diedit/dihapus) — supaya
            // audit trail tidak bisa dimanipulasi diam-diam. Kalau ada
            // salah catat, jalan resminya adalah "Sesuaikan Stok" (opname)
            // di daftar utama, BUKAN mengubah baris ini.
            ->emptyStateHeading('Belum ada riwayat')
            ->emptyStateDescription('Riwayat otomatis muncul di sini setiap kali "Catat Stok" dicatat. Baris di sini permanen — kalau ada salah catat, koreksi lewat "Sesuaikan Stok" (opname) di daftar utama, bukan mengubah baris ini.');
    }
}
