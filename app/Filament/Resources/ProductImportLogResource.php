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

    protected static ?string $navigationLabel = 'Riwayat Impor Produk';

    protected static ?string $modelLabel = 'Riwayat Impor';

    protected static ?string $pluralModelLabel = 'Riwayat Impor';

    protected static ?int $navigationSort = 2;

    private static function accessGate(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false) && $user->hasMenuAccess(static::class);
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
            TextEntry::make('errors')
                ->label('Detail Baris Dilewati/Error')
                ->visible(fn (ProductImportLog $record) => ! empty($record->errors))
                ->formatStateUsing(fn (?array $state) => implode("\n", $state ?? []))
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
