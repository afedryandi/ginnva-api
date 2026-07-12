<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceRuleResource\Pages;
use App\Models\PriceRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PriceRuleResource extends Resource
{
    protected static ?string $model = PriceRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Koefisien Harga';

    protected static ?string $modelLabel = 'Koefisien Harga';

    protected static ?string $pluralModelLabel = 'Koefisien Harga';

    protected static ?int $navigationSort = 30;

    /**
     * Hanya super_admin yang boleh ubah — ini data master pricing nasional,
     * bukan per-toko. regional_admin tidak perlu akses ke sini.
     */
    // Disembunyikan dari navigasi — kalkulasi harga belum diimplementasikan
    // di quotation flow. Aktifkan kembali saat fitur harga otomatis siap.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Aturan Harga')
                ->columns(2)
                ->description('Koefisien dipakai dalam kalkulasi quotation: Harga = base_price_produk × koefisien')
                ->schema([
                    Forms\Components\Select::make('vehicle_size')
                        ->label('Ukuran Kendaraan')
                        ->options([
                            'S'   => 'S — City Car (Agya, Brio, March)',
                            'M'   => 'M — Sedan / Hatchback (Civic, Yaris)',
                            'L'   => 'L — SUV / MPV (Fortuner, Innova)',
                            'XL'  => 'XL — Large SUV / Van (Alphard, Hiace)',
                            'XXL' => 'XXL — Bus / Truck',
                        ])
                        ->required(),

                    Forms\Components\Select::make('car_part')
                        ->label('Area Kaca / Bagian')
                        ->options([
                            'front'    => 'Kaca Depan',
                            'back'     => 'Kaca Belakang',
                            'side'     => 'Kaca Samping',
                            'full_set' => 'Full Set (semua kaca)',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('coefficient')
                        ->label('Koefisien')
                        ->helperText('Pengali harga dasar. Contoh: 1.00 = sama, 1.20 = +20%, 0.80 = -20%')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0.01)
                        ->required()
                        ->suffix('×'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('vehicle_size')
                    ->label('Ukuran')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'S'   => 'gray',
                        'M'   => 'info',
                        'L'   => 'warning',
                        'XL'  => 'danger',
                        'XXL' => 'purple',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('car_part')
                    ->label('Area / Bagian')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'front'    => 'Kaca Depan',
                        'back'     => 'Kaca Belakang',
                        'side'     => 'Kaca Samping',
                        'full_set' => 'Full Set',
                        default    => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('coefficient')
                    ->label('Koefisien')
                    ->suffix('×')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('vehicle_size')
                    ->label('Ukuran')
                    ->options([
                        'S'   => 'S',
                        'M'   => 'M',
                        'L'   => 'L',
                        'XL'  => 'XL',
                        'XXL' => 'XXL',
                    ]),

                Tables\Filters\SelectFilter::make('car_part')
                    ->label('Area')
                    ->options([
                        'front'    => 'Kaca Depan',
                        'back'     => 'Kaca Belakang',
                        'side'     => 'Kaca Samping',
                        'full_set' => 'Full Set',
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
            ->defaultSort('vehicle_size')
            ->groups([
                Tables\Grouping\Group::make('vehicle_size')
                    ->label('Ukuran Kendaraan')
                    ->collapsible(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPriceRules::route('/'),
            'create' => Pages\CreatePriceRule::route('/create'),
            'edit'   => Pages\EditPriceRule::route('/{record}/edit'),
        ];
    }
}
