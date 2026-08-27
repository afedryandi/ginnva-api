<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Riwayat Keluar/Masuk';

    /**
     * Read-only — baris di sini cuma pernah dibuat lewat scan QR di app
     * mobile (InventoryItem::recordMovement()), bukan diinput manual
     * admin di Filament. Admin (full-access) tetap bisa membatalkan
     * movement TERAKHIR lewat aksi "Batalkan" kalau ada salah scan/salah
     * pilih tipe — lihat InventoryItem::reverseLastMovement().
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
                        'gray' => 'correction',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in' => 'Masuk',
                        'out' => 'Keluar',
                        'correction' => 'Koreksi',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('destinationStore.name')
                    ->label('Toko Tujuan')
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
                Tables\Actions\Action::make('reverse')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (InventoryMovement $record) => auth()->user()?->isFullAccess()
                        && $record->type !== 'correction'
                        && ! $this->getOwnerRecord()->movements()->where('id', '>', $record->id)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Batalkan pencatatan ini? Status barang akan dikembalikan ke sebelumnya, dan baris "Koreksi" baru akan ditambahkan sebagai jejaknya. Cuma bisa untuk kejadian paling terakhir.')
                    ->action(function (InventoryMovement $record) {
                        /** @var InventoryItem $item */
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
            ->defaultSort('created_at', 'desc');
    }
}
