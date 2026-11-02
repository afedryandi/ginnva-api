<?php

namespace App\Filament\Resources;

use App\Exports\ConsumableItemImportTemplateExport;
use App\Filament\Resources\ConsumableItemResource\Pages;
use App\Filament\Resources\ConsumableItemResource\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\ConsumableItemResource\RelationManagers\MovementsRelationManager;
use App\Models\ConsumableItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Barang Habis Pakai (lakban, lap, cutter, isi cutter, dll) — perlengkapan
 * operasional yang terpakai habis, BEDA dari Aset Tetap (individual,
 * biasanya ber-QR, tidak "habis") dan Bahan Baku (khusus material
 * produksi PPF/WF). Strukturnya SENGAJA mirror RawMaterialResource
 * (stok kuantitas, Catat Stok, Sesuaikan Stok/opname) karena kelakuannya
 * memang identik.
 */
class ConsumableItemResource extends Resource
{
    // Tombol "Buat Permintaan Beli" (sama dengan di widget Perlu Perhatian Dashboard Inventaris).
    use \App\Filament\InventoryWidgets\Concerns\OffersRestockRequest;

    protected static ?string $model = ConsumableItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- "Master Data Stok".
    protected static ?string $navigationGroup = 'Master Data Stok';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Barang Habis Pakai';

    protected static ?string $modelLabel = 'Barang Habis Pakai';

    protected static ?string $pluralModelLabel = 'Barang Habis Pakai';

    /**
     * Barang habis pakai memuat stok dan harga modal (dasar HPP), jadi tidak terbuka lewat akses bawaan akun baru (menu_access
     * kosong): hanya akses penuh atau staf yang menunya dicentang eksplisit (audit Barang Habis Pakai, 2026-11-02). Mengubah data dan
     * mencatat stok butuh izin Tambah/Ubah, melihat harga butuh izin Lihat, menghapus butuh izin Hapus. Sama dengan Bahan Baku.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    /** Boleh melihat harga modal: akses penuh atau izin "Lihat" pada menu Barang Habis Pakai. */
    public static function canSeeCost(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
    }

