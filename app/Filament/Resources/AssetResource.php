<?php

namespace App\Filament\Resources;

use App\Exports\AssetImportTemplateExport;
use App\Filament\Resources\AssetResource\Pages;
use App\Filament\Resources\AssetResource\RelationManagers\TransfersRelationManager;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Store;
use App\Models\User;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- "Master Data Stok".
    protected static ?string $navigationGroup = 'Master Data Stok';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Aset Tetap';

    protected static ?string $modelLabel = 'Aset Tetap';

    protected static ?string $pluralModelLabel = 'Aset Tetap';

    /**
     * Aset memuat harga beli dan nilai buku, dan punya alur serah terima serta pelepasan, jadi tidak terbuka lewat akses bawaan akun
     * baru (menu_access kosong): hanya akses penuh atau staf yang menunya dicentang eksplisit (audit Aset Tetap, 2026-11-02).
     * Mengubah data dan serah terima butuh izin Tambah/Ubah, melihat harga butuh izin Lihat, menghapus butuh izin Hapus.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /** Boleh melihat harga beli, nilai residu, nilai buku, dan hasil pelepasan: akses penuh atau izin "Lihat" pada menu Aset Tetap. */
    public static function canSeeCost(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
    }

    /**
     * hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
     * default FALSE (tetap ketat spt sebelumnya, isFullAccess()-only).
     */
    public static function canDelete($record): bool
    {
        return static::canDeleteAny();
    }

    /**
     * SEBELUMNYA cuma canDelete() yang di-override di sini — canCreate()/
     * canEdit()/canDeleteAny() masih ikut default Resource (Gate::allows()
     * tanpa AssetPolicy terdaftar, selalu FALSE untuk siapa pun). Tombol
     * "New", "Edit", dan hapus massal (DeleteBulkAction, sudah ada guard
     * ->visible(isFullAccess()) eksplisit) semuanya hilang. Sama bug class
     * dengan InventoryItemResource/RawMaterialResource (audit sebelumnya).
     * canCreate()/canEdit() dibiarkan seluas canViewAny() — EditAction
     * tidak punya ->visible() tambahan apa pun di kode aslinya.
     */
    public static function canCreate(): bool
    {
        return static::canViewAny() && auth()->user()->hasModuleAction(static::class, 'create', false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && auth()->user()->hasModuleAction(static::class, 'update', false);
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || (static::canViewAny() && $user->hasModuleAction(static::class, 'delete', false));
    }

    /**
     * store_manager (admin toko) cuma lihat aset milik tokonya sendiri —
     * pola sama persis dengan TechnicianResource/StoreReviewResource.
     * Aset dengan store_id NULL ("Kantor Pusat / belum ditentukan") jadi
     * TIDAK ikut kelihatan untuk non-full-access — itu memang aset
     * pusat, bukan milik toko mana pun.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user) {
            $query->visibleTo($user);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Aset')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Aset')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. Elektronik, Kendaraan, Mesin, Furnitur')
                        // Saran dari kategori yang sudah ada; ejaan/huruf besar-kecil disamakan dengannya saat disimpan.
                        ->datalist(fn () => static::categoryOptions())
                        ->dehydrateStateUsing(fn (?string $state) => static::canonicalCategory($state)),

                    Forms\Components\TextInput::make('asset_tag')
                        ->label('Kode Aset / QR')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Asset $record) => $record !== null)
                        ->helperText('Kode ini otomatis dibuat sistem dan tidak bisa diubah.'),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(fn (?Asset $record) => static::formStatusOptions($record))
                        // Aturan eksplisit: opsi dari closure tidak otomatis dijadikan aturan validasi, dan status liar (mis. dari form yang dimanipulasi)
                        // sebelumnya langsung menabrak kolom enum di database.
                        ->in(fn (?Asset $record) => array_keys(static::formStatusOptions($record)))
                        // Aset yang sudah dilepas (Lepas Aset) statusnya terkunci.
                        ->disabled(fn (?Asset $record) => $record?->disposed_at !== null)
                        ->dehydrated(fn (?Asset $record) => $record?->disposed_at === null)
                        ->required(fn (?Asset $record) => $record?->disposed_at === null)
                        ->default('aktif')
                        ->helperText('Aset dijual atau dibuang lewat tombol Lepas Aset di daftar (akses penuh) supaya jurnalnya tercatat. Hilang boleh ditandai di sini.'),

                    Forms\Components\Select::make('assigned_to')
                        ->label('Dipegang Oleh')
                        // SEBELUMNYA User::pluck('name', 'id') polos —
                        // menampilkan SEMUA akun (termasuk installer/partner
                        // yang bukan staff internal) sebagai kandidat
                        // pemegang aset. Dibatasi ke akun yang benar-benar
                        // bisa masuk area staff (canAccessStaffArea()).
                        ->options(fn () => User::all()
                            ->filter(fn (User $u) => $u->canAccessStaffArea())
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Belum ditentukan'),

                    Forms\Components\Select::make('store_id')
                        ->label('Lokasi (Toko)')
                        ->options(fn () => Store::pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Kantor Pusat / belum ditentukan')
                        // store_manager cuma boleh assign ke tokonya sendiri —
                        // kalau field-nya cuma disembunyikan (bukan dikunci),
                        // getEloquentQuery() akan langsung "menyembunyikan"
                        // aset itu dari pandangannya begitu disimpan, karena
                        // lolos dari scope tokonya sendiri.
                        ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                        ->dehydrated(fn () => auth()->user()?->isFullAccess() ?? false)
                        ->default(fn () => auth()->user()?->isFullAccess() ? null : auth()->user()?->store_id),

                    Forms\Components\DatePicker::make('received_date')
                        ->label('Tanggal Masuk')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->helperText(fn (?Asset $record) => $record !== null && $record->received_date === null
                            ? 'Data lama belum punya tanggal ini — isi tanggal perkiraan (boleh hari ini kalau tidak tahu pastinya).'
                            : null),

                    Forms\Components\DatePicker::make('purchase_date')
                        ->label('Tanggal Beli')
                        ->native(false)
                        ->displayFormat('d M Y'),

                    Forms\Components\TextInput::make('purchase_cost')
                        ->label('Harga Beli')
                        ->visible(fn () => static::canSeeCost())
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp'),

                    Forms\Components\DatePicker::make('next_maintenance_date')
                        ->label('Jadwal Maintenance Berikutnya')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->helperText('Opsional — isi kalau aset ini butuh servis/kalibrasi berkala. Admin akan diberi tahu otomatis begitu tanggalnya jatuh tempo.'),

                    Forms\Components\TextInput::make('maintenance_interval_days')
                        ->label('Interval Servis (hari)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->helperText('Opsional. Kalau diisi, Catat Servis mengisi jadwal berikutnya otomatis sebesar interval ini.'),

                    Forms\Components\DatePicker::make('last_maintenance_date')
                        ->label('Servis Terakhir')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Asset $record) => $record?->last_maintenance_date !== null)
                        ->helperText('Diisi otomatis oleh "Catat Servis".'),

                    Forms\Components\TextInput::make('useful_life_years')
                        ->label('Umur Ekonomis (tahun)')
                        ->visible(fn () => static::canSeeCost())
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Opsional — untuk hitung estimasi nilai buku saat ini (depresiasi garis lurus). Kosongkan kalau tidak perlu dilacak.'),

                    Forms\Components\TextInput::make('salvage_value')
                        ->label('Nilai Residu')
                        ->visible(fn () => static::canSeeCost())
                        ->numeric()
                        ->minValue(0)
                        // Tidak boleh melebihi Harga Beli yang diisi di form yang sama.
                        ->maxValue(fn (Forms\Get $get) => filled($get('purchase_cost')) ? (float) $get('purchase_cost') : null)
                        ->prefix('Rp')
                        ->helperText('Opsional — perkiraan nilai sisa di akhir umur ekonomis (boleh 0).'),

                    // 2 akun Bagan Akun untuk penyusutan otomatis bulanan
                    // (lihat DepreciationPostingService) — opsi dibatasi
                    // ke akun anak "1200 Aset Tetap" saja, supaya admin
                    // tidak salah pilih akun dari kelompok lain.
                    Forms\Components\Select::make('chart_of_account_id')
                        ->label('Akun Aset (Bagan Akun)')
                        ->options(fn () => \App\Models\ChartOfAccount::whereHas('parent', fn ($q) => $q->where('code', '1200'))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($a) => [$a->id => $a->display_name]))
                        ->searchable()
                        ->visible(fn () => (bool) config('features.finance'))
                        // Dua akun penyusutan harus diisi berpasangan -- satu saja tidak cukup untuk memposting jurnal.
                        ->required(fn (Forms\Get $get) => filled($get('accumulated_depreciation_account_id')))
                        ->helperText('Opsional — isi bersama "Akun Akumulasi Penyusutan" supaya aset ini ikut disusutkan otomatis tiap bulan.'),

                    Forms\Components\Select::make('accumulated_depreciation_account_id')
                        ->label('Akun Akumulasi Penyusutan')
                        ->visible(fn () => (bool) config('features.finance'))
                        ->required(fn (Forms\Get $get) => filled($get('chart_of_account_id')))
                        ->options(fn () => \App\Models\ChartOfAccount::whereHas('parent', fn ($q) => $q->where('code', '1200'))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($a) => [$a->id => $a->display_name]))
                        ->searchable(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Status yang boleh dipilih di form. "Dijual" hanya lewat Lepas Aset (supaya jurnalnya tercatat), jadi baru muncul bila aset
     * memang sudah berstatus itu (data lama). "Hilang" boleh ditandai tanpa jurnal.
     */
    public static function formStatusOptions(?Asset $record): array
    {
        return [
            'aktif'      => 'Aktif Dipakai',
            'diperbaiki' => 'Sedang Diperbaiki',
            'rusak'      => 'Rusak',
            'hilang'     => 'Hilang',
        ] + ($record?->status === 'dijual' ? ['dijual' => 'Dijual'] : []);
    }

    /** Kategori yang sudah dipakai, untuk saran isian. */
    public static function categoryOptions(): array
    {
        return Asset::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category')->all();
    }

    /** Samakan kategori dengan yang sudah ada (tanpa membedakan huruf besar/kecil); kategori baru disimpan apa adanya. */
    public static function canonicalCategory(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Asset::query()->whereRaw('LOWER(category) = ?', [mb_strtolower($value)])->orderBy('id')->value('category') ?? $value;
    }

    /** Ciri satu aset untuk mendeteksi baris import yang sama dengan aset yang sudah terdaftar. */
    private static function importSignature(string $name, ?string $category, ?int $storeId, ?string $purchaseDate, ?float $purchaseCost): string
    {
        return implode('|', [mb_strtolower(trim($name)), mb_strtolower((string) $category), (string) $storeId, (string) $purchaseDate, $purchaseCost === null ? '' : number_format($purchaseCost, 2, '.', '')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('asset_tag')
                    ->label('Kode')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode disalin')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Aset')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'aktif',
                        'warning' => 'diperbaiki',
                        'danger'  => ['rusak', 'hilang'],
                        'gray'    => 'dijual',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'aktif'      => 'Aktif Dipakai',
                        'diperbaiki' => 'Diperbaiki',
                        'rusak'      => 'Rusak',
                        'dijual'     => 'Dijual',
                        'hilang'     => 'Hilang',
                        default      => $state,
                    }),

                Tables\Columns\TextColumn::make('disposed_at')
                    ->label('Tgl Pelepasan')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->description(fn (Asset $record) => static::canSeeCost() && $record->disposed_at && $record->disposal_proceeds !== null && (float) $record->disposal_proceeds > 0
                        ? 'Hasil Rp ' . number_format((float) $record->disposal_proceeds, 0, ',', '.')
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Dipegang Oleh')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Lokasi')
                    ->placeholder('Kantor Pusat')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Masuk')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Tanggal Beli')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('book_value')
                    ->label('Nilai Buku Saat Ini')
                    ->visible(fn () => static::canSeeCost())
                    ->state(fn (Asset $record) => $record->currentBookValue())
                    ->formatStateUsing(fn (?float $state) => $state !== null ? 'Rp ' . number_format($state, 0, ',', '.') : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('last_maintenance_date')
                    ->label('Servis Terakhir')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('next_maintenance_date')
                    ->label('Maintenance Berikutnya')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Asset $record) => $record->next_maintenance_date?->isPast() ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'aktif'      => 'Aktif Dipakai',
                        'diperbaiki' => 'Sedang Diperbaiki',
                        'rusak'      => 'Rusak',
                        'dijual'     => 'Dijual',
                        'hilang'     => 'Hilang',
                    ]),

                Tables\Filters\SelectFilter::make('assigned_to')
                    ->label('Dipegang Oleh')
                    ->options(fn () => User::query()->whereIn('id', Asset::query()->visibleTo(auth()->user())->whereNotNull('assigned_to')->select('assigned_to'))->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Tables\Filters\Filter::make('maintenance_due')
                    ->label('Maintenance Jatuh Tempo')
                    ->query(fn ($query) => $query->whereNull('disposed_at')->whereNotNull('next_maintenance_date')->whereDate('next_maintenance_date', '<=', today())),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => Asset::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray()),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Lokasi')
                    ->options(fn () => Store::pluck('name', 'id')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Download Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->action(fn () => Excel::download(
                        new AssetImportTemplateExport(),
                        'Template-Import-Aset.xlsx'
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
                            ->directory('asset-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai format dari tombol Download Template. Baris pertama dilewati. Dipegang Oleh dan Lokasi dicocokkan ke nama persis; kalau tidak ketemu, dikosongkan (aset tetap dibuat).'),
                    ])
                    ->action(fn (array $data) => static::importAssets($data['file']))
                    ->modalSubmitActionLabel('Import'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('transfer')
                    ->label('Serah Terima')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->button()
                    ->color('primary')
                    ->visible(fn (Asset $record) => static::canEdit($record) && $record->disposed_at === null)
                    ->form([
                        Forms\Components\Select::make('to_user_id')
                            ->label('Diserahkan Ke')
                            ->options(fn () => User::all()
                                ->filter(fn (User $u) => $u->canAccessStaffArea())
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder('Tidak berubah / dilepas (tidak ada pemegang)'),

                        Forms\Components\Select::make('to_store_id')
                            ->label('Pindah Ke Toko')
                            ->options(fn () => Store::pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                            ->placeholder('Tidak berubah'),

                        Forms\Components\Select::make('condition_at_transfer')
                            ->label('Kondisi Fisik Saat Ini')
                            ->options([
                                'baik' => 'Baik',
                                'perlu_perhatian' => 'Perlu Perhatian',
                                'rusak' => 'Rusak',
                            ])
                            ->required(),

                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan / Keterangan Serah Terima')
                            ->required()
                            ->placeholder('Mis. rotasi tugas, pindah toko, penggantian penanggung jawab'),
                    ])
                    ->action(function (Asset $record, array $data) {
                        $user = auth()->user();

                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data, $user) {
                            $locked = Asset::where('id', $record->id)->lockForUpdate()->firstOrFail();

                            $toStoreId = ($user->isFullAccess() && $data['to_store_id']) ? $data['to_store_id'] : $locked->store_id;

                            AssetTransfer::create([
                                'asset_id' => $locked->id,
                                'from_user_id' => $locked->assigned_to,
                                'to_user_id' => $data['to_user_id'] ?? null,
                                'from_store_id' => $locked->store_id,
                                'to_store_id' => $toStoreId,
                                'condition_at_transfer' => $data['condition_at_transfer'],
                                'reason' => $data['reason'],
                                'performed_by' => $user->id,
                            ]);

                            $locked->update([
                                'assigned_to' => $data['to_user_id'] ?? null,
                                'store_id' => $toStoreId,
                            ]);
                        });

                        Notification::make()->title('Serah terima dicatat')->success()->send();
                    }),

                // Pelepasan aset (audit ACCURATE 5, tombol "Dispos"): aset dijual atau hilang/dibuang keluar dari pembukuan dengan
                // satu jurnal (harga perolehan, akumulasi penyusutan, hasil, laba/rugi). Hanya akses penuh; satu aset sekali saja.

                Tables\Actions\ActionGroup::make([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('record_maintenance')
                    ->label('Catat Servis')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('gray')
                    ->visible(fn (Asset $record) => static::canEdit($record) && $record->disposed_at === null)
                    ->modalHeading(fn (Asset $record) => "Catat servis: {$record->name}")
                    ->form(fn (Asset $record) => [
                        Forms\Components\DatePicker::make('date')
                            ->label('Tanggal Servis')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->default(today())
                            ->maxDate(today())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) use ($record) {
                                $set('next_date', filled($state) ? $record->nextMaintenanceFrom(\Illuminate\Support\Carbon::parse($state))?->toDateString() : null);
                            }),

                        Forms\Components\DatePicker::make('next_date')
                            ->label('Jadwal Servis Berikutnya')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->default($record->nextMaintenanceFrom(today())?->toDateString())
                            ->afterOrEqual('date')
                            ->helperText($record->maintenance_interval_days
                                ? "Terisi otomatis dari interval {$record->maintenance_interval_days} hari; boleh diubah."
                                : 'Opsional — kosongkan kalau aset ini tidak punya jadwal servis berikutnya (pengingat berhenti).'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan Servis')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (Asset $record, array $data) {
                        $record->recordMaintenance(
                            \Illuminate\Support\Carbon::parse($data['date']),
                            filled($data['next_date'] ?? null) ? \Illuminate\Support\Carbon::parse($data['next_date']) : null,
                            $data['note'] ?? null,
                            auth()->id(),
                        );

                        Notification::make()->title('Servis dicatat')->success()->send();
                    }),

                Tables\Actions\Action::make('download_qr')
                    ->label('Download QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->action(fn (Asset $record) => static::downloadQrPdf(new Collection([$record]))),

                // Jalan RESMI pindah tangan aset — beda dari sekadar edit
                // field "Dipegang Oleh"/"Lokasi" di form (yang tetap ada,
                // untuk koreksi data biasa): aksi ini WAJIB isi kondisi
                // fisik + alasan saat serah terima, dan tercatat sebagai 1
                // baris utuh di asset_transfers (lihat tab "Riwayat
                // Kepemilikan"), bukan cuma diff before/after generik di
                // activity log.

                Tables\Actions\Action::make('dispose')
                    ->label('Lepas Aset')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->visible(fn (Asset $record) => (auth()->user()?->isFullAccess() ?? false) && $record->disposed_at === null)
                    ->modalHeading(fn (Asset $record) => "Lepas aset: {$record->name}")
                    ->modalDescription('Aset dikeluarkan dari pembukuan dan tidak disusutkan lagi. Tidak bisa dibatalkan dari sini; salah catat dikoreksi lewat jurnal.')
                    ->form(fn (Asset $record) => [
                        Forms\Components\Select::make('type')
                            ->label('Jenis Pelepasan')
                            ->options(\App\Services\AssetDisposalService::TYPES)
                            ->default('dijual')
                            ->required()
                            ->live(),
                        Forms\Components\DatePicker::make('date')
                            ->label('Tanggal Pelepasan')
                            ->native(false)
                            ->default(today())
                            ->maxDate(today())
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('proceeds')
                            ->label('Hasil Penjualan')
                            ->numeric()->minValue(0)->prefix('Rp')->default(0)
                            ->visible(fn (Forms\Get $get) => $get('type') === 'dijual')
                            ->live(onBlur: true),
                        Forms\Components\Select::make('payment_account_id')
                            ->label('Diterima di Rekening')
                            ->options(fn () => \App\Models\PaymentAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn (Forms\Get $get) => config('features.finance') && $get('type') === 'dijual' && (float) ($get('proceeds') ?? 0) > 0)
                            ->required(fn (Forms\Get $get) => config('features.finance') && $get('type') === 'dijual' && (float) ($get('proceeds') ?? 0) > 0),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->maxLength(500),
                        Forms\Components\Placeholder::make('preview')
                            ->label('Dampak ke pembukuan')
                            ->visible(fn () => (bool) config('features.finance'))
                            ->content(function (Forms\Get $get) use ($record) {
                                $date = filled($get('date')) ? \Illuminate\Support\Carbon::parse($get('date')) : today();
                                $proceeds = $get('type') === 'dijual' ? (float) ($get('proceeds') ?? 0) : 0.0;
                                $p = app(\App\Services\AssetDisposalService::class)->preview($record, $date, $proceeds);
                                $rp = fn (float $n) => 'Rp ' . number_format($n, 0, ',', '.');

                                if (! $p['journalable']) {
                                    return implode(' ', $p['warnings']);
                                }

                                $result = $p['gain_loss'] > 0 ? 'Laba pelepasan ' . $rp($p['gain_loss']) : ($p['gain_loss'] < 0 ? 'Rugi pelepasan ' . $rp(abs($p['gain_loss'])) : 'Tanpa laba/rugi');

                                return "Harga perolehan {$rp($p['cost'])}; akumulasi penyusutan {$rp($p['accumulated'])}; nilai buku {$rp($p['book_value'])}. {$result}."
                                    . ($p['warnings'] ? ' ' . implode(' ', $p['warnings']) : '');
                            }),
                    ])
                    ->action(function (Asset $record, array $data) {
                        try {
                            $result = app(\App\Services\AssetDisposalService::class)->dispose($record, $data, auth()->id());
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Aset tidak bisa dilepas')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Aset dilepas')
                            ->body($result['journaled'] ? 'Jurnal pelepasan sudah dicatat.' : 'Status dicatat tanpa jurnal (aset belum punya harga perolehan atau akun).')
                            ->success()
                            ->send();
                    }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('download_qr_bulk')
                        ->label('Download QR (PDF Massal)')
                        ->icon('heroicon-o-qr-code')
                        ->action(fn (Collection $records) => static::downloadQrPdf($records))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * QR berisi KODE POLOS asset_tag (mis. "ASSET-A1B2C3D4"), sama pola
     * dengan InventoryItemResource::downloadQrPdf() — app mobile staff
     * scan langsung query GET /api/staff/assets/{code}.
     */
    private static function downloadQrPdf(Collection $items)
    {
        $rows = SupportCollection::make($items)->map(function (Asset $item) {
            return [
                'name' => $item->name,
                'meta' => $item->category,
                'code' => $item->asset_tag,
                'qr'   => QrCodeService::generateDataUri($item->asset_tag, 260),
            ];
        });

        $pdf = Pdf::loadView('pdf.asset_qr_batch', ['items' => $rows])->setPaper('a4', 'portrait');

        $filename = $items->count() === 1
            ? "QR-Aset-{$items->first()->asset_tag}.pdf"
            : 'QR-Aset-Batch-' . now()->format('Ymd-His') . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }

    /**
     * Baris pertama (header) dilewati. Kolom: Nama Aset, Kategori,
     * Status, Dipegang Oleh (nama user, dicocokkan persis — kalau tidak
     * ketemu dikosongkan, bukan bikin baris gagal), Lokasi (nama toko,
     * sama), Tanggal Beli, Harga Beli, Catatan.
     */
    private static function importAssets(string $uploadedPath): void
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

        $statusOptions = ['aktif', 'diperbaiki', 'rusak', 'dijual', 'hilang'];

        $createdCount = 0;
        $unmatchedAssigneeNames = [];
        $unmatchedStoreNames = [];
        $userCache = [];
        $storeCache = [];
        $problems = [];

        // Baris yang identik dengan aset yang SUDAH terdaftar (nama, kategori, lokasi, tanggal beli, harga beli) dilewati, supaya mengimpor
        // file yang sama dua kali tidak menggandakan seluruh aset (tiap aset mendapat kode QR berbeda). Baris kembar di dalam file yang sama
        // tetap dibuat, karena beberapa unit yang sama persis (mis. kursi) memang wajar.
        $existing = Asset::query()->get(['name', 'category', 'store_id', 'purchase_date', 'purchase_cost'])
            ->mapWithKeys(fn (Asset $a) => [static::importSignature($a->name, $a->category, $a->store_id, $a->purchase_date?->toDateString(), $a->purchase_cost !== null ? (float) $a->purchase_cost : null) => true])
            ->all();

        foreach ($rows as $index => $row) {
            $line = $index + 2; // baris header sudah dibuang; nomor baris di Excel mulai dari 1

            if (! array_filter($row, fn ($cell) => trim((string) $cell) !== '')) {
                continue;
            }

            $name = isset($row[0]) ? trim((string) $row[0]) : '';

            if ($name === '') {
                $problems[] = "Baris {$line}: Nama Aset kosong";
                continue;
            }

            $category = static::canonicalCategory(isset($row[1]) ? trim((string) $row[1]) : '');
            $status = isset($row[2]) ? strtolower(trim((string) $row[2])) : 'aktif';
            $status = in_array($status, $statusOptions, true) ? $status : 'aktif';

            $assigneeName = isset($row[3]) ? trim((string) $row[3]) : '';
            $assignedTo = null;
            if ($assigneeName !== '') {
                if (! array_key_exists($assigneeName, $userCache)) {
                    $userCache[$assigneeName] = User::where('name', $assigneeName)
                        ->get()
                        ->first(fn (User $u) => $u->canAccessStaffArea())
                        ?->id;
                }
                $assignedTo = $userCache[$assigneeName];
                if ($assignedTo === null) $unmatchedAssigneeNames[$assigneeName] = true;
            }

            $storeName = isset($row[4]) ? trim((string) $row[4]) : '';
            $storeId = null;
            if ($storeName !== '') {
                if (! array_key_exists($storeName, $storeCache)) {
                    $storeCache[$storeName] = Store::where('name', $storeName)->first()?->id;
                }
                $storeId = $storeCache[$storeName];
                if ($storeId === null) $unmatchedStoreNames[$storeName] = true;
            }

            $receivedDate = static::normalizeImportedDate($row[5] ?? null) ?? now()->toDateString();
            $purchaseDate = static::normalizeImportedDate($row[6] ?? null);
            $purchaseCost = isset($row[7]) && $row[7] !== '' ? max(0, (float) $row[7]) : null;
            $notes = isset($row[8]) ? trim((string) $row[8]) : '';

            if (isset($existing[static::importSignature($name, $category, $storeId, $purchaseDate, $purchaseCost)])) {
                $problems[] = "Baris {$line}: \"{$name}\" sudah terdaftar";
                continue;
            }

            try {
                DB::transaction(fn () => Asset::create([
                    'asset_tag' => Asset::generateAssetTag(),
                    'name' => $name,
                    'category' => $category,
                    'status' => $status,
                    'assigned_to' => $assignedTo,
                    'store_id' => $storeId,
                    'received_date' => $receivedDate,
                    'purchase_date' => $purchaseDate,
                    'purchase_cost' => $purchaseCost,
                    'notes' => $notes ?: null,
                    'created_by' => auth()->id(),
                ]));
            } catch (\Throwable $e) {
                report($e);
                $problems[] = "Baris {$line}: gagal disimpan ({$name})";
                continue;
            }

            $createdCount++;
        }

        $bodyLines = ["{$createdCount} aset berhasil didaftarkan."];
        if ($problems !== []) {
            $bodyLines[] = count($problems) . ' baris dilewati: ' . implode('; ', array_slice($problems, 0, 10)) . (count($problems) > 10 ? '; dan ' . (count($problems) - 10) . ' lainnya.' : '.');
        }
        if (! empty($unmatchedAssigneeNames)) {
            $bodyLines[] = 'Nama user tidak ditemukan (dikosongkan): ' . implode(', ', array_keys($unmatchedAssigneeNames)) . '.';
        }
        if (! empty($unmatchedStoreNames)) {
            $bodyLines[] = 'Nama toko tidak ditemukan (dikosongkan): ' . implode(', ', array_keys($unmatchedStoreNames)) . '.';
        }

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
            TransfersRelationManager::class,
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'view'   => Pages\ViewAsset::route('/{record}'),
            'edit'   => Pages\EditAsset::route('/{record}/edit'),
        ];
    }
}
