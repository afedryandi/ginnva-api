<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CaseStudyResource\Pages;
use App\Models\CaseStudy;
use App\Models\FilmProduct;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CaseStudyResource extends Resource
{
    protected static ?string $model = CaseStudy::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Konten';

    protected static ?string $navigationLabel = 'Galeri Pemasangan';

    protected static ?string $modelLabel = 'Galeri Pemasangan';

    protected static ?string $pluralModelLabel = 'Galeri Pemasangan';

    /**
     * Sama seperti VehicleResource/FilmProductResource — data master
     * company-wide, semua admin (super_admin & regional_admin) boleh
     * lihat & kelola, tidak ber-scope toko.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Pemasangan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('vehicle_id')
                        ->label('Kendaraan')
                        ->relationship('vehicle', 'model')
                        ->getOptionLabelFromRecordUsing(fn (Vehicle $record) => "{$record->brand} {$record->model}")
                        ->searchable(['brand', 'model'])
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('film_product_id')
                        ->label('Produk Film')
                        ->relationship('filmProduct', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\TextInput::make('title')
                        ->label('Judul Lengkap')
                        ->helperText('Ditampilkan saat foto ini jadi sorotan utama. Contoh: "Kaca Film · Zeekr 9X — Ginnva Ziwei 70"')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('short_title')
                        ->label('Judul Singkat')
                        ->helperText('Ditampilkan di thumbnail kecil. Contoh: "Zeekr 9X · Ziwei 70"')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('image')
                        ->label('Foto Hasil Pemasangan')
                        ->image()
                        ->directory('case-studies')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Tampilkan di Website')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('Foto')
                    ->square(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('vehicle.model')
                    ->label('Kendaraan')
                    ->formatStateUsing(fn ($record) => $record->vehicle ? "{$record->vehicle->brand} {$record->vehicle->model}" : '—'),

                Tables\Columns\TextColumn::make('filmProduct.name')
                    ->label('Produk')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Tampil')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            // reorderable() menambahkan drag-handle di tabel — urutan
            // baru langsung tersimpan ke kolom sort_order setiap kali
            // admin drag, tanpa perlu tombol simpan terpisah.
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCaseStudies::route('/'),
            'create' => Pages\CreateCaseStudy::route('/create'),
            'edit' => Pages\EditCaseStudy::route('/{record}/edit'),
        ];
    }
}