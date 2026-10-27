<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleGlassAreaResource\Pages;
use App\Models\VehicleGlassArea;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Luas Kaca" (Master Data Fase 2, 2026-10-21): luas kaca (m²) per merek+model untuk menghitung kebutuhan bahan dan harga kaca film.
 * Sumber data di database; Google Sheet "Data Ukuran" hanya untuk impor (aksi di daftar). Pemilik data = akses penuh (Master Data Fase 4):
 * staf lain hanya melihat, kecuali diberi izin aksi secara eksplisit di Hak Akses Detail. Impor dari Sheet hanya akses penuh.
 */
class VehicleGlassAreaResource extends Resource
{
    protected static ?string $model = VehicleGlassArea::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $cluster = \App\Filament\Clusters\MasterDataCluster::class;

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Luas Kaca';

    protected static ?string $modelLabel = 'Luas Kaca';

    protected static ?string $pluralModelLabel = 'Luas Kaca';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(static::class);
    }

    /** Mengubah luas kaca memengaruhi harga dan bahan di semua toko: akses penuh, atau izin aksi eksplisit di Hak Akses Detail. */
    private static function may(string $action): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, $action, false) ?? false);
    }

    public static function canCreate(): bool
    {
        return static::may('create');
    }

    public static function canEdit($record): bool
    {
        return static::may('update');
    }

    public static function canDelete($record): bool
    {
        return static::may('delete');
    }

    public static function canDeleteAny(): bool
    {
        return static::may('delete');
    }

    public static function form(Form $form): Form
    {
        $sqm = fn (string $name, string $label) => Forms\Components\TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(99)
            ->step(0.001)
            ->default(0)
            ->suffix('m²')
            ->required();

        return $form->schema([
            Forms\Components\Section::make('Kendaraan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('brand')->label('Merek')->required()->maxLength(80),
                    Forms\Components\TextInput::make('model')
                        ->label('Tipe / Model')
                        ->required()
                        ->maxLength(120)
                        ->helperText('Samakan penulisannya dengan menu Kendaraan agar terhubung ke katalog. Huruf besar/kecil dan spasi ganda tidak membedakan.'),
                ]),
            Forms\Components\Section::make('Luas kaca (m²)')
                ->columns(4)
                ->schema([
                    $sqm('front_sqm', 'Depan'),
                    $sqm('side_sqm', 'Samping (total)'),
                    $sqm('rear_sqm', 'Belakang'),
                    $sqm('sunroof_sqm', 'Sunroof'),
                    Forms\Components\TextInput::make('notes')->label('Catatan')->maxLength(255)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('brand')->label('Merek')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('model')->label('Tipe / Model')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('front_sqm')->label('Depan')->numeric(3)->alignEnd(),
                Tables\Columns\TextColumn::make('side_sqm')->label('Samping')->numeric(3)->alignEnd(),
                Tables\Columns\TextColumn::make('rear_sqm')->label('Belakang')->numeric(3)->alignEnd(),
                Tables\Columns\TextColumn::make('sunroof_sqm')->label('Sunroof')->numeric(3)->alignEnd(),
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (VehicleGlassArea $record) => $record->totalSqm())
                    ->numeric(3)
                    ->alignEnd(),
                Tables\Columns\IconColumn::make('in_catalog')
                    ->label('Di katalog')
                    ->state(fn (VehicleGlassArea $record) => $record->catalogVehicleCount() > 0)
                    ->boolean()
                    ->tooltip('Ada kendaraan dengan merek+model yang sama di menu Kendaraan'),
                Tables\Columns\TextColumn::make('source')
                    ->label('Sumber')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'import' ? 'Import' : 'Manual')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->label('Sumber')->options(['import' => 'Import', 'manual' => 'Manual']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('brand')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleGlassAreas::route('/'),
            'create' => Pages\CreateVehicleGlassArea::route('/create'),
            'edit' => Pages\EditVehicleGlassArea::route('/{record}/edit'),
        ];
    }
}
