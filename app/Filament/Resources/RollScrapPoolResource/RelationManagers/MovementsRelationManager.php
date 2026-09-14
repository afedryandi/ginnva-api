<?php

namespace App\Filament\Resources\RollScrapPoolResource\RelationManagers;

use App\Models\RollScrapMovement;
use App\Models\RollScrapPool;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Riwayat Pergerakan';

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

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Meter')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2) . ' m'),

                Tables\Columns\TextColumn::make('sourceScrollCode.code')
                    ->label('Dari Kode Gulungan')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking Terkait')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('reverse')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (RollScrapMovement $record) => (auth()->user()?->isFullAccess() ?? false)
                        && $record->type !== 'correction'
                        && ! $record->pool->movements()->where('id', '>', $record->id)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Batalkan pencatatan ini? Sisa pool akan dikembalikan ke sebelumnya, dan baris "Koreksi" baru akan ditambahkan sebagai jejaknya. Cuma bisa untuk kejadian paling terakhir pool ini.')
                    ->action(function (RollScrapMovement $record) {
                        /** @var RollScrapPool $pool */
                        $pool = $record->pool;

                        try {
                            $pool->reverseLastMovement($record, auth()->id());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa membatalkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pencatatan dibatalkan')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat')
            ->emptyStateDescription('Riwayat otomatis muncul setiap kali sisa dikumpulkan atau dipakai.');
    }
}