    /**
     * SEBELUMNYA tidak ada override sama sekali di sini dan tidak ada
     * ConsumableItemPolicy terdaftar — canCreate()/canEdit()/canDelete()/
     * canDeleteAny() bawaan Resource selalu FALSE untuk siapa pun tanpa
     * policy (default-deny Laravel). Sama bug class dengan
     * RawMaterialResource/InventoryItemResource/AssetResource (audit
     * sebelumnya) — struktur resource ini memang sengaja mirror
     * RawMaterialResource, jadi fix-nya pun sama persis: canCreate()/
     * canEdit() seluas canViewAny() (EditAction tidak punya ->visible()
     * tambahan di kode aslinya), canDelete()/canDeleteAny() dibatasi
     * isFullAccess() (menyamai guard ->visible() yang SUDAH ada eksplisit
     * di DeleteAction/DeleteBulkAction).
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
            Forms\Components\Section::make('Detail Barang')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Barang')
                        ->required()
                        ->maxLength(255)
                        // Barang baru dengan nama yang sudah terdaftar ditolak (tanpa membedakan huruf besar/kecil), supaya stok satu
                        // barang tidak terpecah di dua baris. Barang lama tidak dicek ulang saat diubah.
                        ->rule(fn (?ConsumableItem $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                            if ($record === null && ConsumableItem::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])->exists()) {
                                $fail('Barang dengan nama ini sudah terdaftar. Catat stok masuk di barang yang ada, atau bedakan namanya (mis. tambahkan merek/ukuran).');
                            }
                        }),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode Barang')
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->placeholder('Kode internal yang sudah ada, mis. dari sistem lama')
                        ->helperText('Opsional — isi kalau barang ini sudah punya kode barang sendiri.'),

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. Lakban, Alat Potong, Kebersihan')
                        // Saran dari kategori yang sudah ada; ejaan/huruf besar-kecil disamakan dengannya saat disimpan.
                        ->datalist(fn () => static::categoryOptions())
                        ->dehydrateStateUsing(fn (?string $state) => static::canonicalCategory($state)),

                    Forms\Components\TextInput::make('unit')
                        ->label('Satuan')
                        ->required(fn (?ConsumableItem $record) => ! static::unitLocked($record))
                        ->maxLength(20)
                        ->placeholder('Mis. pcs, roll, box, lusin')
                        // Angka stok dan riwayat disimpan tanpa satuan; mengganti satuan setelah ada riwayat mengubah arti semua angka lama.
                        ->disabled(fn (?ConsumableItem $record) => static::unitLocked($record))
                        ->dehydrated(fn (?ConsumableItem $record) => ! static::unitLocked($record))
                        ->helperText(fn (?ConsumableItem $record) => static::unitLocked($record)
                            ? 'Terkunci karena barang ini sudah punya riwayat stok — mengganti satuan mengubah arti semua angka lama. Kalau salah, daftarkan barang baru dan kosongkan stok yang lama.'
                            : null),

                    Forms\Components\DatePicker::make('received_date')
                        ->label('Tanggal Masuk')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->helperText(fn (?ConsumableItem $record) => $record !== null && $record->received_date === null
                            ? 'Data lama belum punya tanggal ini — isi tanggal perkiraan (boleh hari ini kalau tidak tahu pastinya).'
                            : null),

                    Forms\Components\TextInput::make('unit_cost')
                        ->label('Harga per Satuan')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->visible(fn () => static::canSeeCost())
                        ->helperText('Opsional — untuk estimasi nilai stok.'),

                    Forms\Components\TextInput::make('reorder_point')
                        ->label('Ambang Stok Menipis')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Opsional — barang ditandai "Stok Menipis" kalau current stock ≤ angka ini.'),

                    Forms\Components\TextInput::make('adjustment_note_threshold')
                        ->label('Selisih Opname Tanpa Catatan')
                        ->numeric()
                        ->minValue(0)
                        ->suffix(fn (?ConsumableItem $record) => $record?->unit)
                        ->helperText('Opsional — kalau selisih hasil hitung fisik dengan stok sistem lebih besar dari angka ini (dalam satuan barang ini), Sesuaikan Stok/Opname wajib diberi catatan alasan. Kosongkan untuk mematikan aturan.'),

                    Forms\Components\Select::make('preferred_supplier_id')
                        ->label('Pemasok Utama')
                        ->relationship('preferredSupplier', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                        ->searchable()
                        ->preload()
                        ->placeholder('Belum ditentukan')
                        ->helperText('Opsional — tampil di peringatan stok menipis dan jadi isian awal Supplier saat permohonan pembelian ditandai terpenuhi.'),

                    // Label switch (Stok Awal <-> Stok Saat Ini) disamakan
                    // dengan RawMaterialResource — sebelumnya di sini
                    // selalu "Stok Saat Ini" walau lagi di form Create.
                    Forms\Components\TextInput::make('current_stock')
                        ->label(fn (?ConsumableItem $record) => $record === null ? 'Stok Awal' : 'Stok Saat Ini')
                        ->numeric()
                        ->default(0)
                        ->disabled(fn (?ConsumableItem $record) => $record !== null)
                        ->dehydrated(fn (?ConsumableItem $record) => $record === null)
                        ->helperText(fn (?ConsumableItem $record) => $record === null
                            ? 'Stok awal saat pertama kali didaftarkan — otomatis tercatat sebagai riwayat "Masuk".'
                            : 'Stok cuma bisa diubah lewat tombol "Catat Stok" di daftar — supaya selalu ada riwayatnya, tidak diedit langsung di sini.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->helperText('Matikan untuk barang yang tidak dipakai lagi: hilang dari pemilih Memo Bahan/Permohonan Pembelian dan dari peringatan, tetapi stok, riwayat, dan laporannya tetap ada. Bisa diaktifkan lagi kapan saja.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Kategori yang sudah dipakai, untuk saran isian. */
    public static function categoryOptions(): array
    {
        return ConsumableItem::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category')->all();
    }

    /** Samakan kategori dengan yang sudah ada (tanpa membedakan huruf besar/kecil); kategori baru disimpan apa adanya. */
    public static function canonicalCategory(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return ConsumableItem::query()->whereRaw('LOWER(category) = ?', [mb_strtolower($value)])->orderBy('id')->value('category') ?? $value;
    }

