<?php

namespace App\Filament\Resources;

use App\Exports\ConsumableItemMovementExport;
use App\Filament\Resources\ConsumableItemMovementResource\Pages;
use App\Models\ConsumableItem;
use App\Models\ConsumableItemMovement;
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

class ConsumableItemMovementResource extends Resource
{
    protected static ?string $model = ConsumableItemMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?string $navigationGroup = 'Riwayat';

    protected static ?int $navigationSort = 102;

    protected static ?string $navigationLabel = 'Riwayat Barang Habis Pakai';

    protected static ?string $modelLabel = 'Riwayat Barang Habis Pakai';

    protected static ?string $pluralModelLabel = 'Riwayat Barang Habis Pakai';

    /** Buku pergerakan stok memuat pelaku dan harga beli per kejadian: hanya akses penuh atau menu yang dicentang eksplisit; harga butuh izin Lihat. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canSeeCost(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
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
        // Pembatalan menyimpan baris aslinya; hanya menu ini yang menampilkannya (scope 'effective' menyembunyikannya dari laporan).
        // Staf non-akses-penuh hanya melihat baris bertanda tokonya sendiri (audit 2026-11-03).
        return parent::getEloquentQuery()
            ->withoutGlobalScope('effective')
            ->visibleTo(auth()->user())
            ->with(['consumableItem', 'user', 'store', 'reverser']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn (ConsumableItemMovement $record) => $record->reversed_at !== null ? 'opacity-60' : null)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('consumableItem.name')
                    ->label('Barang')
                    ->searchable()
                    ->placeholder('— (barang sudah dihapus)')
                    // Drill-down ke halaman edit barang terkait (audit 2026-09-30).
                    ->url(fn (ConsumableItemMovement $record) => $record->consumableItem && ConsumableItemResource::canView($record->consumableItem)
                        ? ConsumableItemResource::getUrl('view', ['record' => $record->consumableItem->id])
                        : null),

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
                    ->visible(fn () => static::canSeeCost())
                    ->money('IDR')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reversed_state')
                    ->label('Status')
                    ->state(fn (ConsumableItemMovement $record) => $record->reversed_at !== null ? 'Dibatalkan' : null)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn (ConsumableItemMovement $record) => $record->reversed_at !== null
                        ? 'Dibatalkan' . ($record->reverser ? " oleh {$record->reverser->name}" : '') . ' pada ' . $record->reversed_at->format('d M Y, H:i') . ($record->reversed_reason ? ". Alasan: {$record->reversed_reason}" : '')
                        : null),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('Sumber')
                    ->state(fn (ConsumableItemMovement $record) => $record->sourceText())
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
                    ->tooltip(fn (ConsumableItemMovement $record) => filled($record->note) && mb_strlen($record->note) > 40 ? $record->note : null)
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(['in' => 'Masuk', 'out' => 'Keluar', 'adjustment' => 'Penyesuaian (Opname)', 'correction' => 'Koreksi']),

                Tables\Filters\SelectFilter::make('consumable_item_id')
                    ->label('Barang')
                    ->relationship('consumableItem', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => ConsumableItem::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->toArray())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn ($q, $value) => $q->whereHas('consumableItem', fn ($m) => $m->where('category', $value))
                    )),

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

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),

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
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Dicatat Oleh')
                    ->options(fn () => User::whereIn(
                        'id',
                        ConsumableItemMovement::whereNotNull('user_id')->distinct()->pluck('user_id')
                    )->pluck('name', 'id')),

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
                // Sama pola dengan RawMaterialMovementResource — ikut
                // filter yang sedang aktif di layar.
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function ($livewire) {
                        // Log ekspor (audit 2026-09-30), konsisten dengan laporan lain.
                        try {
                            activity('report_export')
                                ->causedBy(auth()->user())
                                ->withProperties(['report' => 'consumable_item_movement', 'format' => 'xlsx'])
                                ->log('Export Riwayat Barang Habis Pakai (xlsx)');
                        } catch (\Throwable $e) {
                            report($e);
                        }

                        return Excel::download(
                            new ConsumableItemMovementExport($livewire->getFilteredTableQuery(), static::canSeeCost()),
                            'riwayat-barang-habis-pakai-' . now()->format('Ymd') . '.xlsx'
                        );
                    }),

                Tables\Actions\Action::make('reconcile')
                    ->label('Cek Rekonsiliasi')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->action(function () {
                        $mismatches = [];
                        $checked = 0;

                        // Pergerakan yang dibatalkan tidak dihitung (scope 'effective' pada ConsumableItemMovement).
                        ConsumableItem::query()->chunkById(100, function ($items) use (&$mismatches, &$checked) {
                            foreach ($items as $item) {
                                $checked++;
                                $computed = (float) $item->movements()
                                    ->selectRaw("SUM(CASE WHEN type = 'out' THEN -quantity ELSE quantity END) as total")
                                    ->value('total');

                                $diff = round((float) $item->current_stock - $computed, 2);

                                if (abs($diff) > 0.01) {
                                    $mismatches[] = "{$item->name}: sistem {$item->current_stock}, seharusnya {$computed} (selisih {$diff})";
                                }
                            }
                        });

                        try {
                            activity('report_export')
                                ->causedBy(auth()->user())
                                ->withProperties(['report' => 'consumable_item_reconcile', 'checked' => $checked, 'mismatches' => count($mismatches)])
                                ->log('Cek Rekonsiliasi Barang Habis Pakai');
                        } catch (\Throwable $e) {
                            report($e);
                        }

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
                Tables\Actions\ViewAction::make(),
                static::reverseAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'in' => 'Masuk',
            'out' => 'Keluar',
            'adjustment' => 'Penyesuaian (Opname)',
            'correction' => 'Koreksi',
            default => $type,
        };
    }

    /**
     * Aksi Batalkan: hanya akses penuh, hanya kejadian terakhir barang itu yang bukan bersumber Memo/penerimaan/Stok Terbuang.
     * Alasan wajib; baris asli disimpan (ditandai dibatalkan). Dipakai juga oleh riwayat per barang (MovementsRelationManager).
     */
    public static function reverseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reverse')
            ->label('Batalkan')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (ConsumableItemMovement $record) => (auth()->user()?->isFullAccess() ?? false) && $record->isReversible())
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Alasan pembatalan')
                    ->required()
                    ->maxLength(500)
                    ->rows(2)
                    ->helperText('Mis. salah memilih barang atau salah memasukkan jumlah.'),
            ])
            ->modalDescription('Batalkan pencatatan ini? Stok kembali ke sebelumnya, baris ini ditandai Dibatalkan, dan baris Koreksi ditambahkan. Hanya bisa untuk kejadian terakhir barang ini.'
                . 'Pastikan ini bukan bagian dari Memo, penerimaan Permohonan Pembelian, atau Stok Terbuang lama; yang bersumber dokumen dibatalkan lewat dokumennya.')
            ->action(function (ConsumableItemMovement $record, array $data) {
                try {
                    $record->consumableItem->reverseLastMovement($record, auth()->id(), $data['reason']);
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
                        'adjustment' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => static::typeLabel($state)),
                Infolists\Components\TextEntry::make('quantity')->label('Jumlah')
                    ->formatStateUsing(fn ($state, ConsumableItemMovement $record) => ($state > 0 && $record->type === 'adjustment' ? '+' : '') . number_format((float) $state, 2) . ' ' . ($record->consumableItem?->unit ?? '')),
                Infolists\Components\TextEntry::make('unit_cost')->label('Harga/Satuan')->money('IDR')->placeholder('—')
                    ->visible(fn () => static::canSeeCost()),
                Infolists\Components\TextEntry::make('user.name')->label('Dicatat Oleh')->placeholder('—'),
                Infolists\Components\TextEntry::make('store.name')->label('Toko')->placeholder('—'),
                Infolists\Components\TextEntry::make('source_type')->label('Sumber')
                    ->state(fn (ConsumableItemMovement $record) => $record->sourceText())->placeholder('—'),
                Infolists\Components\TextEntry::make('note')->label('Catatan')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Barang')->columns(3)->schema([
                Infolists\Components\TextEntry::make('consumableItem.name')->label('Barang Habis Pakai')->placeholder('— (barang sudah dihapus)'),
                Infolists\Components\TextEntry::make('consumableItem.category')->label('Kategori')->placeholder('—'),
                Infolists\Components\TextEntry::make('consumableItem.current_stock')->label('Stok Sekarang')
                    ->formatStateUsing(fn ($state, ConsumableItemMovement $record) => number_format((float) $state, 2) . ' ' . ($record->consumableItem?->unit ?? '')),
            ]),
            Infolists\Components\Section::make('Pembatalan')->columns(3)
                ->visible(fn (ConsumableItemMovement $record) => $record->reversed_at !== null)
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
            'index' => Pages\ListConsumableItemMovements::route('/'),
            'view' => Pages\ViewConsumableItemMovement::route('/{record}'),
        ];
    }
}
