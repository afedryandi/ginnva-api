<?php

namespace App\Filament\Resources;

use App\Exports\ConsumableItemMovementExport;
use App\Filament\Resources\ConsumableItemMovementResource\Pages;
use App\Models\ConsumableItem;
use App\Models\ConsumableItemMovement;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ConsumableItemMovementResource extends Resource
{
    protected static ?string $model = ConsumableItemMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Riwayat Barang Habis Pakai';

    protected static ?string $modelLabel = 'Riwayat Barang Habis Pakai';

    protected static ?string $pluralModelLabel = 'Riwayat Barang Habis Pakai';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['consumableItem', 'user']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('consumableItem.name')
                    ->label('Barang')
                    ->searchable()
                    ->placeholder('— (barang sudah dihapus)'),

                Tables\Columns\TextColumn::make('consumableItem.category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'in',
                        'danger'  => 'out',
                        'warning' => 'adjustment',
                        'gray'    => 'correction',
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
                    ->formatStateUsing(fn ($state, ConsumableItemMovement $record) => ($state > 0 && $record->type === 'adjustment' ? '+' : '') . number_format((float) $state, 2) . ' ' . ($record->consumableItem?->unit ?? '')),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(40)
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(['in' => 'Masuk', 'out' => 'Keluar', 'adjustment' => 'Penyesuaian (Opname)', 'correction' => 'Koreksi']),

                Tables\Filters\SelectFilter::make('consumable_item_id')
                    ->label('Barang')
                    ->options(fn () => ConsumableItem::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Dicatat Oleh')
                    ->options(fn () => User::whereIn(
                        'id',
                        ConsumableItemMovement::whereNotNull('user_id')->distinct()->pluck('user_id')
                    )->pluck('name', 'id')),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
                    ),
            ])
            ->headerActions([
                // Sama pola dengan RawMaterialMovementResource — ikut
                // filter yang sedang aktif di layar.
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn ($livewire) => Excel::download(
                        new ConsumableItemMovementExport($livewire->getFilteredTableQuery()),
                        'riwayat-barang-habis-pakai-' . now()->format('Ymd') . '.xlsx'
                    )),

                Tables\Actions\Action::make('reconcile')
                    ->label('Cek Rekonsiliasi')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->action(function () {
                        $mismatches = [];

                        ConsumableItem::query()->chunkById(100, function ($items) use (&$mismatches) {
                            foreach ($items as $item) {
                                $computed = (float) $item->movements()
                                    ->selectRaw("SUM(CASE WHEN type = 'out' THEN -quantity ELSE quantity END) as total")
                                    ->value('total');

                                $diff = round((float) $item->current_stock - $computed, 2);

                                if (abs($diff) > 0.01) {
                                    $mismatches[] = "{$item->name}: sistem {$item->current_stock}, seharusnya {$computed} (selisih {$diff})";
                                }
                            }
                        });

                        if (empty($mismatches)) {
                            Notification::make()
                                ->title('Rekonsiliasi bersih')
                                ->body('current_stock semua barang habis pakai cocok dengan penjumlahan riwayat movement-nya.')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(count($mismatches) . ' barang tidak cocok')
                            ->body(implode("\n", array_slice($mismatches, 0, 15)) . (count($mismatches) > 15 ? "\n… dan " . (count($mismatches) - 15) . ' lainnya.' : ''))
                            ->danger()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                // Sama pola dengan InventoryMovementResource — cuma bisa
                // membatalkan kejadian PALING TERAKHIR untuk barang
                // terkait, terbatas full-access.
                Tables\Actions\Action::make('reverse')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (ConsumableItemMovement $record) => auth()->user()?->isFullAccess()
                        && $record->type !== 'correction'
                        && $record->consumableItem !== null
                        && ! $record->consumableItem->movements()->where('id', '>', $record->id)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Batalkan pencatatan ini? Stok akan dikembalikan ke sebelumnya, dan baris "Koreksi" baru akan ditambahkan sebagai jejaknya. Cuma bisa untuk kejadian paling terakhir barang ini.')
                    ->action(function (ConsumableItemMovement $record) {
                        try {
                            $record->consumableItem->reverseLastMovement($record, auth()->id());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa membatalkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pencatatan dibatalkan')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConsumableItemMovements::route('/'),
        ];
    }
}