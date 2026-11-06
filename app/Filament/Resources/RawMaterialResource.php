<?php

namespace App\Filament\Resources;

use App\Exports\RawMaterialImportTemplateExport;
use App\Filament\Resources\RawMaterialResource\Pages;
use App\Filament\Resources\RawMaterialResource\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\RawMaterialResource\RelationManagers\BatchesRelationManager;
use App\Filament\Resources\RawMaterialResource\RelationManagers\MovementsRelationManager;
use App\Models\RawMaterial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class RawMaterialResource extends Resource
{
    // Tombol "Buat Permintaan Beli" (sama dengan di widget Perlu Perhatian Dashboard Inventaris).
    use \App\Filament\InventoryWidgets\Concerns\OffersRestockRequest;

    protected static ?string $model = RawMaterial::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- "Master Data Stok".
    protected static ?string $navigationGroup = 'Master Data Stok';

    protected static ?int $navigationSort = 20;

    // "Daftar Bahan Baku" — samakan dgn Majoo (Penjualan > Inventori >
    // Daftar Bahan Baku). Label model tetap "Bahan Baku" (tombol
    // "Tambah Bahan Baku").
    protected static ?string $navigationLabel = 'Daftar Bahan Baku';

    protected static ?string $modelLabel = 'Bahan Baku';

    protected static ?string $pluralModelLabel = 'Bahan Baku';

    /**
     * Bahan baku memuat stok dan harga modal (dasar HPP), jadi tidak terbuka lewat akses bawaan akun baru (menu_access kosong):
     * hanya akses penuh atau staf yang menunya dicentang eksplisit (audit Daftar Bahan Baku, 2026-11-02). Mengubah data dan
     * mencatat stok butuh izin Tambah/Ubah, melihat harga butuh izin Lihat, menghapus butuh izin Hapus.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    /** Boleh melihat harga modal: akses penuh atau izin "Lihat" pada menu Daftar Bahan Baku. */
    public static function canSeeCost(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
    }

    /**
     * SEBELUMNYA tidak ada override sama sekali di sini dan tidak ada
     * RawMaterialPolicy terdaftar — canCreate()/canEdit()/canDelete()/
     * canDeleteAny() bawaan Resource selalu FALSE untuk siapa pun tanpa
     * policy (default-deny Laravel). Akibatnya tombol "New", "Edit", DAN
     * "Hapus" (single maupun bulk) tidak pernah muncul — sama bug class
     * dengan InventoryItemResource (audit sebelumnya).
     *
     * canCreate()/canEdit() dibiarkan seluas canViewAny() — EditAction di
     * table() tidak punya ->visible() tambahan apa pun, beda dari
     * DeleteAction/DeleteBulkAction yang SUDAH eksplisit
     * ->visible(isFullAccess()) (lihat EditRawMaterial & bulkActions() di
     * table()) — canDelete()/canDeleteAny() dibatasi isFullAccess() supaya
     * konsisten dengan guard yang sudah ada itu.
     */
    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && auth()->user()->hasModuleAction(static::class, 'create', false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && auth()->user()->hasModuleAction(static::class, 'update', false);
    }

    /**
     * hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
     * default FALSE (tetap ketat spt sebelumnya, isFullAccess()-only).
     */
    public static function canDelete($record): bool
    {
        return static::canDeleteAny();
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || (static::canViewAny() && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Bahan Baku')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Bahan')
                        ->required()
                        ->maxLength(255)
                        // Bahan baru dengan nama yang sudah terdaftar ditolak (tanpa membedakan huruf besar/kecil), supaya
                        // stok satu bahan tidak terpecah di dua baris. Bahan lama tidak dicek ulang saat diubah.
                        ->rule(fn (?RawMaterial $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                            if ($record === null && RawMaterial::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])->exists()) {
                                $fail('Bahan dengan nama ini sudah terdaftar. Catat stok masuk di bahan yang ada, atau bedakan namanya (mis. tambahkan merek/ukuran).');
                            }
                        }),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode Barang')
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->placeholder('Kode internal yang sudah ada, mis. dari sistem lama')
                        ->helperText('Opsional — isi kalau bahan ini sudah punya kode barang sendiri.'),

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. Adhesive, Backing Paper, Packaging')
                        // Saran dari kategori yang sudah ada, dan ejaan/huruf besar-kecil disamakan dengannya saat disimpan,
                        // supaya "adhesive" dan "Adhesive" tidak menjadi dua kategori di filter dan laporan.
                        ->datalist(fn () => static::categoryOptions())
                        ->dehydrateStateUsing(fn (?string $state) => static::canonicalCategory($state)),

                    Forms\Components\TextInput::make('unit')
                        ->label('Satuan Dasar (Stok & Konsumsi)')
                        ->required(fn (?RawMaterial $record) => ! static::unitLocked($record))
                        ->maxLength(20)
                        ->placeholder('Mis. ml, gram, meter, pcs')
                        // Angka stok dan riwayat disimpan tanpa satuan; mengganti satuan setelah ada riwayat mengubah arti semua angka lama.
                        ->disabled(fn (?RawMaterial $record) => static::unitLocked($record))
                        ->dehydrated(fn (?RawMaterial $record) => ! static::unitLocked($record))
                        ->helperText(fn (?RawMaterial $record) => static::unitLocked($record)
                            ? 'Terkunci karena bahan ini sudah punya riwayat stok — mengganti satuan mengubah arti semua angka lama. Kalau salah, daftarkan bahan baru dan kosongkan stok yang lama.'
                            : null),

                    // Konversi satuan beli (audit Majoo f38) — opsional,
                    // murni pembantu input di "Catat Stok". Stok tetap
                    // selalu dalam Satuan Dasar di atas.
                    Forms\Components\TextInput::make('purchase_unit')
                        ->label('Satuan Beli (opsional)')
                        ->maxLength(20)
                        ->placeholder('Mis. Galon, Liter, Dus')
                        ->helperText('Isi kalau bahan ini dibeli dalam satuan besar tapi dipakai/dicatat dalam Satuan Dasar yang lebih kecil (mis. beli per Galon, pakai per ml).')
                        ->live(),

                    Forms\Components\TextInput::make('purchase_conversion_factor')
                        ->label('1 Satuan Beli = Berapa Satuan Dasar')
                        ->numeric()
                        ->minValue(0.0001)
                        ->placeholder('Mis. 3785 (kalau 1 Galon = 3785 ml)')
                        ->visible(fn (Forms\Get $get) => filled($get('purchase_unit')))
                        ->required(fn (Forms\Get $get) => filled($get('purchase_unit')))
                        ->live(),

                    // BUKAN "Tanggal Masuk Batch Ini" di "Catat Stok" —
                    // field itu yang benar-benar menentukan urutan FIFO.
                    // Ini cuma tanggal pendaftaran bahan ke sistem (sekali
                    // saat dibuat), TIDAK ikut sinkron begitu ada batch
                    // baru — sengaja dilabeli beda supaya admin tidak
                    // salah paham keduanya adalah field yang sama.
                    // Saat mendaftar, tanggal ini juga menjadi tanggal masuk batch stok awal (lihat CreateRawMaterial). Sesudah itu
                    // hanya catatan pendaftaran dan dikunci; tanggal batch berikutnya diisi lewat "Catat Stok".
                    Forms\Components\DatePicker::make('received_date')
                        ->label(fn (?RawMaterial $record) => $record === null ? 'Tanggal Masuk Stok Awal' : 'Tanggal Pendaftaran')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required(fn (?RawMaterial $record) => $record === null || $record->received_date === null)
                        ->disabled(fn (?RawMaterial $record) => $record !== null && $record->received_date !== null)
                        ->helperText(fn (?RawMaterial $record) => match (true) {
                            $record === null => 'Tanggal masuk batch stok awal (urutan FIFO). Kalau stok awal 0, ini hanya catatan tanggal pendaftaran.',
                            $record->received_date === null => 'Data lama belum punya tanggal ini — isi tanggal perkiraan (boleh hari ini kalau tidak tahu pastinya).',
                            default => 'Catatan kapan bahan ini didaftarkan; tidak bisa diubah. Tanggal masuk tiap batch dicatat lewat "Catat Stok".',
                        }),

                    Forms\Components\TextInput::make('unit_cost')
                        ->label('Harga Terakhir per Satuan')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->visible(fn () => static::canSeeCost())
                        ->helperText('Opsional — dipakai menilai stok yang batch-nya belum punya harga, jadi mengubahnya ikut mengubah nilai stok itu. Harga batch baru diisi lewat "Catat Stok".'),

                    Forms\Components\TextInput::make('reorder_point')
                        ->label('Ambang Stok Menipis')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Opsional — barang ditandai "Stok Menipis" kalau current stock ≤ angka ini.'),

                    Forms\Components\TextInput::make('adjustment_note_threshold')
                        ->label('Selisih Opname Tanpa Catatan')
                        ->numeric()
                        ->minValue(0)
                        ->suffix(fn (?RawMaterial $record) => $record?->unit)
                        ->helperText('Opsional — kalau selisih hasil hitung fisik dengan stok sistem lebih besar dari angka ini (dalam satuan bahan ini), Sesuaikan Stok/Opname wajib diberi catatan alasan. Kosongkan untuk mematikan aturan.'),

                    Forms\Components\Select::make('preferred_supplier_id')
                        ->label('Pemasok Utama')
                        ->relationship('preferredSupplier', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                        ->searchable()
                        ->preload()
                        ->placeholder('Belum ditentukan')
                        ->helperText('Opsional — tampil di peringatan stok menipis dan jadi isian awal Supplier saat permohonan pembelian ditandai terpenuhi.'),

                    // Hanya untuk batch stok awal. Kedaluwarsa bahan yang sebenarnya dihitung dari batch yang masih ada stoknya,
                    // jadi kolom ini tidak ditampilkan lagi saat mengubah (mengisinya tidak berpengaruh apa pun).
                    Forms\Components\DatePicker::make('expiry_date')
                        ->label('Kedaluwarsa Stok Awal')
                        ->visible(fn (?RawMaterial $record) => $record === null)
                        ->helperText('Opsional — hanya untuk batch stok awal; kosongkan kalau tidak punya masa pakai. Batch berikutnya diisi lewat "Catat Stok".'),

                    Forms\Components\TextInput::make('current_stock')
                        ->label(fn (?RawMaterial $record) => $record === null ? 'Stok Awal' : 'Stok Saat Ini')
                        ->numeric()
                        ->default(0)
                        ->disabled(fn (?RawMaterial $record) => $record !== null)
                        ->dehydrated(fn (?RawMaterial $record) => $record === null)
                        ->helperText(fn (?RawMaterial $record) => $record === null
                            ? 'Kosongkan/isi 0 kalau belum ada stok fisik sama sekali — otomatis tercatat sebagai 1 batch (dengan tanggal masuk di atas) supaya ada jejaknya sejak awal.'
                            : 'Stok cuma bisa diubah lewat tombol "Catat Stok" di daftar — supaya selalu ada riwayatnya, tidak diedit langsung di sini.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->helperText('Matikan untuk bahan yang tidak dipakai lagi: hilang dari pemilih Memo Bahan/Permohonan Pembelian dan dari peringatan, tetapi stok, riwayat, dan laporannya tetap ada. Bisa diaktifkan lagi kapan saja.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Permohonan pembelian yang sudah disetujui dan masih menunggu barangnya untuk bahan ini (dari toko mana pun). */
    public static function approvedPurchase(RawMaterial $record): ?\App\Models\PurchaseRequest
    {
        return \App\Models\PurchaseRequest::withoutGlobalScopes()
            ->where('item_type', 'raw_material')
            ->where('item_id', $record->getKey())
            ->where('status', 'approved')
            ->orderBy('id')
            ->first();
    }

    /** Kategori yang sudah dipakai, untuk saran isian. */
    public static function categoryOptions(): array
    {
        return RawMaterial::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category')->all();
    }

    /** Samakan kategori dengan yang sudah ada (tanpa membedakan huruf besar/kecil); kategori baru disimpan apa adanya. */
    public static function canonicalCategory(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return RawMaterial::query()->whereRaw('LOWER(category) = ?', [mb_strtolower($value)])->orderBy('id')->value('category') ?? $value;
    }

    /** Satuan dasar terkunci begitu bahan punya riwayat stok. */
    private static function unitLocked(?RawMaterial $record): bool
    {
        return $record !== null && $record->movements()->exists();
    }

    public static function table(Table $table): Table
    {
        return $table
            // Batch yang masih ada stoknya dimuat sekali untuk seluruh halaman (nilai stok dan kedaluwarsa dihitung dari sini,
            // tanpa query per baris), dan tanggal pergerakan terakhir diambil sebagai subquery.
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['preferredSupplier:id,name', 'batches' => fn ($batches) => $batches->where('quantity', '>', 0)])
                ->withMax('movements as last_movement_at', 'created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode disalin')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Bahan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Pendaftaran')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok (Total)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->formatStateUsing(fn (RawMaterial $record) => number_format((float) $record->current_stock, 2) . ' ' . $record->unit)
                    ->badge()
                    ->color(fn (RawMaterial $record) => $record->isLowStock() ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->label('Ambang Menipis')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, RawMaterial $record) => $state !== null ? number_format((float) $state, 2) . ' ' . $record->unit : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('preferredSupplier.name')
                    ->label('Pemasok Utama')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
                    ->placeholder('—')
                    ->visible(fn () => static::canSeeCost())
                    ->toggleable(isToggledHiddenByDefault: true),

                // BUKAN kolom expiry_date mentah — itu cuma snapshot saat
                // pendaftaran (lihat catatan RawMaterial::earliestActiveExpiryDate()),
                // tidak ikut sinkron begitu ada batch ke-2 dst dengan
                // tanggal beda. Dihitung dari batch yang MASIH ADA
                // stoknya, paling awal kedaluwarsa — konsisten dengan
                // isExpired()/isNearExpiry() dan dengan tab "Riwayat Batch".
                Tables\Columns\TextColumn::make('earliest_expiry')
                    ->label('Kedaluwarsa')
                    ->getStateUsing(fn (RawMaterial $record) => $record->earliestActiveExpiryDate())
                    ->date('d M Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (RawMaterial $record) => match (true) {
                        $record->isExpired() => 'danger',
                        $record->isNearExpiry() => 'warning',
                        default => 'gray',
                    }),

                // Nilai dari harga tiap batch (FIFO), lihat RawMaterial::stockValue(); hanya bagi yang boleh melihat harga.
                Tables\Columns\TextColumn::make('stock_value')
                    ->label('Nilai Stok')
                    ->getStateUsing(fn (RawMaterial $record) => $record->stockValue())
                    ->money('IDR')
                    ->description(fn (RawMaterial $record) => $record->hasUnpricedStock() ? 'Sebagian belum berharga' : null)
                    ->visible(fn () => static::canSeeCost())
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('last_movement_at')
                    ->label('Terakhir Bergerak')
                    ->date('d M Y')
                    ->placeholder('Belum pernah')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Bawaan hanya bahan aktif; pilih "Semua" atau "Nonaktif" untuk melihat bahan yang sudah tidak dipakai.
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->default(true)
                    ->queries(
                        true: fn ($query) => $query->where('raw_materials.is_active', true),
                        false: fn ($query) => $query->where('raw_materials.is_active', false),
                        blank: fn ($query) => $query,
                    ),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => RawMaterial::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray()),

                Tables\Filters\Filter::make('low_stock')
                    ->label('Stok Menipis')
                    ->query(fn ($query) => $query->whereNotNull('reorder_point')
                        ->whereColumn('current_stock', '<=', 'reorder_point')),

                Tables\Filters\Filter::make('near_expiry')
                    ->label('Mendekati/Sudah Kedaluwarsa')
                    // Sama dengan kolom "Kedaluwarsa" di atas — cek batch
                    // yang masih ada stoknya, BUKAN kolom expiry_date induk.
                    ->query(fn ($query) => $query->whereHas('batches', fn ($q) => $q
                        ->where('quantity', '>', 0)
                        ->whereNotNull('expiry_date')
                        ->whereDate('expiry_date', '<=', now()->addDays(30)))),

                Tables\Filters\Filter::make('dead_stock')
                    ->label('Tidak Bergerak (' . RawMaterial::DEAD_STOCK_DAYS . '+ hari)')
                    // Menurut riwayat pergerakan, bukan updated_at (lihat RawMaterial::scopeDeadStock()).
                    ->query(fn ($query) => $query->deadStock()),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Download Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->action(fn () => Excel::download(
                        new RawMaterialImportTemplateExport(),
                        'Template-Import-Bahan-Baku.xlsx'
                    )),

                Tables\Actions\Action::make('import')
                    ->label('Import Excel')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('File Excel')
                            ->required()
                            ->disk('local')
                            ->directory('raw-material-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai format dari tombol "Download Template". Baris pertama (header) otomatis dilewati. Stok Awal (dalam Satuan, mis. ml) otomatis dicatat sebagai riwayat "Masuk" supaya tetap ada jejaknya. Satuan Beli (mis. botol) dan Isi per Satuan Beli (mis. 400 ml) boleh dikosongkan, tapi bila diisi harus keduanya.'),
                    ])
                    ->action(fn (array $data) => static::importMaterials($data['file']))
                    ->modalSubmitActionLabel('Import'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('record_movement')
                    ->label('Catat Stok')
                    ->icon('heroicon-o-arrow-path')
                    ->button()
                    ->color('primary')
                    ->visible(fn (RawMaterial $record) => static::canEdit($record))
                    ->form([
                        // Ada permohonan pembelian disetujui untuk bahan ini: arahkan ke jalur yang menjaga stok dan permohonan tetap sejalan.
                        Forms\Components\Placeholder::make('open_purchase')
                            ->hiddenLabel()
                            ->content(fn (RawMaterial $record) => ($purchase = static::approvedPurchase($record))
                                ? "Ada permohonan pembelian disetujui untuk bahan ini ({$purchase->request_number}, {$purchase->quantity} {$purchase->unit}). Kalau ini kiriman dari permohonan itu, catat penerimaannya lewat Permohonan Pembelian > Tandai Terpenuhi (stok ikut bertambah) supaya tidak tercatat dua kali."
                                : '')
                            ->visible(fn (RawMaterial $record) => static::approvedPurchase($record) !== null),

                        Forms\Components\Select::make('type')
                            ->label('Jenis')
                            ->options(['in' => 'Masuk', 'out' => 'Keluar'])
                            ->required()
                            ->live(),

                        // Input dalam Satuan Beli (audit Majoo f38) --
                        // cuma tampil kalau bahan ini punya purchase_unit
                        // dikonfigurasi & jenisnya "Masuk" (konversi
                        // satuan cuma masuk akal utk stok masuk, bukan
                        // keluar/konsumsi yang SELALU dicatat dlm Satuan
                        // Dasar apa adanya).
                        Forms\Components\Toggle::make('use_purchase_unit')
                            ->label(fn (RawMaterial $record) => 'Input dalam Satuan Beli (' . $record->purchase_unit . ')')
                            ->visible(fn (RawMaterial $record, Forms\Get $get) => $get('type') === 'in' && filled($record->purchase_unit))
                            ->live()
                            ->default(false),

                        Forms\Components\TextInput::make('purchase_quantity')
                            ->label(fn (RawMaterial $record) => 'Jumlah (' . $record->purchase_unit . ')')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->visible(fn (Forms\Get $get) => $get('type') === 'in' && $get('use_purchase_unit'))
                            ->helperText(fn (RawMaterial $record) => $record->purchase_conversion_factor
                                ? '1 ' . $record->purchase_unit . ' = ' . rtrim(rtrim(number_format((float) $record->purchase_conversion_factor, 4, '.', ''), '0'), '.') . ' ' . $record->unit
                                : null),

                        Forms\Components\TextInput::make('purchase_total_cost')
                            ->label('Total Harga Beli (opsional)')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->visible(fn (Forms\Get $get) => static::canSeeCost() && $get('type') === 'in' && $get('use_purchase_unit'))
                            ->helperText('Total harga utk seluruh jumlah di atas (bukan per satuan) — sistem otomatis hitung harga per ' . '"Satuan Dasar" dari sini.'),

                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->required(fn (Forms\Get $get) => ! $get('use_purchase_unit'))
                            ->minValue(0.01)
                            ->suffix(fn (RawMaterial $record) => $record->unit)
                            ->visible(fn (Forms\Get $get) => ! $get('use_purchase_unit')),

                        // Setiap "Masuk" jadi 1 batch baru (tanggal masuk +
                        // kedaluwarsa sendiri) — dipakai untuk konsumsi FIFO
                        // saat "Keluar" nanti. Tidak relevan untuk "Keluar"
                        // makanya disembunyikan.
                        Forms\Components\DatePicker::make('received_date')
                            ->label('Tanggal Masuk Batch Ini')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->default(now())
                            ->maxDate(now())
                            ->visible(fn (Forms\Get $get) => $get('type') === 'in')
                            ->required(fn (Forms\Get $get) => $get('type') === 'in'),

                        Forms\Components\DatePicker::make('expiry_date')
                            ->label('Tanggal Kedaluwarsa Batch Ini')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->helperText('Opsional — kosongkan kalau batch ini tidak punya masa pakai.')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'in'),

                        // Per-batch, bukan cuma 1 harga rata-rata di
                        // raw_materials.unit_cost — supaya nilai stok bisa
                        // dihitung dari harga beli batch itu sendiri kalau
                        // harganya berubah antar pembelian.
                        Forms\Components\TextInput::make('unit_cost')
                            ->label('Harga per Satuan Batch Ini')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->default(fn (RawMaterial $record) => $record->unit_cost)
                            ->helperText('Opsional — kosongkan untuk pakai harga terakhir tersimpan. Untuk estimasi nilai stok di Dashboard Inventaris.')
                            ->visible(fn (Forms\Get $get) => static::canSeeCost() && $get('type') === 'in' && ! $get('use_purchase_unit')),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('Mis. nomor PO, nama supplier, dipakai untuk booking apa'),
                    ])
                    ->action(function (RawMaterial $record, array $data) {
                        // Input dalam Satuan Beli (audit Majoo f38) --
                        // konversi ke Satuan Dasar SEBELUM dikirim ke
                        // recordMovement(), supaya movement yang tersimpan
                        // tetap 100% dalam satuan dasar seperti biasa
                        // (tidak ada perubahan skema raw_material_movements).
                        if (! empty($data['use_purchase_unit'])) {
                            $converted = $record->convertPurchaseToBaseUnit(
                                (float) $data['purchase_quantity'],
                                isset($data['purchase_total_cost']) && $data['purchase_total_cost'] !== '' ? (float) $data['purchase_total_cost'] : null,
                            );
                            $quantity = $converted['quantity'];
                            $unitCost = $converted['unitCost'];
                        } else {
                            $quantity = (float) $data['quantity'];
                            $unitCost = isset($data['unit_cost']) && $data['unit_cost'] !== '' ? (float) $data['unit_cost'] : null;
                        }

                        try {
                            $record->recordMovement(
                                $data['type'],
                                $quantity,
                                auth()->id(),
                                $data['note'] ?? null,
                                $data['received_date'] ?? null,
                                $data['expiry_date'] ?? null,
                                $unitCost,
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Tidak bisa mencatat stok')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Stok dicatat')
                            ->body(! empty($data['use_purchase_unit'])
                                ? 'Dikonversi jadi ' . number_format($quantity, 2, ',', '.') . ' ' . $record->unit . '.'
                                : null)
                            ->success()
                            ->send();
                    }),

                // Hanya muncul untuk bahan yang stoknya menipis, belum punya permintaan beli berjalan, dan bagi yang boleh membuat Permohonan Pembelian.
                static::restockAction(),

                Tables\Actions\ActionGroup::make([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('adjust_stock')
                    ->label('Sesuaikan Stok')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->visible(fn (RawMaterial $record) => static::canEdit($record))
                    ->form([
                        Forms\Components\TextInput::make('actual_quantity')
                            ->label('Hasil Hitung Fisik Sebenarnya')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->suffix(fn (RawMaterial $record) => $record->unit)
                            ->helperText(fn (RawMaterial $record) => 'Stok di sistem saat ini: ' . number_format((float) $record->current_stock, 2) . ' ' . $record->unit . '. Isi jumlah hasil hitung fisik yang SEBENARNYA — selisihnya dihitung otomatis.'),

                        Forms\Components\Textarea::make('note')
                            ->label(fn (RawMaterial $record) => $record->adjustment_note_threshold !== null
                                ? 'Catatan (wajib bila selisih lebih dari ' . rtrim(rtrim(number_format((float) $record->adjustment_note_threshold, 2, ',', '.'), '0'), ',') . ' ' . $record->unit . ')'
                                : 'Catatan')
                            ->required(fn (RawMaterial $record, Forms\Get $get) => is_numeric($get('actual_quantity'))
                                && $record->adjustmentNeedsNote((float) $get('actual_quantity')))
                            ->placeholder('Mis. hasil stock opname bulanan, alasan selisih (tumpah/rusak/salah catat)'),
                    ])
                    ->action(function (RawMaterial $record, array $data) {
                        $movement = $record->adjustStock((float) $data['actual_quantity'], auth()->id(), $data['note'] ?? null);

                        if ($movement === null) {
                            Notification::make()
                                ->title('Tidak ada selisih')
                                ->body('Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.')
                                ->info()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Stok disesuaikan')
                            ->body('Selisih ' . ($movement->quantity > 0 ? '+' : '') . $movement->quantity . ' ' . $record->unit . ' dicatat sebagai penyesuaian.')
                            ->success()
                            ->send();
                    }),

                // "Stok Terbuang" (write-off) — beda dari "Sesuaikan Stok"
                // yang generik: ada alasan terstruktur + jurnal kerugian
                // otomatis (6520). Lihat StockWriteOffService.
                Tables\Actions\Action::make('write_off')
                    ->label('Catat Stok Terbuang')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (RawMaterial $record) => static::canEdit($record))
                    ->modalHeading('Catat Stok Terbuang')
                    ->form([
                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah Terbuang')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(fn (RawMaterial $record) => (float) $record->current_stock)
                            ->suffix(fn (RawMaterial $record) => $record->unit)
                            ->helperText(fn (RawMaterial $record) => 'Stok saat ini: ' . number_format((float) $record->current_stock, 2) . ' ' . $record->unit
                                . (! static::canSeeCost()
                                    ? '.'
                                    : (($record->unit_cost !== null && (float) $record->unit_cost > 0)
                                        ? '. Harga modal Rp' . number_format((float) $record->unit_cost, 0, ',', '.') . '/' . $record->unit . ' — jurnal kerugian dibuat otomatis.'
                                        : '. Harga modal belum diisi — stok tetap dikurangi, tapi jurnal kerugian dilewati.'))),

                        Forms\Components\Select::make('reason')
                            ->label('Alasan')
                            ->options(\App\Models\StockWriteOff::REASON_LABELS)
                            ->in(array_keys(\App\Models\StockWriteOff::REASON_LABELS))
                            ->required()
                            ->live()
                            ->native(false),

                        Forms\Components\Textarea::make('note')
                            ->label(fn (Forms\Get $get) => in_array($get('reason'), \App\Models\StockWriteOff::REASONS_NEEDING_NOTE, true) ? 'Catatan (wajib untuk alasan ini)' : 'Catatan (opsional)')
                            ->required(fn (Forms\Get $get) => in_array($get('reason'), \App\Models\StockWriteOff::REASONS_NEEDING_NOTE, true))
                            ->rows(2)
                            ->maxLength(255),
                    ])
                    ->action(function (RawMaterial $record, array $data) {
                        try {
                            $wo = app(\App\Services\StockWriteOffService::class)->record(
                                $record,
                                'raw_material',
                                (float) $data['quantity'],
                                $data['reason'],
                                $data['note'] ?? null,
                                auth()->id(),
                            );

                            Notification::make()
                                ->title('Stok terbuang dicatat')
                                ->body(! static::canSeeCost()
                                    ? ($wo->total_value !== null ? 'Kerugian diposting ke Jurnal Umum.' : 'Stok dikurangi.')
                                    : ($wo->total_value !== null
                                        ? 'Kerugian Rp' . number_format((float) $wo->total_value, 0, ',', '.') . ' diposting ke Jurnal Umum.'
                                        : 'Stok dikurangi. Jurnal kerugian dilewati (harga modal belum diisi).'))
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal mencatat stok terbuang')->body($e->getMessage())->danger()->send();
                        }
                    }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Label QR untuk rak/kemasan: scan di mobile langsung membuka bahan ini (lihat StockScanCode).
                    Tables\Actions\BulkAction::make('print_labels')
                        ->label('Cetak Label QR')
                        ->icon('heroicon-o-qr-code')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (\Illuminate\Support\Collection $records) => \App\Support\StockScanCode::labelsPdf($records->values(), 'Label Bahan Baku')),
                    // Diproses satu-satu: yang belum punya riwayat stok terhapus, yang punya dilewati dengan laporan jumlahnya.
                    Tables\Actions\BulkAction::make('delete')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $deleted = 0;
                            $blocked = 0;

                            foreach ($records as $record) {
                                if (\App\Support\StockMasterDeletion::blocker($record, 'Bahan baku') === null) {
                                    $record->delete();
                                    $deleted++;
                                } else {
                                    $blocked++;
                                }
                            }

                            if ($blocked > 0) {
                                \Filament\Notifications\Notification::make()
                                    ->title($deleted > 0 ? "{$deleted} dihapus, {$blocked} tidak bisa dihapus" : 'Tidak ada yang bisa dihapus')
                                    ->body("{$blocked} sudah punya riwayat pergerakan stok sehingga dilewati. Menghapusnya akan menghapus riwayat itu dan merusak laporan persediaan dan HPP.")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            \Filament\Notifications\Notification::make()->title("{$deleted} dihapus")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Baris pertama (header) dilewati. Kolom: Nama Bahan, Kategori,
     * Satuan, Stok Awal, Ambang Stok Menipis, Harga per Satuan, Tanggal
     * Kedaluwarsa, Catatan. Stok awal > 0 otomatis dicatat sebagai
     * movement 'in' (bukan cuma di-set langsung ke current_stock) supaya
     * ada jejak riwayatnya sejak awal.
     */
    private static function importMaterials(string $uploadedPath): void
    {
        $fullPath = Storage::disk('local')->path($uploadedPath);

        try {
            $sheets = Excel::toArray(null, $fullPath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($uploadedPath);

            Notification::make()
                ->title('Gagal membaca file')
                ->body('File tidak bisa dibaca sebagai Excel/CSV yang valid: ' . $e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Storage::disk('local')->delete($uploadedPath);

        $rows = $sheets[0] ?? [];
        array_shift($rows); // baris pertama = header

        $createdCount = 0;
        $codeConflicts = 0;
        $seenCodesInFile = [];
        $problems = [];

        // Nama yang sama (tanpa membedakan huruf besar/kecil) dengan bahan yang sudah ada atau baris sebelumnya dilewati,
        // supaya mengimpor file yang sama dua kali tidak menggandakan bahan.
        $knownNames = RawMaterial::query()->pluck('name')
            ->mapWithKeys(fn ($existing) => [mb_strtolower(trim((string) $existing)) => true])
            ->all();

        foreach ($rows as $index => $row) {
            $line = $index + 2; // baris header sudah dibuang; nomor baris di Excel mulai dari 1

            if (! array_filter($row, fn ($cell) => trim((string) $cell) !== '')) {
                continue;
            }

            $name = isset($row[0]) ? trim((string) $row[0]) : '';
            $unit = isset($row[3]) ? trim((string) $row[3]) : '';

            if ($name === '' || $unit === '') {
                $problems[] = "Baris {$line}: Nama Bahan atau Satuan kosong";
                continue;
            }

            $code = isset($row[1]) ? trim((string) $row[1]) : '';
            $category = isset($row[2]) ? trim((string) $row[2]) : '';
            $initialStock = isset($row[4]) && $row[4] !== '' ? (float) $row[4] : 0.0;
            $reorderPoint = isset($row[5]) && $row[5] !== '' ? (float) $row[5] : null;
            $unitCost = isset($row[6]) && $row[6] !== '' ? (float) $row[6] : null;

            // Angka negatif tidak masuk akal untuk stok awal / ambang / harga (form manual sudah menolaknya) -- baris
            // dilewati, bukan disimpan dengan stok atau harga negatif.
            if ($initialStock < 0 || ($reorderPoint !== null && $reorderPoint < 0) || ($unitCost !== null && $unitCost < 0)) {
                $problems[] = "Baris {$line}: stok/ambang/harga negatif ({$name})";
                continue;
            }

            $nameKey = mb_strtolower($name);
            if (isset($knownNames[$nameKey])) {
                $problems[] = "Baris {$line}: \"{$name}\" sudah terdaftar";
                continue;
            }

            $receivedDate = static::normalizeImportedDate($row[7] ?? null) ?? now()->toDateString();
            $expiryDate = static::normalizeImportedDate($row[8] ?? null);
            $notes = isset($row[9]) ? trim((string) $row[9]) : '';

            // Satuan Beli (kolom 11) dan Isi per Satuan Beli (kolom 12), opsional: mis. beli per "botol" yang berisi 400 "ml" Satuan Dasar.
            // Keduanya harus diisi bersamaan. Kolom ini di ujung kanan supaya file import lama (10 kolom) tetap terbaca.
            $purchaseUnit = isset($row[10]) ? trim((string) $row[10]) : '';
            $factorText = isset($row[11]) ? str_replace(',', '.', trim((string) $row[11])) : '';
            $purchaseFactor = null;

            if ($purchaseUnit !== '' || $factorText !== '') {
                $factor = is_numeric($factorText) ? (float) $factorText : 0.0;

                if ($purchaseUnit === '' || $factor <= 0 || mb_strlen($purchaseUnit) > 20) {
                    $problems[] = "Baris {$line}: Satuan Beli dan Isi per Satuan Beli harus diisi bersamaan (isi berupa angka lebih dari 0, satuan maksimal 20 huruf) ({$name})";
                    continue;
                }

                $purchaseFactor = $factor;
            }

            // Kode barang punya UNIQUE constraint — kalau bentrok (sudah
            // ada di database, atau duplikat dalam file itu sendiri),
            // bahan tetap didaftarkan TANPA kode (bukan gagal total),
            // supaya admin tinggal isi manual belakangan.
            $useCode = null;
            if ($code !== '') {
                if (isset($seenCodesInFile[$code]) || RawMaterial::where('code', $code)->exists()) {
                    $codeConflicts++;
                } else {
                    $seenCodesInFile[$code] = true;
                    $useCode = $code;
                }
            }

            // Bahan dan stok awalnya satu kesatuan: kalau salah satu gagal, tidak ada yang tersimpan setengah-setengah.
            try {
                DB::transaction(function () use ($name, $useCode, $category, $receivedDate, $unit, $reorderPoint, $unitCost, $expiryDate, $notes, $initialStock, $purchaseUnit, $purchaseFactor) {
                    $material = RawMaterial::create([
                        'name' => $name,
                        'code' => $useCode,
                        'category' => static::canonicalCategory($category),
                        'received_date' => $receivedDate,
                        'unit' => $unit,
                        'purchase_unit' => $purchaseUnit !== '' ? $purchaseUnit : null,
                        'purchase_conversion_factor' => $purchaseFactor,
                        'current_stock' => 0,
                        'reorder_point' => $reorderPoint,
                        'unit_cost' => $unitCost,
                        'expiry_date' => $expiryDate,
                        'notes' => $notes ?: null,
                        'created_by' => auth()->id(),
                    ]);

                    if ($initialStock > 0) {
                        $material->recordMovement('in', $initialStock, auth()->id(), 'Stok awal dari import Excel.', $receivedDate, $expiryDate);
                    }
                });
            } catch (\Throwable $e) {
                report($e);
                $problems[] = "Baris {$line}: gagal disimpan ({$name})";
                continue;
            }

            $knownNames[$nameKey] = true;
            $createdCount++;
        }

        $bodyLines = ["{$createdCount} bahan baku berhasil didaftarkan."];
        if ($problems !== []) {
            $bodyLines[] = count($problems) . ' baris dilewati: ' . implode('; ', array_slice($problems, 0, 10)) . (count($problems) > 10 ? '; dan ' . (count($problems) - 10) . ' lainnya.' : '.');
        }
        if ($codeConflicts > 0) $bodyLines[] = "{$codeConflicts} kode barang dilewati (sudah dipakai bahan lain / duplikat dalam file) — bahan tetap didaftarkan tanpa kode.";

        Notification::make()
            ->title('Import selesai')
            ->body(implode(' ', $bodyLines))
            ->color($createdCount > 0 ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    /**
     * Format kolom tanggal di template adalah DD/MM/YYYY (sama dengan
     * tampilan UI) — di-parse eksplisit dengan format itu, BUKAN
     * Carbon::parse()/strtotime() yang ambigu untuk tanggal bertitik dua
     * (mis. "05/06/2027" bisa terbaca 5 Juni atau 6 Mei tergantung
     * locale). Excel bisa juga mengembalikan cell bertipe Date sebagai
     * serial number.
     */
    private static function normalizeImportedDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $date = \DateTime::createFromFormat('d/m/Y', trim((string) $raw));

        return $date instanceof \DateTime ? $date->format('Y-m-d') : null;
    }

    public static function getRelations(): array
    {
        return [
            BatchesRelationManager::class,
            MovementsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRawMaterials::route('/'),
            'create' => Pages\CreateRawMaterial::route('/create'),
            'view'   => Pages\ViewRawMaterial::route('/{record}'),
            'edit'   => Pages\EditRawMaterial::route('/{record}/edit'),
        ];
    }
}