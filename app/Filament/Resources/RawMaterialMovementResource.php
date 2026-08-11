<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RawMaterialMovementResource\Pages;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan/riwayat pergerakan bahan baku LINTAS SEMUA bahan — beda dari
 * MovementsRelationManager di RawMaterialResource yang cuma nampilkan
 * riwayat 1 bahan tertentu. Read-only murni, sama seperti pola
 * InventoryMovementResource.
 */
class RawMaterialMovementResource extends Resource
{
    protected static ?string $model = RawMaterialMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Riwayat Bahan Baku';

    protected static ?string $modelLabel = 'Riwayat Bahan Baku';

    protected static ?string $pluralModelLabel = 'Riwayat Bahan Baku';

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
        return parent::getEloquentQuery()->with(['rawMaterial', 'user']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rawMaterial.name')
                    ->label('Bahan Baku')
                    ->searchable()
                    ->placeholder('— (bahan sudah dihapus)'),

                Tables\Columns\TextColumn::make('rawMaterial.category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'in',
                        'danger'  => 'out',
                        'warning' => 'adjustment',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in' => 'Masuk',
                        'out' => 'Keluar',
                        'adjustment' => 'Penyesuaian (Opname)',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->formatStateUsing(fn ($state, RawMaterialMovement $record) => ($state > 0 && $record->type === 'adjustment' ? '+' : '') . number_format((float) $state, 2) . ' ' . ($record->rawMaterial?->unit ?? '')),

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
                    ->options(['in' => 'Masuk', 'out' => 'Keluar', 'adjustment' => 'Penyesuaian (Opname)']),

                Tables\Filters\SelectFilter::make('raw_material_id')
                    ->label('Bahan Baku')
                    ->options(fn () => RawMaterial::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Dicatat Oleh')
                    ->options(fn () => User::whereIn(
                        'id',
                        RawMaterialMovement::whereNotNull('user_id')->distinct()->pluck('user_id')
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
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRawMaterialMovements::route('/'),
        ];
    }
}
