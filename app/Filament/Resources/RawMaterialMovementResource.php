<?php

namespace App\Filament\Resources;

use App\Exports\RawMaterialMovementExport;
use App\Filament\Resources\RawMaterialMovementResource\Pages;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

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

                // Harga batch yang tersalin saat "Catat Masuk" — supaya
                // tidak perlu buka tab Batch terpisah dan cocokkan tanggal
                // manual untuk tahu harga beli suatu kejadian masuk.
                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
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
            ->headerActions([
                // Ikut filter yang sedang aktif di layar — sama pola
                // dengan InventoryMovementResource (PPF/WF).
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn ($livewire) => Excel::download(
                        new RawMaterialMovementExport($livewire->getFilteredTableQuery()),
                        'riwayat-bahan-baku-' . now()->format('Ymd') . '.xlsx'
                    )),

                // Bandingkan current_stock tiap bahan dengan penjumlahan
                // seluruh riwayat movement-nya dari 0 — mendeteksi drift
                // kalau ada manipulasi manual DB atau bug lain di masa
                // depan (recordMovement()/adjustStock() sendiri sudah
                // dijaga transaction+lock, jadi drift seharusnya tidak
                // pernah terjadi lewat jalur normal aplikasi).
                Tables\Actions\Action::make('reconcile')
                    ->label('Cek Rekonsiliasi')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->action(function () {
                        $mismatches = [];

                        RawMaterial::query()->chunkById(100, function ($materials) use (&$mismatches) {
                            foreach ($materials as $material) {
                                $computed = (float) $material->movements()
                                    ->selectRaw("SUM(CASE WHEN type = 'out' THEN -quantity ELSE quantity END) as total")
                                    ->value('total');

                                $diff = round((float) $material->current_stock - $computed, 2);

                                if (abs($diff) > 0.01) {
                                    $mismatches[] = "{$material->name}: sistem {$material->current_stock}, seharusnya {$computed} (selisih {$diff})";
                                }
                            }
                        });

                        if (empty($mismatches)) {
                            Notification::make()
                                ->title('Rekonsiliasi bersih')
                                ->body('current_stock semua bahan baku cocok dengan penjumlahan riwayat movement-nya.')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(count($mismatches) . ' bahan baku tidak cocok')
                            ->body(implode("\n", array_slice($mismatches, 0, 15)) . (count($mismatches) > 15 ? "\n… dan " . (count($mismatches) - 15) . ' lainnya.' : ''))
                            ->danger()
                            ->persistent()
                            ->send();
                    }),
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
