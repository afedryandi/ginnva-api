<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FilmProductResource\Pages;
use App\Models\FilmProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use App\Services\PriceCalculator;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\HtmlString;

class FilmProductResource extends Resource
{
    protected static ?string $model = FilmProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    // 2026-09-10: dipindah dari Lainnya > Master Data ke Penjualan >
    // Produk (cluster ProdukCluster), = "Daftar Produk" di menu Produk
    // Majoo. Ditaruh bareng "Master Resep" (MasterResepResource).
    protected static ?string $cluster = \App\Filament\Clusters\ProdukCluster::class;

    protected static ?int $navigationSort = 1;

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

    /**
     * SEBELUMNYA cuma canViewAny() yang ada, dan tidak ada
     * FilmProductPolicy terdaftar — Gate default-deny bikin CreateAction,
     * EditAction, dan DeleteAction (row) TIDAK PERNAH muncul untuk siapa
     * pun, termasuk super_admin — bertentangan LANGSUNG dengan komentar
     * canViewAny() di atas ("staff toko sama-sama lihat & bisa edit semua
     * baris"). LEBIH PARAH: bulk action "Hapus" di bawah itu custom
     * BulkAction (bukan DeleteBulkAction bawaan Filament), jadi TIDAK
     * ikut auto-wired ke Gate sama sekali — staff mana pun yang punya
     * akses lihat menu ini SELALU bisa mass-delete data master Produk
     * Film lewat situ, walau tombol Delete satuan tidak pernah muncul.
     * Disamakan seluas canViewAny() (persis niat desain yang sudah
     * didokumentasikan), sekaligus menutup celah privilege-escalation
     * di bulk delete tadi — orang yang SEBELUMNYA sudah bisa bulk-delete
     * tetap bisa (breadth sama persis), tapi sekarang row Delete/Edit/
     * Create ikut konsisten muncul untuk mereka juga.
     */
    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
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
                            'detailing'   => 'Detailing (jasa, bukan film)',
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
                        ->required(fn (Forms\Get $get) => $get('product_type') === 'window_film'),

