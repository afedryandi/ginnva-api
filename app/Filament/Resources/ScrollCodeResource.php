<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScrollCodeResource\Pages;
use App\Filament\Resources\ScrollCodeResource\RelationManagers\OffcutsRelationManager;
use App\Filament\Resources\ScrollCodeResource\RelationManagers\TransfersRelationManager;
use App\Filament\Resources\ScrollCodeResource\RelationManagers\UsagesRelationManager;
use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ScrollCodeExport;

class ScrollCodeResource extends Resource
{
    protected static ?string $model = ScrollCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    // Dipindah ke klaster Inventori 2026-11-02 (kode gulungan adalah data stok roll film); sebelumnya MasterDataCluster. Tetap
    // disembunyikan dari sidebar (lihat shouldRegisterNavigation()): dibuka lewat Produk PPF/WF.
    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?string $navigationGroup = 'Master Data Stok';

    protected static ?string $navigationLabel = 'Kode Gulungan';

    protected static ?string $modelLabel = 'Kode Gulungan';

    protected static ?string $pluralModelLabel = 'Kode Gulungan';

    protected static ?int $navigationSort = 15;

    /**
     * Kode gulungan memuat harga beli (dasar HPP), nomor garansi, dan riwayat pemakaian dengan nama pelanggan, jadi tidak terbuka lewat
     * akses bawaan akun baru (menu_access kosong): hanya akses penuh atau staf yang menunya dicentang eksplisit (audit Kode Gulungan,
     * 2026-11-02). Harga beli butuh izin "Lihat" pada menu ini, dan Hapus butuh izin Hapus eksplisit.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    /** Boleh melihat harga beli dan harga per meter: akses penuh atau izin "Lihat" pada menu Kode Gulungan. */
    public static function canSeeCost(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * SEBELUMNYA tidak ada override di sini dan tidak ada ScrollCodePolicy
     * terdaftar — canView() bawaan Resource selalu FALSE untuk siapa pun
     * tanpa policy (default-deny Laravel). ViewAction (label "Riwayat") di
     * table() jadi tidak pernah muncul, DAN halaman getPages()['view']
     * (drill-down dari kolom "Kode Gulungan" di Produk PPF/WF) 403 kalau
     * diakses langsung — sama bug class yang berulang di banyak audit
     * sebelumnya. Dibiarkan seluas canViewAny().
     */
    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /**
     * DeleteAction per-baris di table() SUDAH punya ->visible(isFullAccess
     * && status==='unallocated') eksplisit, tapi tanpa canDelete()
     * di-override, auto-wire authorize()-nya (default false tanpa policy)
     * tetap menyembunyikan tombolnya terlepas dari ->visible(). Guard
     * yang sama diulang di sini supaya konsisten dengan intent yang
     * sudah ada.
     */
    public static function canDelete($record): bool
    {
        $user = auth()->user();

        $allowed = ($user?->isFullAccess() ?? false)
            || (static::canViewAny() && $user->hasModuleAction(static::class, 'delete', false));

        return $allowed && $record->status === 'unallocated';
    }

    /**
     * Disembunyikan dari sidebar navigasi — staff sekarang cukup lewat 1
     * menu "Produk PPF/WF" untuk semua urusan PPF/WF (lihat header actions
     * baru di InventoryItemResource: Tambah Kode, Export, Rekonsiliasi,
     * dan link "Kelola Kode Gulungan Lanjutan" untuk alokasi massal/isi
     * panjang standar/hapus kode). Resource ini TETAP aktif (bukan
     * dihapus) — route/halaman View masih dipakai sebagai link drill-down
     * dari kolom "Kode Gulungan" di Produk PPF/WF, dan halaman index-nya
     * masih bisa diakses langsung lewat tombol "Kelola Kode Gulungan
     * Lanjutan" untuk tugas yang tidak terikat 1 barang tertentu.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Nomor garansi, jumlah garansi, dan jumlah pencatatan meter dihitung lewat subquery di query daftar (bukan dua query per baris seperti
        // sebelumnya). Garansi dicocokkan lewat teks kode di empat kolom roll_number* (tidak ada foreign key), sama dengan ScrollCode::warranties().
        $rollMatch = fn ($w) => $w->whereColumn('warranties.roll_number', 'scroll_codes.code')
            ->orWhereColumn('warranties.roll_number_2', 'scroll_codes.code')
            ->orWhereColumn('warranties.roll_number_front', 'scroll_codes.code')
            ->orWhereColumn('warranties.roll_number_side_rear', 'scroll_codes.code');

        $query = parent::getEloquentQuery()
            ->with('inventoryItem:id,scroll_code_id,code')
            ->select('scroll_codes.*')
            ->addSelect([
                'warranty_numbers' => \App\Models\Warranty::query()->selectRaw("GROUP_CONCAT(DISTINCT warranty_code ORDER BY warranty_code SEPARATOR ', ')")->where($rollMatch),
                'warranty_total' => \App\Models\Warranty::query()->selectRaw('count(*)')->where($rollMatch),
                'meter_usages_total' => \App\Models\ScrollCodeUsage::query()->selectRaw('count(*)')->whereColumn('scroll_code_usages.scroll_code_id', 'scroll_codes.id'),
                'offcuts_stored_count' => \App\Models\ScrollCodeOffcut::query()->selectRaw('count(*)')->whereColumn('scroll_code_offcuts.scroll_code_id', 'scroll_codes.id')->where('scroll_code_offcuts.status', 'stored'),
            ]);
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')->label('Kode')->disabled(),
                    Forms\Components\TextInput::make('filmProduct.name')->label('Produk')->disabled(),
                    Forms\Components\TextInput::make('store.name')->label('Toko')->disabled(),
                    Forms\Components\TextInput::make('status')->label('Status')->disabled(),

                    // BUKAN field 'warranty_code' mentah — kolom itu cuma
                    // menyimpan warranty TERAKHIR yang memakai kode ini
                    // (gampang ketimpa, lihat catatan di kolom tabel),
                    // padahal 1 kode sekarang bisa dipakai >1 warranty.
                    // Dihitung ulang dari tabel warranties di sini juga
                    // supaya form detail TIDAK menampilkan angka yang beda
                    // dari yang ditampilkan tabel listing untuk baris yang
                    // sama.
                    Forms\Components\TextInput::make('warranty_codes_display')
                        ->label('No. Garansi')
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?ScrollCode $record) => $record
                            ? ($record->warranties()->pluck('warranty_code')->implode(', ') ?: '—')
                            : '—'),

                    // Ringkasan yang sebelumnya hanya terlihat di daftar: panjang, koreksi manual, harga, jumlah catatan, dan barang terkait.
                    Forms\Components\TextInput::make('length_summary')
                        ->label('Panjang (sisa / total)')
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?ScrollCode $record) => $record && $record->total_length_meters !== null
                            ? number_format((float) $record->remaining_length_meters, 2) . ' / ' . number_format((float) $record->total_length_meters, 2) . ' m'
                            : 'Belum diisi'),

                    Forms\Components\TextInput::make('length_adjustment_display')
                        ->label('Koreksi Manual (meter)')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?ScrollCode $record) => $record !== null && (float) $record->length_adjustment_meters !== 0.0)
                        ->helperText('Selisih antara sisa yang diisi admin dan sisa menurut riwayat pemakaian; ikut dihitung "Cek Rekonsiliasi".')
                        ->formatStateUsing(fn (?ScrollCode $record) => $record
                            ? ((float) $record->length_adjustment_meters > 0 ? '+' : '') . number_format((float) $record->length_adjustment_meters, 2) . ' m'
                            : '—'),

                    Forms\Components\TextInput::make('cost_display')
                        ->label('Harga Beli / per Meter')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn () => static::canSeeCost())
                        ->formatStateUsing(fn (?ScrollCode $record) => $record && $record->purchase_cost !== null
                            ? 'Rp' . number_format((float) $record->purchase_cost, 0, ',', '.')
                                . ($record->costPerMeter() !== null ? ' (Rp' . number_format($record->costPerMeter(), 0, ',', '.') . '/m)' : '')
                            : 'Belum diisi'),

                    Forms\Components\TextInput::make('usage_summary')
                        ->label('Pemakaian')
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn (?ScrollCode $record) => $record
                            ? (int) ($record->warranty_total ?? $record->warranties()->count()) . ' garansi · ' . (int) ($record->meter_usages_total ?? $record->usages()->count()) . ' catatan meter'
                                . ($record->max_usage ? " · kapasitas {$record->max_usage}" : '')
                            : '—'),

                    Forms\Components\Placeholder::make('linked_item')
                        ->label('Barang Terkait')
                        ->content(function (?ScrollCode $record) {
                            $item = $record?->inventoryItem;

                            if (! $item) {
                                return new \Illuminate\Support\HtmlString('<span>Belum dikaitkan ke barang. Kaitkan lewat menu Produk PPF/WF.</span>');
                            }

                            $label = e("{$item->code} — {$item->name}");

                            return new \Illuminate\Support\HtmlString(InventoryItemResource::canViewAny()
                                ? '<a href="' . e(InventoryItemResource::getUrl('edit', ['record' => $item->id])) . '" class="text-primary-600 hover:underline">' . $label . '</a>'
                                : "<span>{$label}</span>");
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('filmProduct.name')
                    ->label('Produk Film')
                    ->placeholder('—')
                    ->searchable()
                    ->description(function (ScrollCode $record): ?string {
                        $fp = $record->filmProduct;
                        if (! $fp) return null;

                        $type = $fp->product_type === 'ppf' ? 'PPF' : 'Kaca Film';

                        if ($fp->product_type !== 'window_film') {
                            return $type;
                        }

                        return $type . ' — ' . ($fp->position === 'front' ? 'Kaca Depan' : 'Samping & Belakang');
                    }),

                // Kode gulungan (tercetak di fisik gulungan) beda dari
                // kode barang (INV-xxx, di-generate sistem untuk QR
                // kardus/kemasan) — sebelumnya tidak kelihatan sama sekali
                // dari menu ini, staff harus buka menu Produk PPF/WF dulu
                // dan cari manual kalau mau tahu kode barangnya.
                Tables\Columns\TextColumn::make('inventoryItem.code')
                    ->label('Kode Barang')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (ScrollCode $record) => $record->inventoryItem ? 'primary' : 'gray')
                    ->copyable()
                    ->copyMessage('Kode disalin')
                    ->searchable()
                    ->toggleable()
                    // Klik langsung ke halaman edit Produk PPF/WF terkait
                    // — pasangan dari link di InventoryItemResource
                    // ("Kode Gulungan" -> sini).
                    ->url(fn (ScrollCode $record) => $record->inventoryItem
                        ? InventoryItemResource::getUrl('edit', ['record' => $record->inventoryItem->id])
                        : null),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'gray'    => 'unallocated',
                        'warning' => 'allocated',
                        'success' => 'used',
                    ])
                    // Istilah sama dengan menu Produk PPF/WF: Belum dialokasikan / Dialokasikan / Terpakai.
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unallocated' => 'Belum dialokasikan',
                        'allocated'   => 'Dialokasikan',
                        'used'        => 'Terpakai',
                        default       => $state,
                    }),

                // Dihitung langsung dari tabel warranties (bukan cuma
                // kolom warranty_code di baris ini) — 1 kode gulungan bisa
                // dipakai >1 warranty sejak tidak lagi otomatis single-use,
                // jadi kolom warranty_code yang cuma nyimpan 1 nilai akan
                // ketimpa (nampilkan yang PALING TERAKHIR pakai doang) kalau
                // tidak dihitung ulang begini.
                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('No. Garansi')
                    ->placeholder('—')
                    ->getStateUsing(fn (ScrollCode $record) => array_key_exists('warranty_numbers', $record->getAttributes())
                        ? ($record->warranty_numbers ?: null)
                        : ($record->warranties()->pluck('warranty_code')->implode(', ') ?: null))
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->orWhereExists(function ($sub) use ($search) {
                            $sub->selectRaw('1')
                                ->from('warranties')
                                ->where('warranties.warranty_code', 'like', "%{$search}%")
                                ->where(function ($w) {
                                    $w->whereColumn('warranties.roll_number', 'scroll_codes.code')
                                        ->orWhereColumn('warranties.roll_number_2', 'scroll_codes.code')
                                        ->orWhereColumn('warranties.roll_number_front', 'scroll_codes.code')
                                        ->orWhereColumn('warranties.roll_number_side_rear', 'scroll_codes.code');
                                });
                        });
                    }),

                // Berlaku untuk PPF maupun Window Film — hitung berapa kali
                // kode ini dipakai, auto-'used' hanya kalau max_usage diisi
                // dan tercapai. Lihat WarrantyObserver.
                // Dua hitungan yang sebelumnya tercampur dalam satu kolom "Terpakai": jumlah garansi yang memakai kode ini (dari tabel garansi,
                // dengan batas kapasitas bila diisi) dan jumlah pencatatan meter lewat Catat Pemakaian.
                Tables\Columns\TextColumn::make('warranty_total')
                    ->label('Garansi')
                    ->formatStateUsing(fn (ScrollCode $record) => $record->max_usage
                        ? "{$record->warranty_total} / {$record->max_usage}"
                        : (string) (int) $record->warranty_total)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('meter_usages_total')
                    ->label('Catatan Meter')
                    ->formatStateUsing(fn (ScrollCode $record) => (string) (int) $record->meter_usages_total)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining_length_meters')
                    ->label('Sisa Panjang')
                    ->placeholder('—')
                    ->formatStateUsing(fn (ScrollCode $record) => $record->total_length_meters !== null
                        ? number_format((float) $record->remaining_length_meters, 2) . ' / ' . number_format((float) $record->total_length_meters, 2) . ' m'
                        : null)
                    ->badge()
                    ->color(fn (ScrollCode $record) => $record->total_length_meters !== null && (float) $record->remaining_length_meters <= 0
                        ? 'danger'
                        : 'info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('offcuts_stored_count')
                    ->label('Potongan Sisa')
                    ->formatStateUsing(fn ($state) => (int) $state > 0 ? (int) $state . ' potongan' : null)
                    ->placeholder('—')
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('purchase_cost')
                    ->label('Harga/Meter')
                    ->placeholder('Belum diisi')
                    ->formatStateUsing(fn (ScrollCode $record) => $record->costPerMeter() !== null
                        ? 'Rp' . number_format($record->costPerMeter(), 0, ',', '.') . '/m'
                        : null)
                    ->visible(fn () => static::canSeeCost())
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('allocated_at')
                    ->label('Dialokasi Pada')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('used_at')
                    ->label('Dipakai Pada')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'unallocated' => 'Belum dialokasikan',
                        'allocated'   => 'Dialokasikan',
                        'used'        => 'Terpakai',
                    ]),

                // Roll yang masih menyimpan potongan sisa (wasted) di gudang.
                Tables\Filters\Filter::make('has_offcuts')
                    ->label('Punya potongan sisa')
                    ->query(fn (Builder $query) => $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('scroll_code_offcuts')
                        ->whereColumn('scroll_code_offcuts.scroll_code_id', 'scroll_codes.id')
                        ->where('scroll_code_offcuts.status', 'stored'))),

                // Gulungan yang hampir habis (sisa 20% atau kurang dari total, belum Terpakai): untuk memesan ulang tepat waktu.
                Tables\Filters\Filter::make('low_roll')
                    ->label('Sisa menipis (≤ 20%)')
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('total_length_meters')
                        ->where('total_length_meters', '>', 0)
                        ->where('status', '!=', 'used')
                        ->whereRaw('remaining_length_meters <= total_length_meters * 0.2')),

                Tables\Filters\Filter::make('unlinked')
                    ->label('Belum dikaitkan ke barang')
                    ->query(fn (Builder $query) => $query->doesntHave('inventoryItem')),

                Tables\Filters\Filter::make('no_length')
                    ->label('Panjang belum diisi')
                    ->query(fn (Builder $query) => $query->whereNull('total_length_meters')),

                // HPP dan Laba Kotor menandai gulungan tanpa harga beli; hanya bagi yang boleh melihat harga.
                Tables\Filters\Filter::make('no_cost')
                    ->label('Harga beli belum diisi')
                    ->visible(fn () => static::canSeeCost())
                    ->query(fn (Builder $query) => $query->whereNull('purchase_cost')),

                Tables\Filters\SelectFilter::make('film_product_id')
                    ->label('Produk Film')
                    ->options(fn () => FilmProduct::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->headerActions([
                // Input kode dari China — hanya super_admin
                Tables\Actions\Action::make('add_code')
                    ->label('Tambah Kode')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->form([
                        Forms\Components\TextInput::make('code')
                            ->label('Kode Gulungan')
                            ->placeholder('Masukkan kode dari gulungan fisik')
                            ->required(),

                        // Label disertai tipe (PPF/Kaca Film) + posisi
                        // (Depan/Samping & Belakang) supaya staff tidak
                        // salah pilih produk saat daftarkan kode baru —
                        // sebelumnya cuma nama polos, tidak ada konteks.
                        Forms\Components\Select::make('film_product_id')
                            ->label('Produk Film')
                            ->options(fn () => FilmProduct::where('is_active', true)
                                ->whereNotIn('product_type', ['detailing', 'premium_wash'])
                                ->get()
                                ->mapWithKeys(function (FilmProduct $fp) {
                                    $type = $fp->product_type === 'ppf' ? 'PPF' : 'Kaca Film';

                                    $detail = $fp->product_type === 'window_film'
                                        ? ($fp->position === 'front' ? 'Kaca Depan' : 'Samping & Belakang')
                                        : null;

                                    $label = $detail ? "{$fp->name} — {$type} ({$detail})" : "{$fp->name} — {$type}";

                                    return [$fp->id => $label];
                                })
                            )
                            ->searchable()
                            ->required()
                            // Isi otomatis Total Panjang begitu produk dipilih
                            // — standar PPF 15m, Window Film 30m. Staff tetap
                            // bisa edit manual kalau gulungan ini beda (mis.
                            // sisa tabungan dari gulungan lain, lihat catatan
                            // di InventoryItemResource).
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                $product = $state ? FilmProduct::find($state) : null;
                                if ($product) {
                                    $set('total_length_meters', $product->product_type === 'ppf' ? 15 : 30);
                                }
                            }),

                        Forms\Components\TextInput::make('max_usage')
                            ->label('Kapasitas Gulungan (opsional)')
                            ->helperText('Boleh kosong. Kode tetap muncul di pilihan garansi sampai ditandai habis. Isi kalau gulungan hanya cukup untuk sekian mobil.')
                            ->numeric()
                            ->minValue(1),

                        Forms\Components\TextInput::make('total_length_meters')
                            ->label('Total Panjang (meter)')
                            ->helperText('Terisi otomatis dari produk (PPF 15 m, Window Film 30 m). Ubah manual kalau gulungan ini berbeda.')
                            ->numeric()
                            ->required()
                            ->minValue(0.01),

                        Forms\Components\TextInput::make('purchase_cost')
                            ->label('Harga Beli Gulungan (opsional)')
                            ->helperText('Harga beli total satu gulungan (bukan per meter), dipakai menghitung HPP di Laporan Penjualan Per Periode. Boleh kosong; laporan menandainya harga belum diisi.')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0),
                    ])
                    ->action(function (array $data) {
                        // Cek unique manual terhadap nilai yang SUDAH di-trim
                        // — sebelumnya rule unique:scroll_codes,code jalan
                        // atas input mentah (belum di-trim) sementara yang
                        // disimpan sudah di-trim, jadi kode ber-spasi bisa
                        // lolos validasi lalu tetap bentrok dengan UNIQUE
                        // constraint di database saat insert (error 500
                        // mentah, bukan pesan validasi yang rapi).
                        $code = trim($data['code']);

                        if (ScrollCode::where('code', $code)->exists()) {
                            Notification::make()
                                ->title('Kode gulungan sudah terdaftar')
                                ->body("Kode \"{$code}\" sudah ada di sistem.")
                                ->danger()
                                ->send();

                            return;
                        }

                        // Cek exists() di atas masih bisa race (2 admin
                        // tambah kode yang sama nyaris bersamaan) — dibekap
                        // UNIQUE constraint di DB, tapi exception-nya
                        // sebelumnya tidak ketangkap di sini sama sekali,
                        // jadi race itu muncul sebagai error 500 mentah.
                        try {
                            ScrollCode::create([
                                'code'            => $code,
                                'film_product_id' => $data['film_product_id'],
                                'max_usage'       => $data['max_usage'] ?? null,
                                'total_length_meters'     => $data['total_length_meters'] ?? null,
                                'remaining_length_meters' => $data['total_length_meters'] ?? null,
                                'purchase_cost'   => $data['purchase_cost'] !== '' ? $data['purchase_cost'] : null,
                                'status'          => 'unallocated',
                            ]);
                        } catch (QueryException $e) {
                            Notification::make()
                                ->title('Kode gulungan sudah terdaftar')
                                ->body("Kode \"{$code}\" baru saja ditambahkan oleh proses lain.")
                                ->danger()
                                ->send();
                        }
                    }),

                // Import massal DIPINDAH ke menu Barang (InventoryItemResource)
                // — supaya kode gulungan hasil import juga otomatis tercatat
                // sebagai unit fisik di Inventaris (dapat kode/QR sendiri),
                // bukan cuma masuk ke tabel scroll_codes tanpa jejak stok.

                // Export Excel
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->action(fn () => Excel::download(
                        new ScrollCodeExport(),
                        'scroll-codes-' . now()->format('Ymd') . '.xlsx'
                    )),

                // Bandingkan remaining_length_meters dengan (total_length_meters
                // - jumlah seluruh riwayat pemakaian) — mendeteksi drift yang
                // bisa terjadi kalau admin pernah pakai "Edit Panjang" manual
                // tanpa ikut menyesuaikan/menghapus baris scroll_code_usages
                // terkait (satu-satunya jalan koreksi sebelum "Batalkan" ada).
                Tables\Actions\Action::make('reconcile')
                    ->label('Cek Rekonsiliasi')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->action(function () {
                        $mismatches = [];

                        ScrollCode::query()
                            ->whereNotNull('total_length_meters')
                            ->chunkById(100, function ($codes) use (&$mismatches) {
                                foreach ($codes as $code) {
                                    $expectedRemaining = $code->expectedRemainingLength();
                                    $diff = round((float) $code->remaining_length_meters - $expectedRemaining, 2);

                                    if (abs($diff) > 0.01) {
                                        $mismatches[] = "{$code->code}: sistem {$code->remaining_length_meters}m, seharusnya {$expectedRemaining}m (selisih {$diff}m)";
                                    }
                                }
                            });

                        if (empty($mismatches)) {
                            Notification::make()
                                ->title('Rekonsiliasi bersih')
                                ->body('Sisa panjang semua kode gulungan cocok dengan Total Panjang dikurangi riwayat pemakaian.')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(count($mismatches) . ' kode gulungan tidak cocok')
                            ->body(implode("\n", array_slice($mismatches, 0, 15)) . (count($mismatches) > 15 ? "\n… dan " . (count($mismatches) - 15) . ' lainnya.' : ''))
                            ->danger()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Riwayat')
                    ->icon('heroicon-o-clock'),

                // "Catat Pemakaian" dan "Tandai Habis" SENGAJA tidak ada di
                // sini lagi — dulu di-copy-paste identik dari
                // InventoryItemResource (risiko lupa sinkron kalau salah
                // satu diubah). Aksi ini konseptualnya milik sistem
                // Inventaris (barang fisik yang di-scan staff), bukan
                // Master Data Kode Gulungan — sekarang HANYA ada di menu
                // Produk PPF/WF. Kode gulungan yang belum dikaitkan ke
                // barang harus dikaitkan dulu lewat menu Produk PPF/WF
                // sebelum pemakaiannya bisa dicatat.
                //
                // Satu-satunya jalan mengisi/koreksi Total & Sisa Panjang
                // untuk kode yang statusnya SUDAH allocated/used sebelum
                // fitur meter ada — bulk action "Isi Total Panjang
                // Standar" sengaja melewati kode begini (sisa sebenarnya
                // tidak diketahui sistem), jadi harus manual di sini kalau
                // adminnya tahu/bisa perkirakan sisa fisiknya.
                Tables\Actions\Action::make('edit_length')
                    ->label('Edit Panjang & Harga')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->fillForm(fn (ScrollCode $record) => [
                        'total_length_meters' => $record->total_length_meters,
                        'remaining_length_meters' => $record->remaining_length_meters,
                        'purchase_cost' => $record->purchase_cost,
                    ])
                    ->form(static::editLengthSchema())
                    ->action(fn (ScrollCode $record, array $data) => static::applyLengthEdit($record, $data)),

                // "Mutasi Roll Film antar cabang" -- keputusan atasan
                // 2026-09-19 (Topik 4, Fase 1, "Keputusan-PPN-DP-Produk-
                // Stok-Ginnva.docx"). Beda dari bulk action "Alokasi ke
                // Toko" di bawah (itu utk kode 'unallocated' yang BELUM
                // pernah dialokasi) -- ini untuk kode 'allocated' yang
                // SUDAH di 1 toko, tapi mau dipindah ke toko lain (mis.
                // toko A over-purchase, kirim sebagian ke toko B).
                Tables\Actions\Action::make('transfer')
                    ->label('Mutasi ke Toko Lain')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->visible(fn (ScrollCode $record) => (auth()->user()?->isFullAccess() ?? false)
                        && $record->status === 'allocated')
                    ->form(static::transferSchema())
                    ->action(fn (ScrollCode $record, array $data) => static::applyTransfer($record, $data)),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (ScrollCode $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'unallocated')
                    // Gulungan yang punya riwayat pemakaian, garansi, mutasi, atau barang terkait tidak dihapus (lihat ScrollCode::deleteBlockers()).
                    ->before(function (Tables\Actions\DeleteAction $action, ScrollCode $record) {
                        if ($reasons = $record->deleteBlockers()) {
                            Notification::make()
                                ->title('Kode gulungan tidak bisa dihapus')
                                ->body("{$record->code}: " . implode(', ', $reasons) . '. Hapus akan menghilangkan data itu; biarkan kodenya atau selesaikan dulu keterkaitannya.')
                                ->danger()
                                ->persistent()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('allocate')
                        ->label('Alokasi ke Toko')
                        ->icon('heroicon-o-building-storefront')
                        ->color('warning')
                        ->visible(fn () => auth()->user()?->isFullAccess())
                        ->form([
                            Forms\Components\Select::make('store_id')
                                ->label('Toko Tujuan')
                                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            // Lock tiap row + WAJIB masih 'unallocated' —
                            // SEBELUMNYA overwrite status/store_id tanpa
                            // cek status saat ini sama sekali, jadi bulk
                            // alokasi yang tidak sengaja mencakup kode yang
                            // sudah 'allocated'/'used' (mis. baru saja
                            // dipakai WarrantyObserver di request lain)
                            // bisa menimpanya balik ke 'allocated' —
                            // 1 kode fisik jadi kelihatan "belum dipakai"
                            // padahal sudah terpasang di mobil lain.
                            $allocated = 0;
                            $skipped = 0;
                            $inStock = [];

                            foreach ($records as $record) {
                                DB::transaction(function () use ($record, $data, &$allocated, &$skipped, &$inStock) {
                                    $locked = ScrollCode::where('id', $record->id)->lockForUpdate()->first();
                                    if (! $locked || $locked->status !== 'unallocated') {
                                        $skipped++;

                                        return;
                                    }

                                    // Gulungan milik barang yang masih Ada Stok (di gudang) tidak boleh dialokasikan dari sini: statusnya akan
                                    // bertentangan dengan barangnya, dan Catat Masuk berikutnya mengosongkan alokasinya. Alokasikan lewat Catat
                                    // Keluar pada barangnya (Produk PPF/WF atau aplikasi), yang sekaligus memilih toko tujuan.
                                    $item = $locked->inventoryItem()->first(['id', 'code', 'status']);
                                    if ($item && $item->status === 'in_stock') {
                                        $inStock[] = "{$locked->code} ({$item->code})";

                                        return;
                                    }

                                    $locked->update([
                                        'store_id'     => $data['store_id'],
                                        'status'       => 'allocated',
                                        'allocated_at' => now(),
                                    ]);
                                    $allocated++;
                                });
                            }

                            Notification::make()
                                ->title('Alokasi selesai')
                                ->body("{$allocated} kode dialokasikan."
                                    . ($skipped > 0 ? " {$skipped} dilewati (sudah dialokasi/dipakai)." : '')
                                    . ($inStock !== [] ? ' ' . count($inStock) . ' dilewati karena barangnya masih Ada Stok di gudang (catat keluar dengan tombol Catat Keluar di Produk PPF/WF, atau lewat aplikasi karyawan): ' . implode('; ', array_slice($inStock, 0, 10)) . (count($inStock) > 10 ? '; …' : '') . '.' : ''))
                                ->color($inStock !== [] ? 'warning' : 'success')
                                ->persistent($inStock !== [])
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Backfill untuk kode lama (dibuat sebelum fitur
                    // pelacakan meter ada) — cuma isi yang statusnya
                    // 'unallocated' (belum pernah dipakai sama sekali),
                    // SENGAJA lewati yang 'allocated'/'used' karena kita
                    // tidak tahu berapa meter yang sudah kepakai duluan di
                    // gulungan itu — mengisi total/sisa penuh untuk kode
                    // yang sudah separuh dipakai akan salah menampilkan
                    // sisa yang lebih besar dari yang sebenarnya.
                    Tables\Actions\BulkAction::make('fill_default_length')
                        ->label('Isi Total Panjang Standar')
                        ->icon('heroicon-o-arrows-pointing-out')
                        ->color('gray')
                        ->visible(fn () => auth()->user()?->isFullAccess())
                        ->requiresConfirmation()
                        ->modalDescription('Isi Total Panjang otomatis (PPF 15 m, Window Film 30 m) untuk kode terpilih yang belum punya nilai dan belum dialokasikan. Kode yang sudah dialokasi atau dipakai dilewati.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $filled = 0;
                            $skipped = 0;

                            foreach ($records->loadMissing('filmProduct') as $record) {
                                if ($record->total_length_meters !== null || $record->status !== 'unallocated') {
                                    $skipped++;
                                    continue;
                                }

                                $length = $record->filmProduct?->product_type === 'ppf' ? 15 : 30;
                                $record->update([
                                    'total_length_meters' => $length,
                                    'remaining_length_meters' => $length,
                                ]);
                                $filled++;
                            }

                            Notification::make()
                                ->title('Selesai')
                                ->body("{$filled} kode diisi. " . ($skipped > 0 ? "{$skipped} kode dilewati (sudah punya nilai, atau sudah dialokasi/dipakai)." : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // BUKAN Tables\Actions\DeleteBulkAction::make() biasa —
                    // itu tidak punya pengaman status sama sekali, beda
                    // dari DeleteAction per-baris di atas yang sengaja
                    // dibatasi cuma untuk 'unallocated'. Bulk polos bisa
                    // menghapus kode yang sudah allocated/used sekaligus,
                    // menghilangkan riwayat alokasi/pemakaiannya.
                    Tables\Actions\BulkAction::make('delete_unallocated')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn () => auth()->user()?->isFullAccess())
                        ->requiresConfirmation()
                        ->modalDescription('Hapus kode gulungan terpilih? Cuma yang masih "Belum dialokasikan" yang akan dihapus — kode yang sudah dialokasi/dipakai dilewati.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $deleted = 0;
                            $skipped = 0;
                            $blocked = [];

                            foreach ($records as $record) {
                                if ($record->status !== 'unallocated') {
                                    $skipped++;
                                } elseif ($reasons = $record->deleteBlockers()) {
                                    // Punya riwayat pemakaian, garansi, mutasi, atau barang terkait: tidak dihapus (ScrollCode::deleteBlockers()).
                                    $blocked[] = "{$record->code} (" . implode(', ', $reasons) . ')';
                                } else {
                                    $record->delete();
                                    $deleted++;
                                }
                            }

                            Notification::make()
                                ->title('Selesai')
                                ->body("{$deleted} kode dihapus. "
                                    . ($skipped > 0 ? "{$skipped} kode dilewati (sudah dialokasi/dipakai). " : '')
                                    . ($blocked !== [] ? count($blocked) . ' kode dilewati karena punya data terkait: ' . implode('; ', array_slice($blocked, 0, 10)) . (count($blocked) > 10 ? '; …' : '') . '.' : ''))
                                ->color($blocked !== [] ? 'warning' : 'success')
                                ->persistent($blocked !== [])
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Skema form "Edit Panjang & Harga", dipakai aksi baris di daftar dan aksi di header halaman Lihat. */
    public static function editLengthSchema(): array
    {
        return [
            Forms\Components\TextInput::make('total_length_meters')
                ->label('Total Panjang (meter)')
                ->numeric()
                ->minValue(0.01),

            Forms\Components\TextInput::make('remaining_length_meters')
                ->label('Sisa Panjang (meter)')
                ->numeric()
                ->minValue(0)
                ->helperText('Perkirakan sisa fisik gulungan sekarang kalau kode ini sudah pernah dipakai sebelum fitur ini ada.'),

            Forms\Components\TextInput::make('purchase_cost')
                ->label('Harga Beli Gulungan (opsional)')
                ->numeric()
                ->prefix('Rp')
                ->minValue(0)
                ->helperText('Harga beli TOTAL gulungan ini — dipakai hitung HPP & Laba Kotor di Laporan Penjualan Per Periode.'),
        ];
    }

    public static function editLengthDefaults(ScrollCode $record): array
    {
        return [
            'total_length_meters' => $record->total_length_meters,
            'remaining_length_meters' => $record->remaining_length_meters,
            'purchase_cost' => $record->purchase_cost,
        ];
    }

    public static function applyLengthEdit(ScrollCode $record, array $data): void
    {
        // Selisih terhadap riwayat pemakaian dicatat sebagai koreksi, supaya "Cek Rekonsiliasi" tetap bersih.
        $record->correctLength(
            filled($data['total_length_meters'] ?? null) ? (float) $data['total_length_meters'] : null,
            filled($data['remaining_length_meters'] ?? null) ? (float) $data['remaining_length_meters'] : null
        );
        $record->update(['purchase_cost' => ($data['purchase_cost'] ?? '') !== '' ? $data['purchase_cost'] : null]);

        Notification::make()->title('Data panjang & harga diperbarui')->success()->send();
    }

    /** Skema form "Mutasi ke Toko Lain". */
    public static function transferSchema(): array
    {
        return [
            Forms\Components\Select::make('to_store_id')
                ->label('Toko Tujuan')
                ->options(fn (ScrollCode $record) => Store::where('is_active', true)
                    ->where('id', '!=', $record->store_id)
                    ->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\Textarea::make('reason')
                ->label('Alasan Mutasi')
                ->required()
                ->placeholder('Mis. toko asal kelebihan stok, permintaan toko tujuan'),
        ];
    }

    public static function applyTransfer(ScrollCode $record, array $data): void
    {
        try {
            $record->transferTo((int) $data['to_store_id'], $data['reason'], auth()->id());
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Mutasi dicatat')->success()->send();
    }

    public static function getRelations(): array
    {
        return [
            UsagesRelationManager::class,
            OffcutsRelationManager::class,
            TransfersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScrollCodes::route('/'),
            'view'  => Pages\ViewScrollCode::route('/{record}'),
        ];
    }
}