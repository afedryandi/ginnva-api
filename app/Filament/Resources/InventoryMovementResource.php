<?php

namespace App\Filament\Resources;

use App\Exports\InventoryMovementExport;
use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Store;
use App\Models\User;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
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
 *
 * Audit 2026-11-03: akses eksplisit (menu harus dicentang), staf non-akses-penuh hanya melihat baris tokonya (toko pencatat atau toko
 * tujuan), pembatalan menyimpan baris aslinya (ditandai dibatalkan, alasan wajib), dan halaman detail.
 */
class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?string $navigationGroup = 'Riwayat';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Riwayat Keluar/Masuk';

    protected static ?string $modelLabel = 'Riwayat Keluar/Masuk';

    protected static ?string $pluralModelLabel = 'Riwayat Keluar/Masuk';

    /** Eksplisit (bukan akses bawaan): sama dengan menu induknya, Produk PPF/WF, dan Riwayat Bahan Baku. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
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
        return parent::getEloquentQuery()
            ->visibleTo(auth()->user())
            ->with(['inventoryItem.scrollCode:id,code', 'user', 'destinationStore', 'store', 'reverser']);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'in' => 'Masuk',
            'out' => 'Keluar',
            'correction' => 'Koreksi',
            default => $type,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn (InventoryMovement $record) => $record->reversed_at !== null ? 'opacity-60' : null)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('inventoryItem.code')
                    ->label('Kode Produk')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    // Drill-down ke halaman edit barang terkait (audit 2026-09-30).
                    ->url(fn (InventoryMovement $record) => $record->inventoryItem && InventoryItemResource::canViewAny()
                        ? InventoryItemResource::getUrl('edit', ['record' => $record->inventoryItem->id])
                        : null),

                Tables\Columns\TextColumn::make('inventoryItem.name')
                    ->label('Nama Produk')
                    ->searchable()
                    ->placeholder('— (produk sudah dihapus)')
                    ->url(fn (InventoryMovement $record) => $record->inventoryItem && InventoryItemResource::canViewAny()
                        ? InventoryItemResource::getUrl('edit', ['record' => $record->inventoryItem->id])
                        : null),

                Tables\Columns\TextColumn::make('inventoryItem.category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('inventoryItem.scrollCode.code')
                    ->label('Kode Gulungan')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'in',
                        'danger'  => 'out',
                        'gray'    => 'correction',
                    ])
                    ->formatStateUsing(fn (string $state): string => static::typeLabel($state)),

                Tables\Columns\TextColumn::make('reversed_state')
                    ->label('Status')
                    ->state(fn (InventoryMovement $record) => $record->reversed_at !== null ? 'Dibatalkan' : null)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn (InventoryMovement $record) => $record->reversed_at !== null
                        ? 'Dibatalkan' . ($record->reverser ? " oleh {$record->reverser->name}" : '') . ' pada ' . $record->reversed_at->format('d M Y, H:i') . ($record->reversed_reason ? ". Alasan: {$record->reversed_reason}" : '')
                        : null),

                // Diisi otomatis dari toko akun staff yang scan (atau
                // dipilih manual oleh full-access) — bukan lagi cuma
                // catatan teks bebas, lihat migration
                // add_destination_store_id_to_inventory_movements.
                Tables\Columns\TextColumn::make('destinationStore.name')
                    ->label('Toko Tujuan')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko Pencatat')
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
                    ->tooltip(fn (InventoryMovement $record) => filled($record->note) && mb_strlen($record->note) > 40 ? $record->note : null)
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(['in' => 'Masuk', 'out' => 'Keluar', 'correction' => 'Koreksi']),

                Tables\Filters\SelectFilter::make('period')
                    ->label('Periode')
                    ->options(['today' => 'Hari ini', 'week' => '7 hari terakhir', 'month' => 'Bulan ini', 'last_month' => 'Bulan lalu'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'today' => $query->whereDate('created_at', today()),
                        'week' => $query->where('created_at', '>=', today()->subDays(6)->startOfDay()),
                        'month' => $query->where('created_at', '>=', now()->startOfMonth()),
                        'last_month' => $query->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()]),
                        default => $query,
                    }),

                Tables\Filters\SelectFilter::make('inventory_item_id')
                    ->label('Barang')
                    ->relationship('inventoryItem', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('destination_store_id')
                    ->label('Toko Tujuan')
                    ->options(fn () => Store::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko Pencatat')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Dicatat Oleh')
                    ->options(fn () => User::whereIn(
                        'id',
                        InventoryMovement::query()->visibleTo(auth()->user())->whereNotNull('user_id')->distinct()->pluck('user_id')
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

                Tables\Filters\TernaryFilter::make('reversed')
                    ->label('Dibatalkan')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya yang dibatalkan')
                    ->falseLabel('Sembunyikan yang dibatalkan')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('reversed_at'),
                        false: fn (Builder $query) => $query->whereNull('reversed_at'),
                        blank: fn (Builder $query) => $query,
                    ),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
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
                    ->action(function ($livewire) {
                        // Log ekspor (audit 2026-09-30), konsisten dengan laporan lain.
                        try {
                            activity('report_export')
                                ->causedBy(auth()->user())
                                ->withProperties(['report' => 'inventory_movement', 'format' => 'xlsx'])
                                ->log('Export Riwayat Keluar/Masuk (xlsx)');
                        } catch (\Throwable $e) {
                            report($e);
                        }

                        return Excel::download(
                            new InventoryMovementExport($livewire->getFilteredTableQuery()),
                            'riwayat-inventaris-' . now()->format('Ymd') . '.xlsx'
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Sama pola dengan MovementsRelationManager (per-barang) —
                // cuma bisa membatalkan kejadian PALING TERAKHIR untuk
                // barang terkait, terbatas full-access. Baris aslinya disimpan (ditandai dibatalkan) dan alasan wajib.
                static::reverseAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Aksi Batalkan, dipakai juga oleh riwayat per barang (MovementsRelationManager). */
    public static function reverseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reverse')
            ->label('Batalkan')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (InventoryMovement $record) => (auth()->user()?->isFullAccess() ?? false) && $record->isReversible())
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Alasan pembatalan')
                    ->required()
                    ->maxLength(500)
                    ->rows(2)
                    ->helperText('Mis. salah scan barang atau salah memilih jenis.'),
            ])
            ->modalDescription('Batalkan pencatatan ini? Status barang akan dikembalikan ke sebelumnya. Baris ini tetap tersimpan sebagai "Dibatalkan" dan baris "Koreksi" baru ditambahkan sebagai jejaknya. Cuma bisa untuk kejadian paling terakhir barang ini.')
            ->action(function (InventoryMovement $record, array $data) {
                try {
                    $record->inventoryItem->reverseLastMovement($record, auth()->id(), $data['reason']);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title('Tidak bisa membatalkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pencatatan dibatalkan')->success()->send();
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Pergerakan')->columns(3)->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y, H:i'),
                Infolists\Components\TextEntry::make('type')->label('Jenis')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'in' => 'success',
                        'out' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => static::typeLabel($state)),
                Infolists\Components\TextEntry::make('user.name')->label('Dicatat Oleh')->placeholder('—'),
                Infolists\Components\TextEntry::make('destinationStore.name')->label('Toko Tujuan')->placeholder('—'),
                Infolists\Components\TextEntry::make('store.name')->label('Toko Pencatat')->placeholder('—'),
                Infolists\Components\TextEntry::make('note')->label('Catatan')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Barang')->columns(3)->schema([
                Infolists\Components\TextEntry::make('inventoryItem.code')->label('Kode Produk')->badge()->placeholder('—'),
                Infolists\Components\TextEntry::make('inventoryItem.name')->label('Nama Produk')->placeholder('— (produk sudah dihapus)'),
                Infolists\Components\TextEntry::make('inventoryItem.category')->label('Kategori')->placeholder('—'),
                Infolists\Components\TextEntry::make('inventoryItem.status')->label('Status Barang Sekarang')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'in_stock' => 'Ada Stok',
                        'out' => 'Keluar',
                        default => $state ?? '—',
                    }),
                Infolists\Components\TextEntry::make('inventoryItem.scrollCode.code')->label('Kode Gulungan')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Pembatalan')->columns(3)
                ->visible(fn (InventoryMovement $record) => $record->reversed_at !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('reversed_at')->label('Dibatalkan Pada')->dateTime('d M Y, H:i'),
                    Infolists\Components\TextEntry::make('reverser.name')->label('Dibatalkan Oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('reversed_reason')->label('Alasan')->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryMovements::route('/'),
            'view' => Pages\ViewInventoryMovement::route('/{record}'),
        ];
    }
}
