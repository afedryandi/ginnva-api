<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeaturedProductResource\Pages;
use App\Models\FeaturedProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FeaturedProductResource extends Resource
{
    protected static ?string $model = FeaturedProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?string $navigationLabel = 'Seri Produk (Beranda)';

    protected static ?string $modelLabel = 'Seri Produk';

    protected static ?string $pluralModelLabel = 'Seri Produk (Beranda)';

    protected static ?int $navigationSort = 15;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Kartu Seri Produk')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('image')
                        ->label('Gambar Beranda (Thumbnail)')
                        ->image()
                        ->directory('featured-products')
                        ->maxSize(3072)
                        ->helperText('Maks. 3 MB. Ditampilkan sebagai kartu di beranda — rasio ideal mengikuti kartu "热门商品", contoh: 800×1000 px.')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('content_image')
                        ->label('Gambar Isi (Detail)')
                        ->image()
                        ->directory('featured-products')
                        ->maxSize(5120)
                        ->helperText('Maks. 5 MB. Gambar yang ditampilkan UTUH saat customer tap kartunya — boleh beda dari gambar beranda (mis. lebih detail/resolusi tinggi). Kosongkan untuk pakai gambar beranda yang sama.')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('title')
                        ->label('Judul (opsional)')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('subtitle')
                        ->label('Sub-judul (opsional)')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('link_url')
                        ->label('Link (opsional)')
                        ->url()
                        ->placeholder('https://...')
                        ->maxLength(255)
                        ->helperText('URL yang dituju saat tombol "Lihat Selengkapnya" di kartu ini ditekan. Kosongkan jika tidak ada.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Tampilkan')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('Gambar')
                    ->width(80)
                    ->height(100),

                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->placeholder('—')
                    ->limit(40),

                Tables\Columns\TextColumn::make('link_url')
                    ->label('Link')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Tampil')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
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
            'index'  => Pages\ListFeaturedProducts::route('/'),
            'create' => Pages\CreateFeaturedProduct::route('/create'),
            'edit'   => Pages\EditFeaturedProduct::route('/{record}/edit'),
        ];
    }
}
