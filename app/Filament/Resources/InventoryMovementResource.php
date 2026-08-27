<?php

namespace App\Filament\Resources;

use App\Exports\InventoryMovementExport;
use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Store;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan/riwayat pergerakan barang LINTAS SEMUA barang — beda dari
 * MovementsRelationManager di InventoryItemResource yang cuma nampilkan
 * riwayat 1 barang tertentu (di dalam halaman edit barang itu). Read-only
 * murni (tidak ada create/edit/delete di sini) — baris cuma pernah dibuat
 * lewat scan QR di app mobile (InventoryItem::recordMovement()).
 */
class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Riwayat Keluar/Masuk';

    protected static ?string $modelLabel = 'Riwayat Keluar/Masuk';

    protected static ?string $pluralModelLabel = 'Riwayat Keluar/Masuk';

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
        return parent::getEloquentQuery()->with(['inventoryItem', 'user', 'destinationStore']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('inventoryItem.code')
                    ->label('Kode Produk')
                    ->badge()
                    ->color('info')
                    ->searchable(),

                Tables\Columns\TextColumn::make('inventoryItem.name')
                    ->label('Nama Produk')
                    ->searchable()
                    ->placeholder('— (produk sudah dihapus)'),

                Tables\Columns\TextColumn::make('inventoryItem.category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'in',
                        'danger'  => 'out',
                        'gray'    => 'correction',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in' => 'Masuk',
                        'out' => 'Keluar',
                        'correction' => 'Koreksi',
                        default => $state,
                    }),

                // Diisi otomatis dari toko akun staff yang scan (atau
                // dipilih manual oleh full-access) — bukan lagi cuma
                // catatan teks bebas, lihat migration
                // add_destination_store_id_to_inventory_movements.
                Tables\Columns\TextColumn::make('destinationStore.name')
                    ->label('Toko Tujuan')
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
                    ->options(['in' => 'Masuk', 'out' => 'Keluar', 'correction' => 'Koreksi']),

                Tables\Filters\SelectFilter::make('destination_store_id')
                    ->label('Toko Tujuan')
                    ->options(fn () => Store::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Dicatat Oleh')
                    ->options(fn () => User::whereIn(
                        'id',
                        InventoryMovement::whereNotNull('user_id')->distinct()->pluck('user_id')
                    )->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori Produk')
                    ->options(fn () => InventoryItem::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn ($q, $value) => $q->whereHas('inventoryItem', fn ($iq) => $iq->where('category', $value))
                    )),

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
                // Pakai getFilteredTableQuery() (bukan query polos) supaya
                // export ikut filter yang sedang aktif di layar (jenis,
                // dicatat oleh, kategori, rentang tanggal) — lihat catatan
                // di InventoryMovementExport.
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn ($livewire) => Excel::download(
                        new InventoryMovementExport($livewire->getFilteredTableQuery()),
                        'riwayat-inventaris-' . now()->format('Ymd') . '.xlsx'
                    )),
            ])
            ->actions([
                // Sama pola dengan MovementsRelationManager (per-barang) —
                // cuma bisa membatalkan kejadian PALING TERAKHIR untuk
                // barang terkait, terbatas full-access.
                Tables\Actions\Action::make('reverse')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (InventoryMovement $record) => auth()->user()?->isFullAccess()
                        && $record->type !== 'correction'
                        && $record->inventoryItem !== null
                        && ! $record->inventoryItem->movements()->where('id', '>', $record->id)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Batalkan pencatatan ini? Status barang akan dikembalikan ke sebelumnya, dan baris "Koreksi" baru akan ditambahkan sebagai jejaknya. Cuma bisa untuk kejadian paling terakhir barang ini.')
                    ->action(function (InventoryMovement $record) {
                        try {
                            $record->inventoryItem->reverseLastMovement($record, auth()->id());
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
            'index' => Pages\ListInventoryMovements::route('/'),
        ];
    }
}