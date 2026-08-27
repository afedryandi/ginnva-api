<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerGalleryPhotoResource\Pages;
use App\Models\CustomerGalleryPhoto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Galeri "showcase" per-customer — TERPISAH dari galeri personal yang
 * auto-terbentuk dari foto chat booking (lihat BookingMessage.photo_path
 * & Api\Customer\BookingMessageController::gallery()). Foto di sini
 * tidak terikat booking tertentu, jadi staff bisa upload foto hasil akhir
 * yang paling bagus kapan pun — otomatis muncul di beranda mobile app
 * customer terkait (lihat penggabungan di gallery() endpoint).
 */
class CustomerGalleryPhotoResource extends Resource
{
    protected static ?string $model = CustomerGalleryPhoto::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Galeri Customer';

    protected static ?string $modelLabel = 'Foto Galeri Customer';

    protected static ?string $pluralModelLabel = 'Galeri Customer';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Foto Galeri')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} — {$record->phone_number}")
                        ->searchable(['name', 'phone_number', 'email'])
                        ->preload()
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('image')
                        ->label('Foto')
                        ->image()
                        ->directory('customer-gallery')
                        ->maxSize(5120)
                        ->helperText('Maks. 5 MB. Format: JPG, PNG, WebP. Pilih foto hasil instalasi terbaik — ini yang tampil di beranda mobile app customer.')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('caption')
                        ->label('Keterangan (opsional)')
                        ->placeholder('Contoh: Kaca Film A70 — Toyota Alphard')
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_featured')
                        ->label('Tampilkan di Beranda')
                        ->helperText('Nonaktifkan untuk sembunyikan foto ini tanpa menghapusnya.')
                        ->default(true),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Urutan Tampil')
                        ->numeric()
                        ->default(0)
                        ->helperText('Angka lebih kecil tampil lebih dulu.'),

                    Forms\Components\Hidden::make('uploaded_by')
                        ->default(fn () => auth()->id()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('Foto')
                    ->square()
                    ->size(60),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->description(fn (CustomerGalleryPhoto $record) => $record->customer?->phone_number),

                Tables\Columns\TextColumn::make('caption')
                    ->label('Keterangan')
                    ->placeholder('—')
                    ->limit(40),

                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Tampil di Beranda')
                    ->boolean(),

                Tables\Columns\TextColumn::make('uploadedBy.name')
                    ->label('Diupload Oleh')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diupload')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label('Tampil di Beranda'),
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
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomerGalleryPhotos::route('/'),
            'create' => Pages\CreateCustomerGalleryPhoto::route('/create'),
            'edit'   => Pages\EditCustomerGalleryPhoto::route('/{record}/edit'),
        ];
    }
}
