<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RollScrapPoolResource\Pages;
use App\Filament\Resources\RollScrapPoolResource\RelationManagers\MovementsRelationManager;
use App\Models\FilmProduct;
use App\Models\RollScrapPool;
use App\Models\Store;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Sisa Roll" — diminta 2026-09-14. Daftar pool sisa panjang + potongan
 * lebar (masih bisa dipakai) yang sudah dikumpulkan dari berbagai
 * ScrollCode lewat aksi "Kumpulkan Sisa" (InventoryItemResource), per
 * (toko, produk film). Tidak ada Create/Edit manual — pool dibuat
 * otomatis oleh RollScrapPool::collectFrom() begitu staff pertama kali
 * kumpulkan sisa untuk kombinasi toko+produk itu.
 */
class RollScrapPoolResource extends Resource
{
    protected static ?string $model = RollScrapPool::class;

    protected static ?string $navigationIcon = 'heroicon-o-scissors';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Sisa Roll';

    protected static ?string $modelLabel = 'Sisa Roll';

    protected static ?string $pluralModelLabel = 'Sisa Roll';

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
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * Per-toko — staff non-full-access cuma lihat pool sisa milik
     * tokonya sendiri, sama pola dengan ScrollCodeResource.
     */
    public static function getEloquentQuery(): Builder
    {
        // 'movements' di-filter cuma type='in' -- itu yang punya
        // source_scroll_code_id, dipakai kolom "Kode Gulungan" di
        // table() di bawah (diminta 2026-09-14). Eager-load supaya tidak
        // N+1 per baris.
        $query = parent::getEloquentQuery()->with([
            'store',
            'filmProduct',
            'movements' => fn ($q) => $q->where('type', 'in')->with('sourceScrollCode:id,code'),
        ]);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    private static function canActOnPool(RollScrapPool $pool): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false) || $pool->store_id === $user?->store_id;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('filmProduct.name')
                    ->label('Produk Film')
                    ->formatStateUsing(fn ($state, RollScrapPool $record) => $record->filmProduct
                        ? "{$record->filmProduct->sku} — {$record->filmProduct->name}"
                        : '—')
                    ->searchable(),

                // Diminta 2026-09-14 -- kode gulungan ASAL yang sisanya
                // sudah dikumpulkan ke pool ini (bisa >1), diambil dari
                // movement type='in' (lihat getEloquentQuery() di atas).
                Tables\Columns\TextColumn::make('scroll_codes')
                    ->label('Kode Gulungan')
                    ->state(fn (RollScrapPool $record) => $record->movements
                        ->pluck('sourceScrollCode.code')
                        ->filter()
                        ->unique()
                        ->implode(', '))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining_length_meters')
                    ->label('Sisa')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2) . ' m')
                    ->badge()
                    ->color(fn (RollScrapPool $record) => (float) $record->remaining_length_meters > 0 ? 'success' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),

                Tables\Filters\SelectFilter::make('film_product_id')
                    ->label('Produk Film')
                    ->options(fn () => FilmProduct::orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('consume')
                    ->label('Catat Pemakaian')
                    ->icon('heroicon-o-scissors')
                    ->color('warning')
                    ->visible(fn (RollScrapPool $record) => (float) $record->remaining_length_meters > 0
                        && static::canActOnPool($record))
                    ->form(fn (RollScrapPool $record): array => [
                        Forms\Components\TextInput::make('meters')
                            ->label('Meter Dipakai')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix('meter')
                            ->helperText(fn (RollScrapPool $record) => 'Sisa pool saat ini: ' . number_format((float) $record->remaining_length_meters, 2) . ' meter.'),

                        // BUKAN ->relationship() -- ini form Action, bukan
                        // form record RollScrapPool (yang tidak punya
                        // relasi booking() sama sekali, booking_id cuma
                        // kolom di RollScrapMovement, bukan di pool-nya).
                        // Opsi diisi manual dari query Booking langsung.
                        Forms\Components\Select::make('booking_id')
                            ->label('Booking Terkait (opsional)')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) use ($record) {
                                $user = auth()->user();

                                return \App\Models\Booking::query()
                                    ->where('store_id', $record->store_id)
                                    ->when(! ($user?->isFullAccess() ?? false), fn ($q) => $q->where('store_id', $user?->store_id))
                                    ->where(fn ($q) => $q->where('booking_number', 'like', "%{$search}%")
                                        ->orWhere('customer_name', 'like', "%{$search}%"))
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn ($b) => [$b->id => "{$b->booking_number} — {$b->customer_name}"]);
                            })
                            ->getOptionLabelUsing(fn ($value) => \App\Models\Booking::find($value)?->booking_number),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan (opsional)'),
                    ])
                    ->action(function (RollScrapPool $record, array $data) {
                        if (! static::canActOnPool($record)) {
                            Notification::make()
                                ->title('Tidak bisa mencatat pemakaian')
                                ->body('Pool sisa ini milik toko lain.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $record->consume(
                                (float) $data['meters'],
                                auth()->id(),
                                $data['note'] ?? null,
                                $data['booking_id'] ?? null,
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa mencatat pemakaian')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pemakaian dicatat')->success()->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (RollScrapPool $record) => (float) $record->remaining_length_meters <= 0),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Belum ada sisa roll dikumpulkan')
            ->emptyStateDescription('Kumpulkan sisa lewat aksi "Kumpulkan Sisa" di menu Produk PPF/WF, per kode gulungan yang sudah selesai dipakai.');
    }

    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRollScrapPools::route('/'),
        ];
    }
}
