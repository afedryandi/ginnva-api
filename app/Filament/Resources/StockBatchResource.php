<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\InventarisCluster;
use App\Filament\Resources\StockBatchResource\Pages;
use App\Models\StockBatch;
use App\Models\Supplier;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch Penerimaan (2026-11-23): daftar satu kali penerimaan barang, PPF/WF dan bahan baku, supaya keduanya bisa dibedakan dan
 * ditelusuri ke pemasok serta nomor fakturnya. Hanya baca: batch dibuat otomatis saat barang masuk (import, tambah produk, Catat Stok).
 */
class StockBatchResource extends Resource
{
    protected static ?string $model = StockBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $cluster = InventarisCluster::class;

    // Satu kelompok dengan menu Riwayat lain (2026-11-23).
    protected static ?string $navigationGroup = 'Riwayat';

    protected static ?string $navigationLabel = 'Batch Penerimaan';

    protected static ?string $modelLabel = 'Batch Penerimaan';

    protected static ?string $pluralModelLabel = 'Batch Penerimaan';

    protected static ?int $navigationSort = 103;

    /** Memuat pemasok dan nomor faktur, jadi hanya akses penuh atau staf yang menunya dicentang eksplisit. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
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
            ->with(['supplier:id,name', 'creator:id,name'])
            ->withCount(['scrollCodes', 'inventoryItems', 'rawMaterialBatches']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('batch_number')
                    ->label('No. Batch')
                    ->weight('semibold')
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StockBatch::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => $state === StockBatch::TYPE_PPF_WF ? 'info' : 'warning'),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Terima')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Pemasok')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('No. Faktur')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('contents')
                    ->label('Isi')
                    ->state(fn (StockBatch $record) => $record->type === StockBatch::TYPE_PPF_WF
                        ? "{$record->scroll_codes_count} gulungan, {$record->inventory_items_count} barang"
                        : "{$record->raw_material_batches_count} penerimaan bahan"),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(StockBatch::TYPE_LABELS),

                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Pemasok')
                    ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Tables\Filters\Filter::make('received')
                    ->label('Tanggal Terima')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dari'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('received_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('received_date', '<=', $d))),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Belum ada batch penerimaan')
            ->emptyStateDescription('Batch dibuat otomatis setiap kali barang masuk: import Excel, tambah produk PPF/WF, atau Catat Stok Masuk bahan baku.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockBatches::route('/'),
        ];
    }
}
