<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarouselResource\Pages;
use App\Models\Carousel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CarouselResource extends Resource
{
    protected static ?string $model = Carousel::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?string $navigationLabel = 'Banner / Carousel';

    protected static ?string $modelLabel = 'Banner';

    protected static ?string $pluralModelLabel = 'Banner / Carousel';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * SEBELUMNYA tidak ada override sama sekali di sini dan tidak ada
     * CarouselPolicy terdaftar — canCreate()/canEdit()/canDelete() bawaan
     * Resource selalu FALSE untuk siapa pun tanpa policy (default-deny
     * Laravel). Akibatnya tombol "New", "Edit", DAN "Hapus" tidak pernah
     * muncul — admin sama sekali tidak bisa kelola Banner/Carousel lewat
     * Filament. Ditemukan saat audit modul Marketing > Katalog Reward
     * (sama bug class yang berulang di beberapa Resource lain sesi ini).
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Banner')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('image')
                        ->label('Gambar Banner')
                        ->image()
                        ->directory('carousel')
                        ->maxSize(3072)
                        ->helperText('Maks. 3 MB. Rasio ideal: 2:1 (lebar × tinggi), contoh: 1200×600 px atau 800×400 px.')
                        ->required()
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
                        ->helperText('URL yang dituju saat banner diklik. Kosongkan jika tidak ada.'),

                    Forms\Components\Select::make('audience')
                        ->label('Tampil Untuk')
                        ->options([
                            'customer' => 'Customer (app utama)',
                            'partner'  => 'Partner (mitra referral)',
                            'both'     => 'Keduanya',
                        ])
                        ->default('customer')
                        ->required()
                        ->native(false),

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
                    ->width(120)
                    ->height(40),

                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->placeholder('—')
                    ->limit(40),

                Tables\Columns\TextColumn::make('link_url')
                    ->label('Link')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('audience')
                    ->label('Untuk')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'customer' => 'Customer',
                        'partner'  => 'Partner',
                        'both'     => 'Keduanya',
                        default    => $state,
                    })
                    ->colors([
                        'primary' => 'customer',
                        'warning' => 'partner',
                        'success' => 'both',
                    ]),

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
            'index'  => Pages\ListCarousels::route('/'),
            'create' => Pages\CreateCarousel::route('/create'),
            'edit'   => Pages\EditCarousel::route('/{record}/edit'),
        ];
    }
}