    /** Satuan terkunci begitu barang punya riwayat stok. */
    private static function unitLocked(?ConsumableItem $record): bool
    {
        return $record !== null && $record->movements()->exists();
    }

    /** Permohonan pembelian yang sudah disetujui dan masih menunggu barangnya untuk barang ini (dari toko mana pun). */
    public static function approvedPurchase(ConsumableItem $record): ?\App\Models\PurchaseRequest
    {
        return \App\Models\PurchaseRequest::withoutGlobalScopes()
            ->where('item_type', 'consumable_item')
            ->where('item_id', $record->getKey())
            ->where('status', 'approved')
            ->orderBy('id')
            ->first();
    }

    public static function table(Table $table): Table
    {
        return $table
            // Tanggal pergerakan terakhir diambil sebagai subquery (satu query untuk seluruh halaman).
            ->modifyQueryUsing(fn ($query) => $query->with('preferredSupplier:id,name')->withMax('movements as last_movement_at', 'created_at'))
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
                    ->label('Nama Barang')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Masuk')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->formatStateUsing(fn (ConsumableItem $record) => number_format((float) $record->current_stock, 2) . ' ' . $record->unit)
                    ->badge()
                    ->color(fn (ConsumableItem $record) => $record->isLowStock() ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->label('Ambang Menipis')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, ConsumableItem $record) => $state !== null ? number_format((float) $state, 2) . ' ' . $record->unit : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('preferredSupplier.name')
                    ->label('Pemasok Utama')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->visible(fn () => static::canSeeCost())
                    ->money('IDR')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // Nilai = stok x harga terakhir (barang habis pakai tidak punya batch); hanya bagi yang boleh melihat harga.
                Tables\Columns\TextColumn::make('stock_value')
                    ->label('Nilai Stok')
                    ->getStateUsing(fn (ConsumableItem $record) => $record->unit_cost !== null ? round((float) $record->current_stock * (float) $record->unit_cost, 2) : null)
                    ->money('IDR')
                    ->placeholder('Harga belum diisi')
                    ->visible(fn () => static::canSeeCost())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_movement_at')
                    ->label('Terakhir Bergerak')
                    ->date('d M Y')
                    ->placeholder('Belum pernah')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Bawaan hanya barang aktif; pilih "Semua" atau "Nonaktif" untuk melihat barang yang sudah tidak dipakai.
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->default(true)
                    ->queries(
                        true: fn ($query) => $query->where('consumable_items.is_active', true),
                        false: fn ($query) => $query->where('consumable_items.is_active', false),
                        blank: fn ($query) => $query,
                    ),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => ConsumableItem::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray()),

                Tables\Filters\Filter::make('low_stock')
                    ->label('Stok Menipis')
                    ->query(fn ($query) => $query->whereNotNull('reorder_point')
                        ->whereColumn('current_stock', '<=', 'reorder_point')),

