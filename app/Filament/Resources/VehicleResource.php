<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Kendaraan';

    protected static ?string $modelLabel = 'Kendaraan';

    protected static ?string $pluralModelLabel = 'Kendaraan';

    /**
     * Data master nasional, tidak ber-scope toko — super_admin dan
     * regional_admin (admin toko) sama-sama lihat & bisa edit semua baris.
     * Dipakai untuk dropdown pilihan kendaraan di form quotation.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kendaraan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('brand')
                        ->label('Merek')
                        ->placeholder('Contoh: Honda, Toyota')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('model')
                        ->label('Tipe / Model')
                        ->placeholder('Contoh: Civic, Alphard (opsional — isi jika sudah diketahui)')
                        ->nullable()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('variant')
                        ->label('Varian')
                        ->placeholder('Contoh: 1.5 RS CVT, 2.5 G AT')
                        ->nullable()
                        ->maxLength(255),

                    Forms\Components\Select::make('size_category')
                        ->label('Kategori Ukuran')
                        ->helperText('Menentukan koefisien harga (base_price × coefficient) saat kalkulasi quotation.')
                        ->options([
                            'S'   => 'S — Kecil (city car, hatchback)',
                            'M'   => 'M — Sedang (sedan, MPV kecil)',
                            'L'   => 'L — Besar (SUV, MPV besar)',
                            'XL'  => 'XL — Sangat Besar (double cabin, van)',
                            'XXL' => 'XXL — Ekstra Besar (sport car, luxury)',
                        ])
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('brand')
                    ->label('Merek')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('model')
                    ->label('Tipe / Model')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('variant')
                    ->label('Varian')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('size_category')
                    ->label('Ukuran')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('size_category')
                    ->label('Kategori Ukuran')
                    ->options([
                        'S'   => 'S',
                        'M'   => 'M',
                        'L'   => 'L',
                        'XL'  => 'XL',
                        'XXL' => 'XXL',
                    ]),
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
            ->defaultSort('brand')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}