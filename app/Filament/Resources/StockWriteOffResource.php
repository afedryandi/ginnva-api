<?php

namespace App\Filament\Resources;

use App\Exports\StockWriteOffExport;
use App\Filament\Resources\StockWriteOffResource\Pages;
use App\Models\StockWriteOff;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
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
        // SEBELUMNYA tidak ada filter store_id di sini sama sekali (bug
        // ditemukan lewat audit framework 2026-09-14, "Isolasi data
        // multi-tenant") — staf non-full-access bisa melihat write-off
        // stok toko lain. Global Scope di model StockWriteOff (lihat
        // App\Models\Scopes\StoreScope) sekarang jadi proteksi utamanya;
        // filter manual di sini tetap ditulis eksplisit supaya konsisten
        // dengan pola di seluruh Resource lain & tidak diam-diam
        // bergantung sepenuhnya pada Global Scope.
        $query = parent::getEloquentQuery()->with(['creator', 'journalEntry']);
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->url(fn (StockWriteOff $record) => $record->writeoffable_type === 'raw_material'
                        ? RawMaterialResource::getUrl('edit', ['record' => $record->writeoffable_id])
                        : ConsumableItemResource::getUrl('edit', ['record' => $record->writeoffable_id]))
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
                    ->money('IDR')
                    ->placeholder('Tidak dinilai (harga modal kosong)')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->money('IDR')),

                Tables\Columns\IconColumn::make('journal_entry_id')
                    ->label('Jurnal')
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
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('writeoffable_type')
                    ->label('Jenis')
                    ->options([
                        'raw_material' => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                    ]),

                Tables\Filters\SelectFilter::make('reason')
                    ->label('Alasan')
                    ->options(StockWriteOff::REASON_LABELS),

                Tables\Filters\SelectFilter::make('created_by')
                    ->label('Oleh')
                    ->options(fn () => User::whereIn('id', StockWriteOff::whereNotNull('created_by')->distinct()->pluck('created_by'))->pluck('name', 'id')),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
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
                            new StockWriteOffExport($livewire->getFilteredTableQuery()),
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
                            ->with(['creator', 'journalEntry'])
                            ->reorder('created_at', 'desc')
                            ->get();

                        $pdf = Pdf::loadView('pdf.stock_write_offs', ['writeOffs' => $writeOffs])->setPaper('a4', 'landscape');
                        $filename = 'stok-terbuang-' . now()->format('Ymd-His') . '.pdf';

                        return response()->streamDownload(fn () => print($pdf->output()), $filename);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Log ekspor (audit Stok Terbuang 2026-09-30), konsisten dengan laporan lain. */
    private static function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'stock_write_off', 'format' => $format])
                ->log('Ekspor Stok Terbuang (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockWriteOffs::route('/'),
        ];
    }
}
