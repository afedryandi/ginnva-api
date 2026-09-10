<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MasterResepResource\Pages;
use App\Models\ConsumableItem;
use App\Models\FilmProduct;
use App\Models\RawMaterial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Master Resep" (BOM per Produk Film) — diminta 2026-09-10, analog
 * menu Majoo "Master Resep". Resource ini ANCHOR-nya ke FilmProduct
 * yang sudah ada (produk dibuat/dihapus di "Produk Film", di sini cuma
 * DIISI resepnya) — makanya tidak ada Create/Delete, cuma Edit.
 *
 * SENGAJA tanpa harga/HPP (keputusan Ginnva belum turun) — yang dicatat
 * cuma kuantitas bahan per 1x pemasangan. Lihat migrasi
 * 2026_09_10_000001 & memory project_penjualan_majoo_blocked_items.
 *
 * Konsumen data ini nanti: auto-isi Memo Barang (MaterialMemoResource)
 * saat booking punya film_product_id — BELUM dibangun, langkah
 * berikutnya.
 */
class MasterResepResource extends Resource
{
    protected static ?string $model = FilmProduct::class;

    protected static ?string $slug = 'master-resep';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $cluster = \App\Filament\Clusters\ProdukCluster::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Master Resep';

    protected static ?string $modelLabel = 'Resep Produk';

    protected static ?string $pluralModelLabel = 'Master Resep';

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
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('recipeItems');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Produk')
                ->columns(3)
                ->schema([
                    Forms\Components\Placeholder::make('info_sku')
                        ->label('SKU')
                        ->content(fn (FilmProduct $record) => $record->sku),
                    Forms\Components\Placeholder::make('info_name')
                        ->label('Nama Produk')
                        ->content(fn (FilmProduct $record) => $record->name),
                    Forms\Components\Placeholder::make('info_type')
                        ->label('Tipe')
                        ->content(fn (FilmProduct $record) => match ($record->product_type) {
                            'window_film' => 'Kaca Film',
                            'ppf' => 'PPF',
                            'detailing' => 'Detailing',
                            'color_change' => 'Ganti Warna',
                            default => $record->product_type,
                        }),
                ]),

            Forms\Components\Section::make('Bahan Standar per 1x Pemasangan')
                ->description('Perkiraan bahan yang terpakai untuk sekali pasang produk ini. Dipakai sebagai acuan auto-isi Memo Barang & perencanaan stok. Belum termasuk perhitungan biaya (HPP menyusul).')
                ->schema([
                    Forms\Components\Repeater::make('recipeItems')
                        ->relationship()
                        ->hiddenLabel()
                        ->addActionLabel('Tambah bahan')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columns(12)
                        ->itemLabel(fn (array $state): ?string => filled($state['item_name'] ?? null)
                            ? trim(($state['item_name']).' — '.($state['standard_qty'] ?? 0).' '.($state['unit'] ?? ''))
                            : 'Bahan baru')
                        ->schema([
                            Forms\Components\Select::make('item_type')
                                ->label('Jenis')
                                ->options([
                                    'raw_material' => 'Bahan Baku',
                                    'consumable_item' => 'Barang Habis Pakai',
                                    'film_roll' => 'Roll Film (meteran)',
                                ])
                                ->required()
                                ->live()
                                ->columnSpan(3)
                                ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                    $set('item_id', null);
                                    if ($state === 'film_roll') {
                                        $set('item_name', 'Roll Film');
                                        $set('unit', 'meter');
                                    } else {
                                        $set('item_name', null);
                                        $set('unit', null);
                                    }
                                }),

                            Forms\Components\Select::make('item_id')
                                ->label('Bahan')
                                ->options(fn (Forms\Get $get): array => match ($get('item_type')) {
                                    'raw_material' => RawMaterial::orderBy('name')->get()
                                        ->mapWithKeys(fn (RawMaterial $m) => [$m->id => "{$m->name} ({$m->code})"])->all(),
                                    'consumable_item' => ConsumableItem::orderBy('name')->get()
                                        ->mapWithKeys(fn (ConsumableItem $c) => [$c->id => "{$c->name} ({$c->code})"])->all(),
                                    default => [],
                                })
                                ->searchable()
                                ->preload()
                                ->visible(fn (Forms\Get $get): bool => in_array($get('item_type'), ['raw_material', 'consumable_item'], true))
                                ->required(fn (Forms\Get $get): bool => in_array($get('item_type'), ['raw_material', 'consumable_item'], true))
                                ->live()
                                ->columnSpan(5)
                                ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                    $model = match ($get('item_type')) {
                                        'raw_material' => RawMaterial::find($state),
                                        'consumable_item' => ConsumableItem::find($state),
                                        default => null,
                                    };
                                    $set('item_name', $model?->name);
                                    $set('unit', $model?->unit);
                                }),

                            Forms\Components\TextInput::make('standard_qty')
                                ->label('Jumlah')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->suffix(fn (Forms\Get $get) => $get('unit') ?: null)
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('note')
                                ->label('Catatan')
                                ->maxLength(255)
                                ->columnSpan(2),

                            Forms\Components\Hidden::make('item_name'),
                            Forms\Components\Hidden::make('unit'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Produk')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product_type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'window_film' => 'Kaca Film',
                        'ppf' => 'PPF',
                        'detailing' => 'Detailing',
                        'color_change' => 'Ganti Warna',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('position')
                    ->label('Posisi Kaca')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(function (?string $state, FilmProduct $record): string {
                        if ($record->product_type !== 'window_film') {
                            return '—';
                        }

                        return match ($state) {
                            'front' => 'Kaca Depan',
                            'side_rear' => 'Samping & Belakang',
                            default => '—',
                        };
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('recipe_items_count')
                    ->label('Isi Resep')
                    ->state(fn (FilmProduct $record): int => (int) ($record->recipe_items_count ?? 0))
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? "{$state} bahan" : 'Belum diisi'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type')
                    ->label('Tipe Produk')
                    ->options([
                        'window_film' => 'Kaca Film',
                        'ppf' => 'PPF',
                        'detailing' => 'Detailing',
                    ]),
                Tables\Filters\Filter::make('belum_diisi')
                    ->label('Belum ada resep')
                    ->query(fn (Builder $query) => $query->doesntHave('recipeItems')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Isi Resep'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterReseps::route('/'),
            'edit' => Pages\EditMasterResep::route('/{record}/edit'),
        ];
    }
}
