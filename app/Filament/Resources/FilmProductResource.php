<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FilmProductResource\Pages;
use App\Models\FilmProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FilmProductResource extends Resource
{
    protected static ?string $model = FilmProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Produk Film';

    protected static ?string $modelLabel = 'Produk Film';

    protected static ?string $pluralModelLabel = 'Produk Film';

    /**
     * Data master nasional, tidak ber-scope toko — super_admin dan
     * regional_admin (admin toko) sama-sama lihat & bisa edit semua baris.
     * Dipakai untuk dropdown pilihan produk di form quotation.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Produk')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama Produk')
                        ->placeholder('Contoh: Ginnva Ziwei 70')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('product_type')
                        ->label('Tipe Produk')
                        ->options([
                            'window_film' => 'Kaca Film',
                            'ppf' => 'Paint Protection Film (PPF)',
                        ])
                        ->live()
                        ->required(),

                    Forms\Components\Select::make('position')
                        ->label('Posisi Kaca')
                        ->options([
                            'front'     => 'Kaca Depan (Windshield)',
                            'side_rear' => 'Kaca Samping & Belakang',
                        ])
                        ->default('front')
                        ->visible(fn (Forms\Get $get) => $get('product_type') === 'window_film')
                        ->required(),

                    Forms\Components\TextInput::make('base_price')
                        ->label('Harga Dasar')
                        ->helperText('Referensi internal sales. Tidak ditampilkan ke customer — kalkulasi quotation memakai base_price × coefficient(vehicle_size, car_part).')
                        ->numeric()
                        ->prefix('Rp')
                        ->required(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif (tampil di pilihan quotation)')
                        ->default(true),
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
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_price')
                    ->label('Harga Dasar')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type')
                    ->label('Tipe Produk')
                    ->options([
                        'window_film' => 'Kaca Film',
                        'ppf' => 'PPF',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFilmProducts::route('/'),
            'create' => Pages\CreateFilmProduct::route('/create'),
            'edit' => Pages\EditFilmProduct::route('/{record}/edit'),
        ];
    }
}