                Tables\Filters\Filter::make('dead_stock')
                    ->label('Tidak Bergerak (' . ConsumableItem::DEAD_STOCK_DAYS . '+ hari)')
                    // Menurut riwayat pergerakan, bukan updated_at (lihat ConsumableItem::scopeDeadStock()).
                    ->query(fn ($query) => $query->deadStock()),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Download Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->action(fn () => Excel::download(
                        new ConsumableItemImportTemplateExport(),
                        'Template-Import-Barang-Habis-Pakai.xlsx'
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
                            ->directory('consumable-item-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai format dari tombol "Download Template". Baris pertama (header) otomatis dilewati. Stok Awal otomatis dicatat sebagai riwayat "Masuk" supaya tetap ada jejaknya.'),
                    ])
                    ->action(fn (array $data) => static::importItems($data['file']))
                    ->modalSubmitActionLabel('Import'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('record_movement')
                    ->visible(fn (ConsumableItem $record) => static::canEdit($record))
                    ->label('Catat Stok')
                    ->icon('heroicon-o-arrow-path')
                    ->button()
                    ->color('primary')
                    ->form([
                        // Ada permohonan pembelian disetujui untuk barang ini: arahkan ke jalur yang menjaga stok dan permohonan tetap sejalan.
                        Forms\Components\Placeholder::make('open_purchase')
                            ->hiddenLabel()
                            ->content(fn (ConsumableItem $record) => ($purchase = static::approvedPurchase($record))
                                ? "Ada permohonan pembelian disetujui untuk barang ini ({$purchase->request_number}, {$purchase->quantity} {$purchase->unit}). Kalau ini kiriman dari permohonan itu, catat penerimaannya lewat Permohonan Pembelian > Tandai Terpenuhi (stok ikut bertambah) supaya tidak tercatat dua kali."
                                : '')
                            ->visible(fn (ConsumableItem $record) => static::approvedPurchase($record) !== null),

                        Forms\Components\Select::make('type')
                            ->label('Jenis')
                            ->options(['in' => 'Masuk', 'out' => 'Keluar'])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix(fn (ConsumableItem $record) => $record->unit),

                        // Disalin ke riwayat movement (bukan cuma menimpa
                        // unit_cost di baris utama) supaya valuasi historis
                        // bisa direkonstruksi kalau harga beli berubah antar
                        // pembelian — lihat ConsumableItem::recordMovement().
                        Forms\Components\TextInput::make('unit_cost')
                            ->label('Harga per Satuan')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->default(fn (ConsumableItem $record) => $record->unit_cost)
                            ->helperText('Opsional — kosongkan untuk pakai harga terakhir tersimpan.')
                            ->visible(fn (Forms\Get $get) => static::canSeeCost() && $get('type') === 'in'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('Mis. dipakai untuk booking apa, alasan keluar'),
                    ])
                    ->action(function (ConsumableItem $record, array $data) {
                        try {
                            $record->recordMovement(
                                $data['type'],
                                (float) $data['quantity'],
                                auth()->id(),
                                $data['note'] ?? null,
                                isset($data['unit_cost']) && $data['unit_cost'] !== '' ? (float) $data['unit_cost'] : null,
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Tidak bisa mencatat stok')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Stok dicatat')->success()->send();
                    }),

                // Hanya untuk barang yang stoknya menipis, belum punya permintaan beli berjalan, dan bagi yang boleh membuat Permohonan Pembelian.
                static::restockAction(),

                Tables\Actions\ActionGroup::make([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('adjust_stock')
                    ->visible(fn (ConsumableItem $record) => static::canEdit($record))
                    ->label('Sesuaikan Stok')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('actual_quantity')
                            ->label('Hasil Hitung Fisik Sebenarnya')
                            ->live(onBlur: true)
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->suffix(fn (ConsumableItem $record) => $record->unit)
                            ->helperText(fn (ConsumableItem $record) => 'Stok di sistem saat ini: ' . number_format((float) $record->current_stock, 2) . ' ' . $record->unit . '. Isi jumlah hasil hitung fisik yang SEBENARNYA — selisihnya dihitung otomatis.'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->required(fn (ConsumableItem $record, Forms\Get $get) => is_numeric($get('actual_quantity')) && $record->adjustmentNeedsNote((float) $get('actual_quantity')))
                            ->helperText(fn (ConsumableItem $record) => $record->adjustment_note_threshold !== null
                                ? 'Wajib diisi bila selisih lebih dari ' . rtrim(rtrim(number_format((float) $record->adjustment_note_threshold, 2, ',', '.'), '0'), ',') . ' ' . $record->unit . '.'
                                : null)
                            ->placeholder('Mis. hasil stock opname bulanan, alasan selisih'),
                    ])
                    ->action(function (ConsumableItem $record, array $data) {
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

                // "Stok Terbuang" (write-off) — alasan terstruktur +
                // jurnal kerugian otomatis (6520). Lihat StockWriteOffService.
                Tables\Actions\Action::make('write_off')
                    ->visible(fn (ConsumableItem $record) => static::canEdit($record))
                    ->label('Catat Stok Terbuang')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('Catat Stok Terbuang')
                    ->form([
                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah Terbuang')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(fn (ConsumableItem $record) => (float) $record->current_stock)
                            ->suffix(fn (ConsumableItem $record) => $record->unit)
                            ->helperText(fn (ConsumableItem $record) => 'Stok saat ini: ' . number_format((float) $record->current_stock, 2) . ' ' . $record->unit
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
                            ->native(false),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->rows(2)
                            ->maxLength(255),
                    ])
                    ->action(function (ConsumableItem $record, array $data) {
                        try {
                            $wo = app(\App\Services\StockWriteOffService::class)->record(
                                $record,
                                'consumable_item',
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
                        ->action(fn (\Illuminate\Support\Collection $records) => \App\Support\StockScanCode::labelsPdf($records->values(), 'Label Barang Habis Pakai')),
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
                                if (\App\Support\StockMasterDeletion::blocker($record, 'Barang') === null) {
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
     * Baris pertama (header) dilewati. Kolom: Nama Barang, Kode Barang,
     * Kategori, Satuan, Stok Awal, Ambang Stok Menipis, Harga per Satuan,
     * Catatan.
     */
    private static function importItems(string $uploadedPath): void
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

        // Nama yang sama (tanpa membedakan huruf besar/kecil) dengan barang yang sudah ada atau baris sebelumnya dilewati,
        // supaya mengimpor file yang sama dua kali tidak menggandakan barang.
        $knownNames = ConsumableItem::query()->pluck('name')
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
                $problems[] = "Baris {$line}: Nama Barang atau Satuan kosong";
                continue;
            }

            $code = isset($row[1]) ? trim((string) $row[1]) : '';
            $category = isset($row[2]) ? trim((string) $row[2]) : '';
            $initialStock = isset($row[4]) && $row[4] !== '' ? (float) $row[4] : 0.0;
            $reorderPoint = isset($row[5]) && $row[5] !== '' ? (float) $row[5] : null;
            $unitCost = isset($row[6]) && $row[6] !== '' ? (float) $row[6] : null;
            $receivedDate = static::normalizeImportedDate($row[7] ?? null) ?? now()->toDateString();
            $notes = isset($row[8]) ? trim((string) $row[8]) : '';

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

            $useCode = null;
            if ($code !== '') {
                if (isset($seenCodesInFile[$code]) || ConsumableItem::where('code', $code)->exists()) {
                    $codeConflicts++;
                } else {
                    $seenCodesInFile[$code] = true;
                    $useCode = $code;
                }
            }

            // Barang dan stok awalnya satu kesatuan: kalau salah satu gagal, tidak ada yang tersimpan setengah-setengah.
            try {
                DB::transaction(function () use ($name, $useCode, $category, $receivedDate, $unit, $reorderPoint, $unitCost, $notes, $initialStock) {
                    $item = ConsumableItem::create([
                        'name' => $name,
                        'code' => $useCode,
                        'category' => static::canonicalCategory($category),
                        'received_date' => $receivedDate,
                        'unit' => $unit,
                        'current_stock' => 0,
                        'reorder_point' => $reorderPoint,
                        'unit_cost' => $unitCost,
                        'notes' => $notes ?: null,
                        'created_by' => auth()->id(),
                    ]);

                    if ($initialStock > 0) {
                        $item->recordMovement('in', $initialStock, auth()->id(), 'Stok awal dari import Excel.');
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

        $bodyLines = ["{$createdCount} barang berhasil didaftarkan."];
        if ($problems !== []) {
            $bodyLines[] = count($problems) . ' baris dilewati: ' . implode('; ', array_slice($problems, 0, 10)) . (count($problems) > 10 ? '; dan ' . (count($problems) - 10) . ' lainnya.' : '.');
        }
        if ($codeConflicts > 0) $bodyLines[] = "{$codeConflicts} kode barang dilewati (sudah dipakai barang lain / duplikat dalam file) — barang tetap didaftarkan tanpa kode.";

        Notification::make()
            ->title('Import selesai')
            ->body(implode(' ', $bodyLines))
            ->color($createdCount > 0 ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    /**
     * Format kolom tanggal di template adalah DD/MM/YYYY — lihat catatan
     * lengkap di RawMaterialResource::normalizeImportedDate().
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
            MovementsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListConsumableItems::route('/'),
            'create' => Pages\CreateConsumableItem::route('/create'),
            'view'   => Pages\ViewConsumableItem::route('/{record}'),
            'edit'   => Pages\EditConsumableItem::route('/{record}/edit'),
        ];
    }
}