<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductImportLogResource\Pages;
use App\Models\ProductImportLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Riwayat Impor Produk" (audit Majoo, f27) — log append-only tiap kali
 * "Import Excel" dipakai di FilmProductResource. Read-only: tidak ada
 * create/edit/delete, cuma "Lihat Detail" untuk baca daftar error per
 * baris (lihat ProductBulkImportService).
 */
class ProductImportLogResource extends Resource
{
    protected static ?string $model = ProductImportLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = \App\Filament\Clusters\ProdukCluster::class;

    protected static ?string $navigationLabel = 'Riwayat Import Produk';

    protected static ?string $modelLabel = 'Riwayat Import';

    protected static ?string $pluralModelLabel = 'Riwayat Import';

    // 3: urutan 2 sudah dipakai Master Resep di cluster yang sama (urutan menu jadi tidak pasti).
    protected static ?int $navigationSort = 3;

    // Hanya akses penuh (sama dengan Import Excel di Daftar Produk). Dulu juga mewajibkan kotak menu, tetapi kotak itu tidak membuka apa pun
    // bagi staf biasa, jadi tidak lagi ditawarkan di Akses Menu.
    private static function accessGate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return static::accessGate();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('filename')->label('File'),
            TextEntry::make('user.name')->label('Diimpor Oleh')->placeholder('—'),
            TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y, H:i'),
            TextEntry::make('total_rows')->label('Total Baris'),
            TextEntry::make('updated_count')->label('Berhasil Diperbarui'),
            TextEntry::make('skipped_count')->label('Dilewati'),
            TextEntry::make('changes')
                ->label('Perubahan yang Dilakukan')
                ->visible(fn (ProductImportLog $record) => ! empty($record->changes))
                ->state(fn (ProductImportLog $record) => collect($record->changes)->map(
                    fn (array $c) => "{$c['sku']} ({$c['product']}) — {$c['field']}: {$c['old']} → {$c['new']}" . (! empty($c['note']) ? " [{$c['note']}]" : '')
                )->all())
                ->listWithLineBreaks()
                ->bulleted()
                ->columnSpanFull(),
            TextEntry::make('errors')
                ->label('Detail Baris Dilewati/Error')
                ->visible(fn (ProductImportLog $record) => ! empty($record->errors))
                // Satu error per baris (sebelumnya digabung dengan newline yang dilebur HTML jadi satu paragraf panjang).
                ->listWithLineBreaks()
                ->bulleted()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('filename')
                    ->label('File')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Diimpor Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('total_rows')
                    ->label('Total Baris')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('updated_count')
                    ->label('Diperbarui')
                    ->badge()
                    ->color('success')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('skipped_count')
                    ->label('Dilewati')
                    ->badge()
                    ->color(fn (ProductImportLog $record) => $record->skipped_count > 0 ? 'danger' : 'gray')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('changes_count')
                    ->label('Perubahan')
                    ->state(fn (ProductImportLog $record) => count($record->changes ?? []))
                    ->alignRight()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('has_skipped')
                    ->label('Ada baris dilewati')
                    ->toggle()
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('skipped_count', '>', 0)),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()->label('Lihat Detail'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductImportLogs::route('/'),
            'view' => Pages\ViewProductImportLog::route('/{record}'),
        ];
    }
}
