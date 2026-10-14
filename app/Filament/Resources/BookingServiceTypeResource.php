<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingServiceTypeResource\Pages;
use App\Models\BookingServiceType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Layanan Booking" (Fase 2 pengerjaan ulang booking, 2026-10-16): nama, deskripsi, kategori kapasitas, lama pengerjaan,
 * urutan, dan aktif/tidak untuk empat layanan yang bisa dipesan pelanggan di aplikasi. Empat kunci tetap (terhubung ke
 * penanda produk di tabel bookings), jadi tidak ada tambah/hapus dari sini. Perubahan langsung terbaca aplikasi.
 */
class BookingServiceTypeResource extends Resource
{
    protected static ?string $model = BookingServiceType::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar "Operasional" (band 100-199), setelah "Tanggal Tidak Tersedia".
    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Layanan Booking';

    protected static ?string $modelLabel = 'Layanan Booking';

    protected static ?string $pluralModelLabel = 'Layanan Booking';

    protected static ?int $navigationSort = 102;

    private static function accessGate(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    // Empat kunci layanan tetap: tidak ada tambah atau hapus dari admin.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    // Mengubah katalog memengaruhi aplikasi pelanggan dan kapasitas, jadi hanya akses penuh (super admin/direksi).
    public static function canEdit($record): bool
    {
        return static::accessGate() && (auth()->user()?->isFullAccess() ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->columns(1)->schema([
            Forms\Components\TextInput::make('key')
                ->label('Kode Layanan')
                ->disabled()
                ->dehydrated(false)
                ->helperText('Tetap; terhubung ke penanda produk di booking.'),

            Forms\Components\TextInput::make('label')
                ->label('Nama Layanan')
                ->required()
                ->maxLength(80)
                ->helperText('Tampil di aplikasi dan menjadi "Jenis Layanan" pada booking BARU. Booking lama tidak berubah.'),

            Forms\Components\Textarea::make('description')
                ->label('Deskripsi Singkat')
                ->rows(2)
                ->maxLength(255)
                ->helperText('Satu atau dua kalimat di bawah nama layanan di aplikasi.'),

            Forms\Components\Select::make('capacity_category')
                ->label('Kategori Kapasitas')
                ->options([
                    BookingServiceType::CATEGORY_HEAVY => 'Berat: memakai kapasitas harian toko (mis. PPF, kaca film)',
                    BookingServiceType::CATEGORY_LIGHT => 'Ringan: memakai slot jam (mis. detailing, cuci premium)',
                ])
                ->required()
                ->helperText('Booking yang berisi layanan berat dan ringan sekaligus dihitung sebagai berat. Mengubah kategori memengaruhi perhitungan kapasitas booking baru.'),

            Forms\Components\TextInput::make('estimated_duration_days')
                ->label('Lama Pengerjaan (hari kerja)')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(14)
                ->helperText('Perkiraan yang tampil di aplikasi.'),

            Forms\Components\TextInput::make('sort_order')
                ->label('Urutan Tampil')
                ->numeric()
                ->required()
                ->minValue(0)
                ->helperText('Angka kecil tampil lebih dulu.'),

            Forms\Components\Toggle::make('is_active')
                ->label('Tampil di Aplikasi')
                ->helperText('Matikan untuk menyembunyikan layanan dari pilihan booking pelanggan.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Layanan')->weight('bold'),
                Tables\Columns\TextColumn::make('key')->label('Kode')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('capacity_category')
                    ->label('Kapasitas')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === BookingServiceType::CATEGORY_LIGHT ? 'Ringan (slot jam)' : 'Berat (harian)')
                    ->color(fn (string $state) => $state === BookingServiceType::CATEGORY_LIGHT ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('estimated_duration_days')->label('Lama')->suffix(' hari kerja'),
                Tables\Columns\TextColumn::make('sort_order')->label('Urutan'),
                Tables\Columns\IconColumn::make('is_active')->label('Tampil')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingServiceTypes::route('/'),
            'edit' => Pages\EditBookingServiceType::route('/{record}/edit'),
        ];
    }
}
