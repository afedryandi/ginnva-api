<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FilmProductResource\Pages;
use App\Models\FilmProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;

class FilmProductResource extends Resource
{
    protected static ?string $model = FilmProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Produk Film';

    protected static ?string $modelLabel = 'Produk Film';

    protected static ?string $pluralModelLabel = 'Produk Film';

    /**
     * Data master nasional, tidak ber-scope toko — super_admin dan
     * staff toko sama-sama lihat & bisa edit semua baris.
     * Dipakai untuk dropdown pilihan produk di form quotation.
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
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
                        ->placeholder('Contoh: Ginnva A70')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('product_type')
                        ->label('Tipe Produk')
                        ->options([
                            'window_film' => 'Kaca Film',
                            'ppf'         => 'Paint Protection Film (PPF)',
                        ])
                        ->live()
                        ->required(),

                    // Kaca depan & samping/belakang SELALU produk (seri)
                    // yang berbeda — tidak ada produk Kaca Film yang sama
                    // untuk kedua posisi, jadi cuma 2 opsi.
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
                        'ppf'         => 'PPF',
                        default       => $state,
                    })
                    ->sortable(),

                // Posisi kaca cuma relevan untuk Kaca Film — PPF itu produk
                // untuk body mobil, bukan kaca, jadi nilai `position`-nya
                // (sisa default kolom, bukan input asli) tidak boleh
                // ditampilkan sama sekali supaya tidak membingungkan.
                Tables\Columns\TextColumn::make('position')
                    ->label('Posisi Kaca')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(function (?string $state, FilmProduct $record): string {
                        if ($record->product_type !== 'window_film') {
                            return '—';
                        }

                        return match ($state) {
                            'front'     => 'Kaca Depan',
                            'side_rear' => 'Samping & Belakang',
                            default     => '—',
                        };
                    })
                    ->toggleable(),

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
                        'ppf'         => 'PPF',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Sekarang scroll_codes.film_product_id restrictOnDelete()
                // (bukan lagi nullOnDelete) — hapus produk yang masih
                // dipakai ScrollCode akan ditolak database. Ditangkap di
                // sini supaya staff dapat pesan yang jelas, bukan error
                // mentah.
                Tables\Actions\DeleteAction::make()
                    ->action(function (FilmProduct $record) {
                        try {
                            $record->delete();
                        } catch (QueryException $e) {
                            Notification::make()
                                ->title('Tidak bisa menghapus produk ini')
                                ->body('Produk ini masih dipakai oleh kode gulungan yang terdaftar. Hapus/pindahkan kode gulungannya dulu, atau nonaktifkan produk ini saja lewat toggle "Aktif".')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Produk dihapus')->success()->send();
                    }),
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