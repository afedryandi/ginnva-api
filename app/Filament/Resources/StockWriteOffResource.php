<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockWriteOffResource\Pages;
use App\Models\StockWriteOff;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        return parent::getEloquentQuery()->with(['creator', 'journalEntry']);
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
                    ->description(fn (StockWriteOff $record) => $record->note ?: null),

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
                    ->tooltip(fn (StockWriteOff $record) => $record->journal_entry_id ? 'Jurnal kerugian diposting' : 'Tidak ada jurnal (nilai kosong)'),

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
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockWriteOffs::route('/'),
        ];
    }
}
