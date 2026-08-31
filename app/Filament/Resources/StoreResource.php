<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreResource\Pages;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Toko/Dealer';

    protected static ?string $modelLabel = 'Toko';

    protected static ?string $pluralModelLabel = 'Toko/Dealer';

    /**
     * Bikin toko/cabang baru adalah keputusan bisnis level super_admin
     * (ekspansi lokasi), bukan wewenang Store Manager.
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * store_manager (admin toko) hanya melihat toko miliknya sendiri di
     * list — READ-ONLY. Edit/Hapus master data toko (termasuk jam
     * operasional, alamat, radius absen, toleransi telat, potongan gaji,
     * dst) sengaja dikunci cuma untuk super_admin/direksi — lihat
     * StorePolicy::update(), yang TIDAK punya pengecualian kepemilikan
     * toko (beda dari view()). Keputusan bisnis 2026-08-29: mencegah
     * conflict of interest kalau Store Manager bisa longgarkan radius
     * absen atau kurangi potongan gaji miliknya sendiri. super_admin
     * lihat semua toko.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Toko')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Toko')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('city')
                        ->label('Kota')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('No. Telepon')
                        ->tel()
                        ->maxLength(255),

                    // Cuma DEFAULT yang jadi patokan awal di form approve
                    // booking (Filament & mobile) — staff tetap bisa ubah
                    // angkanya sesaat kalau kapasitas hari itu beda dari
                    // biasanya (mis. 1 installer izin), tanpa perlu ubah
                    // setting ini. Lihat BookingResource::form().
                    Forms\Components\TextInput::make('install_capacity_per_day')
                        ->label('Kapasitas Instalasi / Hari (Default)')
                        ->helperText('Berapa mobil yang biasanya bisa dikerjakan toko ini per hari — jadi nilai awal saat staff approve booking, tapi tetap bisa diubah sesaat per booking kalau kondisinya beda.')
                        ->numeric()
                        ->minValue(1)
                        ->default(3)
                        ->required(),

                    Forms\Components\TextInput::make('attendance_radius_meters')
                        ->label('Radius Absen (meter)')
                        ->helperText('Jarak maksimum dari lokasi toko ini supaya absen dari app dianggap wajar. Kosongkan untuk pakai default sistem (150 m).')
                        ->numeric()
                        ->minValue(10),

                    Forms\Components\TextInput::make('late_tolerance_minutes')
                        ->label('Toleransi Telat / Bulan (menit)')
                        ->helperText('Total menit telat yang masih ditoleransi dalam 1 bulan sebelum dianggap perlu ditindaklanjuti (potongan gaji dihitung manual di Penggajian). Kosongkan untuk pakai default sistem (15 menit).')
                        ->numeric()
                        ->minValue(0),

                    Forms\Components\TextInput::make('late_deduction_amount')
                        ->label('Potongan per Pelanggaran (Rp)')
                        ->helperText('Nominal potongan gaji setelah toleransi telat bulanan terlampaui — dipakai sebagai acuan saat hitung Penggajian, belum otomatis memotong gaji.')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0),

                    Forms\Components\Repeater::make('opening_hours')
                        ->label('Jam Operasional')
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\CheckboxList::make('days')
                                ->label('Hari')
                                ->options(Store::DAY_LABELS)
                                ->columns(4)
                                ->required()
                                ->columnSpanFull(),

                            Forms\Components\Grid::make(3)
                                ->schema([
                                    Forms\Components\Toggle::make('closed')
                                        ->label('Libur')
                                        ->live()
                                        ->default(false),

                                    Forms\Components\TimePicker::make('open')
                                        ->label('Jam Buka')
                                        ->seconds(false)
                                        ->required(fn (Forms\Get $get) => ! $get('closed'))
                                        ->hidden(fn (Forms\Get $get) => $get('closed')),

                                    Forms\Components\TimePicker::make('close')
                                        ->label('Jam Tutup')
                                        ->seconds(false)
                                        ->required(fn (Forms\Get $get) => ! $get('closed'))
                                        ->hidden(fn (Forms\Get $get) => $get('closed')),
                                ]),
                        ])
                        ->itemLabel(fn (array $state): ?string => filled($state['days'] ?? null)
                            ? Store::formatDayRange($state['days']) . (($state['closed'] ?? false) ? ' — Libur' : " — {$state['open']}–{$state['close']}")
                            : 'Baris baru')
                        ->addActionLabel('Tambah Baris Jam')
                        ->reorderable(false)
                        ->collapsible()
                        ->collapsed(false)
                        ->helperText('Kelompokkan hari dengan jam yang sama jadi 1 baris, mis. "Senin–Jumat" 1 baris, "Sabtu" baris lain, "Minggu" ditandai Libur.')
                        ->default([]),

                    Forms\Components\TextInput::make('maps_url')
                        ->label('Tempel Link Google Maps')
                        ->helperText('Cari toko di Google Maps → Bagikan → salin link (boleh link pendek maps.app.goo.gl) → tempel di sini. Latitude/Longitude di bawah terisi otomatis, dan link ini yang dibuka saat customer tap "Buka Peta" di mobile app.')
                        ->url()
                        ->columnSpanFull()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                            if (! $state) {
                                return;
                            }

                            $coords = static::extractLatLngFromMapsUrl($state);

                            if (! $coords) {
                                Notification::make()
                                    ->title('Koordinat tidak ditemukan')
                                    ->body('Tidak bisa membaca latitude/longitude dari link ini. Isi manual di bawah, atau coba salin ulang link dari Google Maps.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $set('latitude', $coords[0]);
                            $set('longitude', $coords[1]);

                            Notification::make()
                                ->title('Koordinat berhasil diisi')
                                ->body("Latitude {$coords[0]}, Longitude {$coords[1]}")
                                ->success()
                                ->send();
                        }),

                    Forms\Components\TextInput::make('latitude')
                        ->label('Latitude')
                        ->numeric(),

                    Forms\Components\TextInput::make('longitude')
                        ->label('Longitude')
                        ->numeric(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif (tampil di web publik)')
                        ->default(true),

                    Forms\Components\TextInput::make('google_place_id')
                        ->label('Google Place ID')
                        ->helperText('Dipakai untuk tombol "Beri Ulasan" di mobile app. Cari toko di Google Maps → Bagikan → Sematkan peta HTML → salin ID setelah "!1s0x". Atau pakai tool "Place ID Finder" di developers.google.com/maps/documentation/places/web-service/place-id.')
                        ->columnSpanFull()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('address')
                        ->label('Alamat')
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Ambil [latitude, longitude] dari berbagai format URL Google Maps:
     * - Link biasa: .../place/Nama+Toko/@-6.2088,106.8456,17z/...
     * - Link "Sematkan"/embed: ...!3d-6.2088!4d106.8456...
     * - Link query lama: maps.google.com/maps?q=-6.2088,106.8456
     * - Link pendek: maps.app.goo.gl/xxxxx atau goo.gl/maps/xxxxx — di-resolve
     *   dulu ke URL aslinya lewat HTTP request (cuma untuk domain Google
     *   Maps, supaya field ini tidak disalahgunakan jadi URL-fetcher bebas).
     */
    private static function extractLatLngFromMapsUrl(string $url): ?array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        $isShortLink = in_array($host, ['maps.app.goo.gl', 'goo.gl'], true);
        $isGoogleMapsHost = str_ends_with($host, 'google.com') || str_ends_with($host, 'google.co.id');

        if (! $isShortLink && ! $isGoogleMapsHost) {
            return null;
        }

        if ($isShortLink) {
            try {
                $resolved = Http::timeout(8)->get($url)->effectiveUri();
                if ($resolved) {
                    $url = (string) $resolved;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }

        // !3d/!4d (koordinat PIN tempat sebenarnya) dicek DULUAN — @lat,lng
        // itu cuma posisi kamera/viewport peta saat link dibuat, HAMPIR
        // SELALU ada di URL Google Maps apa pun, dan bisa meleset ratusan
        // meter dari pin aslinya (pernah kejadian nyata: toko Ginnva House
        // selisih ~236m karena pattern @lat,lng ini yang kepilih duluan).
        $patterns = [
            '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',    // embed data param — koordinat pin sebenarnya
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',       // .../@-6.2088,106.8456,17z — fallback: posisi kamera
            '/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/',   // ?q=-6.2088,106.8456
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return [(float) $matches[1], (float) $matches[2]];
            }
        }

        return null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Toko')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('city')
                    ->label('Kota')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Telepon'),

                Tables\Columns\TextColumn::make('opening_hours_summary')
                    ->label('Jam Operasional')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Hapus toko cascade-delete SELURUH booking, chat, teknisi,
                // dan review milik toko itu (lihat cascadeOnDelete di
                // migration bookings/technicians/blocked_dates/store_reviews)
                // — sebelumnya bisa diklik siapa saja termasuk Store
                // Manager atas tokonya sendiri, tanpa restriksi sama
                // sekali. Sekarang cuma super_admin/direksi.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess()),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/create'),
            'edit' => Pages\EditStore::route('/{record}/edit'),
        ];
    }
}