                    Forms\Components\TextInput::make('base_price')
                        ->label('Harga Dasar')
                        ->helperText('Referensi internal sales. Tidak ditampilkan ke customer — kalkulasi quotation memakai base_price × coefficient(vehicle_size, car_part).')
                        ->numeric()
                        ->prefix('Rp')
                        // SEBELUMNYA tidak ada minValue() -- field harga
                        // bisa tersimpan 0 atau negatif tanpa ditolak
                        // validasi, tidak konsisten dengan field nominal
                        // lain di sistem (mis. StoreResource::
                        // late_deduction_amount yang sudah pakai
                        // ->minValue(0)). Ditemukan & diperbaiki
                        // 2026-08-29, audit modul Produk Film.
                        ->minValue(0)
                        ->required(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif (tampil di pilihan quotation)')
                        ->default(true),
                ]),

            // Simulasi harga = Harga Dasar × koefisien (menu Penjualan >
            // Produk > Koefisien Harga). Read-only, cuma alat bantu lihat
            // dampak Harga Dasar per ukuran mobil. Fase 1 aktivasi pricing
            // 2026-09-10 — belum dipakai di alur quotation (Fase 2).
            Forms\Components\Section::make('Simulasi Harga per Ukuran Kendaraan')
                ->description('Harga jual = Harga Dasar × koefisien ukuran. Atur koefisien di menu "Koefisien Harga". Simpan dulu perubahan Harga Dasar untuk lihat angka terbaru.')
                ->visible(fn (?FilmProduct $record, Forms\Get $get) => $record !== null
                    && in_array($get('product_type'), ['window_film', 'ppf'], true))
                ->schema([
                    Forms\Components\Placeholder::make('price_matrix')
                        ->hiddenLabel()
                        ->content(function (FilmProduct $record): HtmlString {
                            if ((float) $record->base_price <= 0) {
                                return new HtmlString('<span style="color:#d97706">Harga Dasar masih Rp 0 — isi dulu untuk melihat simulasi.</span>');
                            }

                            $rows = collect(PriceCalculator::matrix($record))
                                ->map(fn (array $r, string $size) => '<tr>'
                                    .'<td style="padding:2px 16px 2px 0">'.$size.'</td>'
                                    .'<td style="padding:2px 16px 2px 0;text-align:right">'.($r['coefficient'] !== null ? number_format($r['coefficient'], 2).'×' : '—').'</td>'
                                    .'<td style="padding:2px 0;text-align:right;font-weight:600">'.($r['price'] !== null ? 'Rp'.number_format($r['price'], 0, ',', '.') : '<span style="color:#d97706">koefisien belum ada</span>').'</td>'
                                    .'</tr>')
                                ->implode('');

                            return new HtmlString(
                                '<table style="font-size:0.875rem;border-collapse:collapse">'
                                .'<thead><tr style="border-bottom:1px solid #e5e7eb">'
                                .'<th style="text-align:left;padding-right:16px">Ukuran</th>'
                                .'<th style="text-align:right;padding-right:16px">Koef.</th>'
                                .'<th style="text-align:right">Harga Jual</th>'
                                .'</tr></thead><tbody>'.$rows.'</tbody></table>'
                            );
                        }),
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
                        'window_film'  => 'Kaca Film',
                        'ppf'          => 'PPF',
                        'detailing'    => 'Detailing',
                        'color_change' => 'Ganti Warna',
                        default        => $state,
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

                Tables\Columns\TextColumn::make('price_status')
                    ->label('Status Harga')
                    ->badge()
                    ->state(fn (FilmProduct $record): string => (float) $record->base_price > 0 ? 'Terisi' : 'Belum diisi')
                    ->color(fn (FilmProduct $record): string => (float) $record->base_price > 0 ? 'success' : 'gray'),

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
                        'detailing'   => 'Detailing',
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
                    // SEBELUMNYA DeleteBulkAction::make() bawaan — beda
                    // dari DeleteAction satuan di atas yang sudah
                    // menangkap QueryException dengan pesan ramah, bulk
                    // delete TIDAK punya proteksi sama sekali. Kalau 1
                    // saja dari baris terpilih masih direferensikan
                    // ScrollCode (restrictOnDelete), staff dapat error
                    // mentah alih-alih pesan jelas. Custom action ini
                    // proses satu-satu supaya baris yang AMAN tetap
                    // terhapus, baris yang masih dipakai dilewati dengan
                    // laporan jumlahnya (pola sama dengan perbaikan
                    // VehicleResource). Ditemukan & diperbaiki
                    // 2026-08-29, audit modul Produk Film.
                    Tables\Actions\BulkAction::make('delete')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        // BulkAction custom TIDAK auto-wired ke Gate
                        // (beda dari DeleteBulkAction bawaan) — ->visible()
                        // eksplisit di sini supaya tetap tunduk ke
                        // canDeleteAny(), bukan cuma mengandalkan
                        // canViewAny() (lihat catatan di canDeleteAny() di
                        // atas). Ditemukan & diperbaiki 2026-09-07.
                        ->visible(fn () => static::canDeleteAny())
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $deleted = 0;
                            $blocked = 0;

                            foreach ($records as $record) {
                                try {
                                    $record->delete();
                                    $deleted++;
                                } catch (QueryException $e) {
                                    $blocked++;
                                }
                            }

                            if ($blocked > 0) {
                                Notification::make()
                                    ->title($deleted > 0
                                        ? "{$deleted} produk dihapus, {$blocked} tidak bisa dihapus"
                                        : 'Tidak ada produk yang bisa dihapus')
                                    ->body("{$blocked} produk masih dipakai oleh kode gulungan yang terdaftar, dilewati. Nonaktifkan lewat toggle \"Aktif\" saja kalau perlu.")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()->title("{$deleted} produk dihapus")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
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