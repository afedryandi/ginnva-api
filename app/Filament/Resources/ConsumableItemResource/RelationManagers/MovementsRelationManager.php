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
            ->actions([
                // Jalan resmi kalau salah catat — lebih presisi daripada
                // "Sesuaikan Stok" (yang butuh tahu angka hasil hitung
                // fisik yang BENAR). Cuma untuk movement TERAKHIR.
                Tables\Actions\Action::make('reverse')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (ConsumableItemMovement $record) => auth()->user()?->isFullAccess()
                        && $record->type !== 'correction'
                        && ! $this->getOwnerRecord()->movements()->where('id', '>', $record->id)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Batalkan pencatatan ini? Stok akan dikembalikan ke sebelumnya, dan baris "Koreksi" baru akan ditambahkan sebagai jejaknya. Cuma bisa untuk kejadian paling terakhir.')
                    ->action(function (ConsumableItemMovement $record) {
                        /** @var ConsumableItem $item */
                        $item = $this->getOwnerRecord();

                        try {
                            $item->reverseLastMovement($record, auth()->id());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa membatalkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pencatatan dibatalkan')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat')
            ->emptyStateDescription('Riwayat otomatis muncul di sini setiap kali "Catat Stok" dicatat. Baris di sini permanen — kalau ada salah catat, gunakan aksi "Batalkan" pada baris terakhir, atau "Sesuaikan Stok" (opname) di daftar utama.');
    }
}