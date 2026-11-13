<?php

namespace App\Filament\Resources;

use App\Exports\StockWriteOffExport;
use App\Filament\Resources\StockWriteOffResource\Pages;
use App\Models\Store;
use App\Models\StockWriteOff;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
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
 * "Stok Terbuang" — daftar write-off barang rusak/kedaluwarsa/hilang
 * (analog menu Majoo "Kelola Stok > Stok Terbuang"). Read-only murni:
 * baris dibuat lewat aksi "Catat Stok Terbuang" di Daftar Bahan Baku /
 * Barang Habis Pakai (StockWriteOffService). Lihat migrasi
 * 2026_09_10_000010.
 */
class StockWriteOffResource extends Resource
{
    protected static ?string $model = StockWriteOff::class;

    protected static ?string $navigationIcon = 'heroicon-o-trash';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?string $navigationGroup = 'Kelola Stok';

    protected static ?int $navigationSort = 111;

    protected static ?string $navigationLabel = 'Stok Terbuang';

    protected static ?string $modelLabel = 'Stok Terbuang';

    protected static ?string $pluralModelLabel = 'Stok Terbuang';

    /** Eksplisit (bukan akses bawaan): daftar kerugian stok dan pelakunya (audit Stok Terbuang 2026-11-03). */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasExplicitMenuAccess(static::class);
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /** Nilai kerugian (rupiah) hanya untuk akses penuh atau pemegang izin Lihat pada menu ini. */
    public static function canSeeValue(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
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
        // SEBELUMNYA tidak ada filter store_id di sini sama sekali (bug
        // ditemukan lewat audit framework 2026-09-14, "Isolasi data
        // multi-tenant") — staf non-full-access bisa melihat write-off
        // stok toko lain. Global Scope di model StockWriteOff (lihat
        // App\Models\Scopes\StoreScope) sekarang jadi proteksi utamanya;
        // filter manual di sini tetap ditulis eksplisit supaya konsisten
        // dengan pola di seluruh Resource lain & tidak diam-diam
        // bergantung sepenuhnya pada Global Scope.
        // Yang dibatalkan tetap tampil di menu ini (ditandai), tetapi tersembunyi dari ringkasan lain lewat scope 'effective'.
        $query = parent::getEloquentQuery()->withoutGlobalScope('effective')->with(['creator', 'journalEntry', 'store', 'canceller']);
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn (StockWriteOff $record) => $record->cancelled_at !== null ? 'opacity-60' : null)
            ->columns([
                Tables\Columns\TextColumn::make('write_off_number')
                    ->label('Nomor')
                    ->searchable()
                    ->weight('bold')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('item_name')
                    ->label('Barang')
                    ->searchable()
                    ->description(fn (StockWriteOff $record) => $record->note ?: null)
                    // Drill-down ke halaman edit item terkait (audit 2026-09-30).
                    ->url(fn (StockWriteOff $record) => static::itemUrl($record))
                    ->color('primary'),

                Tables\Columns\TextColumn::make('writeoffable_type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'raw_material' ? 'primary' : 'warning')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'raw_material' => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->formatStateUsing(fn ($state, StockWriteOff $record) => number_format((float) $state, 2) . ' ' . ($record->unit ?? '')),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'damaged' => 'danger',
                        'expired' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => StockWriteOff::REASON_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('total_value')
                    ->label('Nilai Kerugian')
                    ->visible(fn () => static::canSeeValue())
                    ->money('IDR')
                    ->placeholder('Tidak dinilai (harga modal kosong)')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->money('IDR')),

                Tables\Columns\IconColumn::make('journal_entry_id')
                    ->label('Jurnal')
                    ->visible(fn () => (bool) config('features.finance'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->tooltip(fn (StockWriteOff $record) => $record->journal_entry_id ? 'Jurnal kerugian diposting — klik untuk lihat' : 'Tidak ada jurnal (nilai kosong)')
                    // Drill-down ke jurnal (audit 2026-09-30).
                    ->url(fn (StockWriteOff $record) => $record->journal_entry_id
                        ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id])
                        : null),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Oleh')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cancel_state')
                    ->label('Status')
                    ->state(fn (StockWriteOff $record) => $record->cancelled_at !== null ? 'Dibatalkan' : null)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn (StockWriteOff $record) => $record->cancelled_at !== null
                        ? 'Dibatalkan' . ($record->canceller ? " oleh {$record->canceller->name}" : '') . ' pada ' . $record->cancelled_at->format('d M Y, H:i') . ($record->cancelled_reason ? ". Alasan: {$record->cancelled_reason}" : '')
                        : null),            ])
            ->filters([
                Tables\Filters\SelectFilter::make('writeoffable_type')
                    ->label('Jenis')
                    ->options(\App\Support\HiddenInventoryTypes::filter([
                        'raw_material' => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                    ])),

                Tables\Filters\SelectFilter::make('reason')
                    ->label('Alasan')
                    ->options(StockWriteOff::REASON_LABELS),

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

                Tables\Filters\TernaryFilter::make('cancelled')
                    ->label('Dibatalkan')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya yang dibatalkan')
                    ->falseLabel('Sembunyikan yang dibatalkan')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('cancelled_at'),
                        false: fn (Builder $query) => $query->whereNull('cancelled_at'),
                        blank: fn (Builder $query) => $query,
                    ),

                Tables\Filters\SelectFilter::make('created_by')
                    ->label('Oleh')
                    ->options(fn () => User::whereIn('id', StockWriteOff::whereNotNull('created_by')->distinct()->pluck('created_by'))->pluck('name', 'id')),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->headerActions([
                // Filter-aware, sama pola dengan InventoryMovementResource/
                // RawMaterialMovementResource/ConsumableItemMovementResource
                // (audit 2026-09-14, temuan pola standar) — ikut filter
                // yang sedang aktif di layar.
                Tables\Actions\Action::make('exportExcel')
                    ->label('Export ke Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function ($livewire) {
                        static::logExport('xlsx');

                        return Excel::download(
                            new StockWriteOffExport($livewire->getFilteredTableQuery(), static::canSeeValue()),
                            'stok-terbuang-' . now()->format('Ymd-His') . '.xlsx'
                        );
                    }),

                Tables\Actions\Action::make('exportPdf')
                    ->label('Export ke PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(function ($livewire) {
                        static::logExport('pdf');

                        $writeOffs = $livewire->getFilteredTableQuery()
                            ->with(['creator', 'journalEntry', 'store'])
                            ->reorder('created_at', 'desc')
                            ->get();

                        $pdf = Pdf::loadView('pdf.stock_write_offs', ['writeOffs' => $writeOffs, 'showValue' => static::canSeeValue()])->setPaper('a4', 'landscape');
                        $filename = 'stok-terbuang-' . now()->format('Ymd-His') . '.pdf';

                        return response()->streamDownload(fn () => print($pdf->output()), $filename);
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (StockWriteOff $record) => (auth()->user()?->isFullAccess() ?? false) && $record->cancelled_at === null)
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan pembatalan')
                            ->required()
                            ->maxLength(500)
                            ->rows(2)
                            ->helperText('Mis. salah memilih barang atau salah memasukkan jumlah.'),
                    ])
                    ->modalHeading('Batalkan stok terbuang ini?')
                    ->modalDescription('Stok dikembalikan sejumlah yang dibuang (bahan baku dipulihkan sebagai batch "tidak diketahui asalnya", tanpa kedaluwarsa), jurnal kerugian dibalik bila ada, dan pergerakan stoknya ditandai dibatalkan. Baris ini tetap tersimpan sebagai "Dibatalkan".')
                    ->action(function (StockWriteOff $record, array $data) {
                        try {
                            app(\App\Services\StockWriteOffService::class)->cancel($record, auth()->id(), $data['reason']);
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Tidak bisa membatalkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Stok terbuang dibatalkan')->body('Stok dipulihkan.')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Tautan ke halaman Lihat barangnya, hanya bila barangnya masih ada dan akun ini boleh membukanya (tanpa itu 404/403). */
    public static function itemUrl(StockWriteOff $record): ?string
    {
        if ($record->writeoffable_type === 'raw_material') {
            return RawMaterialResource::canViewAny() && \App\Models\RawMaterial::whereKey($record->writeoffable_id)->exists()
                ? RawMaterialResource::getUrl('view', ['record' => $record->writeoffable_id])
                : null;
        }

        return ConsumableItemResource::canViewAny() && \App\Models\ConsumableItem::whereKey($record->writeoffable_id)->exists()
            ? ConsumableItemResource::getUrl('view', ['record' => $record->writeoffable_id])
            : null;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Stok Terbuang')->columns(3)->schema([
                Infolists\Components\TextEntry::make('write_off_number')->label('Nomor')->placeholder('—'),
                Infolists\Components\TextEntry::make('created_at')->label('Tanggal')->dateTime('d M Y, H:i'),
                Infolists\Components\TextEntry::make('reason')->label('Alasan')->badge()
                    ->formatStateUsing(fn (string $state) => StockWriteOff::REASON_LABELS[$state] ?? $state),
                Infolists\Components\TextEntry::make('item_name')->label('Barang'),
                Infolists\Components\TextEntry::make('writeoffable_type')->label('Jenis')
                    ->formatStateUsing(fn (string $state) => $state === 'raw_material' ? 'Bahan Baku' : 'Barang Habis Pakai'),
                Infolists\Components\TextEntry::make('quantity')->label('Jumlah')
                    ->formatStateUsing(fn ($state, StockWriteOff $record) => number_format((float) $state, 2) . ' ' . ($record->unit ?? '')),
                Infolists\Components\TextEntry::make('unit_cost')->label('Harga/Satuan')->money('IDR')->placeholder('—')
                    ->visible(fn () => static::canSeeValue()),
                Infolists\Components\TextEntry::make('total_value')->label('Nilai Kerugian')->money('IDR')->placeholder('Tidak dinilai (harga modal kosong)')
                    ->visible(fn () => static::canSeeValue()),
                Infolists\Components\TextEntry::make('journal_entry_id')->label('Jurnal')
                    ->visible(fn () => (bool) config('features.finance') && static::canSeeValue())
                    ->state(fn (StockWriteOff $record) => $record->journalEntry?->entry_number ?? ($record->journal_entry_id ? '#' . $record->journal_entry_id : 'Tidak ada jurnal'))
                    ->url(fn (StockWriteOff $record) => $record->journal_entry_id && JournalEntryResource::canViewAny()
                        ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id])
                        : null),
                Infolists\Components\TextEntry::make('creator.name')->label('Dicatat Oleh')->placeholder('—'),
                Infolists\Components\TextEntry::make('store.name')->label('Toko')->placeholder('—'),
                Infolists\Components\TextEntry::make('note')->label('Catatan')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Pembatalan')->columns(3)
                ->visible(fn (StockWriteOff $record) => $record->cancelled_at !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('cancelled_at')->label('Dibatalkan Pada')->dateTime('d M Y, H:i'),
                    Infolists\Components\TextEntry::make('canceller.name')->label('Dibatalkan Oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('cancelled_reason')->label('Alasan')->placeholder('—'),
                ]),
        ]);
    }

    /** Log ekspor (audit Stok Terbuang 2026-09-30), konsisten dengan laporan lain. */
    private static function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'stock_write_off', 'format' => $format])
                ->log('Export Stok Terbuang (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockWriteOffs::route('/'),
            'view' => Pages\ViewStockWriteOff::route('/{record}'),
        ];
    }
}
