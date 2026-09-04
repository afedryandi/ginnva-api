<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Store;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $navigationLabel = 'User';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    protected static ?int $navigationSort = 10;

    /**
     * Resource ini HANYA boleh diakses super_admin. Tidak pakai Policy
     * terpisah karena scope-nya simpel (binary: super_admin atau tidak
     * sama sekali) — beda dengan Warranty/Quotation yang perlu scope
     * per-store yang lebih kompleks.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canDelete($record): bool
    {
        // User tidak boleh hapus akun miliknya sendiri lewat panel ini,
        // supaya tidak ada super_admin yang tidak sengaja terkunci keluar.
        // Hapus PERMANEN juga ditutup begitu akun sudah punya riwayat HR
        // (absensi/cuti/gaji/SP/kontrak) — semua tabel itu cascadeOnDelete
        // ke users.id, jadi hard delete di titik ini akan memusnahkan
        // riwayat payroll/absensi yang wajib tetap ada untuk kebutuhan
        // pajak/BPJS/audit. Gunakan aksi "Nonaktifkan" di tabel, bukan
        // hapus, begitu karyawan sudah pernah tercatat.
        return auth()->user()?->isFullAccess()
            && auth()->id() !== $record->id
            && ! $record->hasHrHistory();
    }

    /**
     * Semua Resource yang BISA diakses staff/role divisi (via
     * canViewAny() atau Policy::viewAny() masing-masing) — dikelompokkan
     * per navigationGroup untuk ditampilkan di CheckboxList "Akses Menu".
     * Resource yang selalu super_admin/direksi-only (RoleResource, User
     * ini sendiri, ActivityResource) SENGAJA tidak masuk sini — staff
     * biasa memang tidak pernah bisa mengaksesnya apapun isi
     * menu_access-nya.
     */
    private static function menuAccessOptions(): array
    {
        // Grup di sini DIRAPIKAN supaya sama persis dengan $navigationGroup
        // resource masing-masing (lihat AdminPanelProvider::navigationGroups())
        // — SEBELUMNYA masih pakai nama grup lama (Penjualan, Konten,
        // Partnership Referral) yang sudah tidak dipakai lagi di sidebar,
        // jadi form "Akses Menu" ini menampilkan section yang tidak nyambung
        // dengan struktur menu yang benar-benar staff lihat. Key 'Karyawan'
        // di sini SENGAJA cuma isi Absensi/Izin/Surat Peringatan/Perpanjang
        // Kontrak (User & Role resource di grup navigasi yang sama TETAP
        // super_admin-only lewat canViewAny() masing-masing, tidak pernah
        // muncul di sini apapun menu_access-nya).
        return [
            'Booking' => [
                'QuotationResource' => 'Quotation (Lead)',
                'BookingResource' => 'Booking Instalasi',
                'BlockedDateResource' => 'Tanggal Tidak Tersedia',
                'TechnicianResource' => 'Teknisi',
                'WarrantyResource' => 'Garansi',
                'StoreReviewResource' => 'Review Toko',
            ],
            'Inventaris' => [
                'InventoryDashboard' => 'Dashboard Inventaris',
                'InventoryItemResource' => 'Produk PPF/WF',
                'InventoryMovementResource' => 'Riwayat Keluar/Masuk',
                'RawMaterialResource' => 'Bahan Baku',
                'RawMaterialMovementResource' => 'Riwayat Bahan Baku',
                'AssetResource' => 'Aset Tetap',
                'ConsumableItemResource' => 'Barang Habis Pakai',
                'ConsumableItemMovementResource' => 'Riwayat Barang Habis Pakai',
                'MaterialMemoResource' => 'Memo Pengambilan/Pengembalian',
                'PurchaseRequestResource' => 'Permohonan Pembelian',
            ],
            'Karyawan' => [
                'AttendanceResource' => 'Absensi Karyawan',
                'LeaveRequestResource' => 'Izin & Cuti',
                'WarningLetterResource' => 'Surat Peringatan',
                'ContractExtensionResource' => 'Perpanjang Kontrak',
                // PayrollResource SENGAJA tidak dimasukkan di sini — lihat
                // komentar di PayrollResource::canViewAny(), selalu
                // isFullAccess-only, tidak pernah lewat menu_access.
            ],
            'Marketing/Konten' => [
                'CustomerResource' => 'Akun Customer',
                'PointTransactionResource' => 'Riwayat Poin Customer',
                'PartnershipInquiryResource' => 'Pengajuan Kemitraan',
                'ProductInquiryResource' => 'Inquiry Produk',
                'CaseStudyResource' => 'Galeri Pemasangan',
                'CustomerGalleryPhotoResource' => 'Galeri Customer',
                'NewsResource' => 'News / Berita',
                'CarouselResource' => 'Banner / Carousel',
                'FeaturedProductResource' => 'Seri Produk (Beranda)',
                'JobOpeningResource' => 'Lowongan Kerja',
                'MaterialResource' => 'Materi Download',
                'MaterialCategoryResource' => 'Kategori Materi',
                'PartnerResource' => 'Partner',
                'VoucherResource' => 'Voucher Promo',
                'RewardResource' => 'Katalog Reward',
                'RewardRedemptionResource' => 'Klaim Reward',
                'PartnerPointTransactionResource' => 'Riwayat Poin Partner',
            ],
            'Master Data' => [
                'FilmProductResource' => 'Produk Film',
                'VehicleResource' => 'Kendaraan',
                'StoreResource' => 'Toko/Dealer',
                'ScrollCodeResource' => 'Kode Gulungan',
                // PriceRuleResource SENGAJA tidak dimasukkan di sini --
                // resource-nya sendiri hidden total dari sidebar
                // (shouldRegisterNavigation() false, lihat komentarnya:
                // kalkulasi harga belum diimplementasikan di quotation
                // flow). Beda dari ScrollCodeResource yang juga hidden
                // tapi tetap punya jalur akses sah (drill-down dari menu
                // Barang), grant "Koefisien Harga" di sini tidak
                // mengarah ke mana pun di UI -- staff yang di-grant harus
                // tahu & ketik URL manual, opsi mati yang cuma
                // membingungkan admin saat assign role. Kembalikan baris
                // ini kalau fitur harga otomatis sudah siap & resource-nya
                // dibuka lagi ke navigasi. Ditemukan & diperbaiki
                // 2026-08-29, audit modul Koefisien Harga.
            ],
            'Sistem' => [
                'CustomerNotificationResource' => 'Riwayat Notifikasi',
                'PartnerNotificationResource' => 'Riwayat Notifikasi Partner',
                'SendNotification' => 'Kirim Notifikasi',
            ],
        ];
    }

    /**
     * "Akses Menu" cuma relevan untuk role staff biasa (store_manager,
     * role divisi mana pun) — super_admin/direksi selalu full access,
     * installer/partner tidak login ke Filament sama sekali. Dicek dengan
     * "role apa pun SELAIN full-access & NO_PANEL_ROLES" supaya role baru
     * yang dibuat lewat RoleResource otomatis kebagian section ini juga.
     */
    private static function isRestrictableStaffSelected(Forms\Get $get): bool
    {
        $roleIds = $get('roles') ?? [];
        if (empty($roleIds)) {
            return false;
        }

        $excluded = array_merge(['super_admin', 'direksi'], \App\Models\User::NO_PANEL_ROLES);

        return \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
            ->whereNotIn('name', $excluded)
            ->exists();
    }

    /**
     * CheckboxList tidak bisa dipakai berkali-kali dengan nama field yang
     * sama (5 grup akan saling menimpa state satu sama lain kalau semua
     * dinamai 'menu_access') — jadi tiap grup punya field SEMENTARA
     * sendiri (mis. 'menu_access_penjualan'), lalu digabung jadi satu
     * kolom `menu_access` beneran lewat mergeMenuAccessFields() di
     * CreateUser/EditUser, dan dipecah balik lewat splitMenuAccessIntoFields()
     * saat form dibuka untuk edit.
     */
    public static function menuAccessFieldKey(string $group): string
    {
        return 'menu_access_' . Str::slug($group, '_');
    }

    public static function mergeMenuAccessFields(array $data): array
    {
        $merged = [];
        foreach (array_keys(self::menuAccessOptions()) as $group) {
            $key = self::menuAccessFieldKey($group);
            $merged = array_merge($merged, $data[$key] ?? []);
            unset($data[$key]);
        }

        // Tidak ada yang dicentang sama sekali = NULL (akses penuh) —
        // sesuai teks bantuan di form ("kosongkan supaya akses penuh").
        // Cuma kalau ADA yang dicentang, baru dibatasi ke daftar itu saja.
        $data['menu_access'] = empty($merged) ? null : $merged;

        return $data;
    }

    public static function splitMenuAccessIntoFields(array $data): array
    {
        $selected = $data['menu_access'] ?? [];

        foreach (self::menuAccessOptions() as $group => $options) {
            $key = self::menuAccessFieldKey($group);
            $data[$key] = array_values(array_intersect($selected, array_keys($options)));
        }

        return $data;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Akun')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone')
                        ->label('No. HP')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\DatePicker::make('join_date')
                        ->label('Tanggal Mulai Kerja')
                        ->maxDate(today()),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Akun Aktif')
                        ->default(true)
                        ->helperText('Matikan untuk mencabut akses login (panel & mobile app) tanpa menghapus akun & riwayatnya — dipakai saat karyawan resign/cuti panjang. Beda dari Hapus: data absensi/gaji/SP tetap tersimpan utuh.')
                        // Karyawan tidak boleh menonaktifkan akunnya
                        // sendiri lewat form ini — kalau butuh, super
                        // admin lain yang harus melakukannya.
                        ->disabled(fn (?User $record) => $record && auth()->id() === $record->id)
                        ->dehydrated(),

                    Forms\Components\TextInput::make('base_salary')
                        ->label('Gaji Pokok')
                        ->helperText('Dipakai sebagai dasar hitung Penggajian bulanan.')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0),

                    Forms\Components\DatePicker::make('contract_end_date')
                        ->label('Tanggal Berakhir Kontrak')
                        ->helperText('Isi di sini untuk kontrak PERTAMA kali. Perpanjangan berikutnya dicatat lewat menu "Perpanjang Kontrak" supaya riwayatnya tersimpan, bukan diedit langsung di sini.'),

                    Forms\Components\Select::make('roles')
                        ->label('Role')
                        ->relationship('roles', 'name')
                        // "partner" sengaja tidak ditawarkan di sini — akun
                        // mitra referral dibuat & dikelola lewat menu Partner
                        // (PartnerResource), bukan dari menu User internal ini.
                        ->options(fn () => \Spatie\Permission\Models\Role::where('name', '!=', 'partner')->pluck('name', 'id'))
                        ->multiple()
                        ->preload()
                        // Bikin role baru langsung dari sini kalau divisinya
                        // belum ada di daftar — tidak perlu ke halaman
                        // Role/Divisi terpisah dulu. Guard 'web' otomatis
                        // ke-set sama seperti role lain (lihat RoleResource).
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->label('Nama Role Baru')
                                ->required()
                                ->unique(table: 'roles', column: 'name')
                                ->regex('/^[a-z_]+$/')
                                ->validationMessages([
                                    'regex' => 'Huruf kecil & underscore saja, contoh: warehouse_staff.',
                                    'unique' => 'Nama role ini sudah digunakan, pakai nama lain.',
                                ])
                                ->helperText('Contoh: hrd, warehouse_staff, sales_admin.'),
                            Forms\Components\Hidden::make('guard_name')->default('web'),
                        ])
                        // Jalur cepat ini TIDAK lewat halaman CreateRole sama
                        // sekali (Role::create() langsung), jadi
                        // CreateRole::afterCreate() yang mencatat Activity
                        // Log tidak pernah terpicu untuk role yang dibuat
                        // dari sini — dicatat manual di closure ini supaya
                        // konsisten dengan role yang dibuat lewat menu
                        // Role/Divisi. Ditemukan dari testing live checklist
                        // Role/Divisi.
                        ->createOptionUsing(function (array $data) {
                            $role = \Spatie\Permission\Models\Role::create($data);

                            activity('role')
                                ->causedBy(auth()->user())
                                ->performedOn($role)
                                ->log("Role \"{$role->name}\" dibuat");

                            return $role->getKey();
                        })
                        ->live()
                        ->required()
                        ->helperText('super_admin/direksi: akses penuh. store_manager & installer: wajib pilih Toko di bawah (installer tidak bisa login ke panel ini, hanya via mobile app).'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko (khusus role yang terikat 1 toko)')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->required(function (Forms\Get $get): bool {
                            $roleIds = $get('roles') ?? [];
                            if (empty($roleIds)) return false;
                            return \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
                                ->whereIn('name', ['installer', 'store_manager'])
                                ->exists();
                        })
                        // Sisi "wajib diisi" untuk installer/store_manager
                        // sudah ada di atas — ini sisi sebaliknya yang
                        // sebelumnya tidak ada sama sekali: mencegah akun
                        // role 'partner' (company-wide, bukan staff toko)
                        // tersimpan dengan store_id terisi, yang bisa bikin
                        // logic mobile API yang mengandalkan kombinasi
                        // role+store_id jadi rancu.
                        ->rules([
                            fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if (! $value) return;
                                $roleIds = $get('roles') ?? [];
                                if (empty($roleIds)) return;
                                $isPartner = \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
                                    ->where('name', 'partner')
                                    ->exists();
                                if ($isPartner) {
                                    $fail('Role Partner tidak boleh terikat ke satu Toko — partner adalah akun bisnis company-wide, bukan staff toko. Kosongkan field ini.');
                                }
                            },
                        ])
                        ->helperText('Wajib diisi kalau role-nya installer atau store_manager — tanpa Toko, akun ini tidak akan bisa akses booking/chat toko manapun. Kosongkan untuk super_admin/direksi/partner/role company-wide lain.'),

                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(8)
                        ->same('passwordConfirmation')
                        // Default Laravel untuk rule 'same' cuma separuh
                        // diterjemahkan Filament ("The password field must
                        // match konfirmasi Password" — dua bahasa
                        // tercampur), pesan sendiri di sini biar konsisten
                        // Indonesia semua. Ditemukan saat testing live
                        // checklist User, dilaporkan pengguna.
                        ->validationMessages([
                            'same' => 'Password dan Konfirmasi Password harus sama persis.',
                        ])
                        ->live(debounce: 500)
                        ->helperText(fn (string $context) => $context === 'edit'
                            ? 'Kosongkan kalau tidak mau mengubah password.'
                            : 'Minimal 8 karakter.'),

                    Forms\Components\TextInput::make('passwordConfirmation')
                        ->label('Konfirmasi Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(false)
                        ->helperText('Ulangi password yang sama persis.'),
                ]),

            Forms\Components\Section::make('Akses Menu')
                ->description('Khusus role staff/divisi (bukan Direksi). Kosongkan semua (jangan centang apa pun) supaya user otomatis dapat akses penuh ke semua menu di bawah — cara paling aman kalau belum yakin. Centang menu tertentu untuk MEMBATASI hanya ke menu itu saja.')
                ->visible(fn (Forms\Get $get) => self::isRestrictableStaffSelected($get))
                ->schema(
                    collect(self::menuAccessOptions())->map(
                        fn (array $options, string $group) => Forms\Components\CheckboxList::make(self::menuAccessFieldKey($group))
                            ->label($group)
                            ->options($options)
                            ->bulkToggleable()
                            ->columns(2)
                    )->values()->all()
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin', 'direksi' => 'danger',
                        'store_manager' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'name'),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id')),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Akun')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Ganti utama untuk "keluarkan karyawan dari sistem" —
                // toggle is_active, TIDAK menyentuh baris/relasi apa pun,
                // jadi aman dipakai kapan saja tanpa risiko kehilangan
                // riwayat (beda dari Hapus di bawah).
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record) => $record->is_active
                        ? "Akun \"{$record->name}\" tidak akan bisa login (panel & mobile app) sampai diaktifkan lagi. Semua riwayat absensi/cuti/gaji/SP tetap tersimpan."
                        : "Akun \"{$record->name}\" akan bisa login kembali.")
                    ->visible(fn (User $record) => auth()->id() !== $record->id)
                    ->action(function (User $record) {
                        $record->update(['is_active' => ! $record->is_active]);

                        \Filament\Notifications\Notification::make()
                            ->title($record->is_active ? 'Akun diaktifkan' : 'Akun dinonaktifkan')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->modalDescription(fn (User $record) => $record->hasHrHistory()
                        ? 'Akun ini sudah punya riwayat HR (absensi/cuti/gaji/SP/kontrak) — tidak bisa dihapus permanen. Gunakan "Nonaktifkan" untuk mencabut akses tanpa kehilangan riwayat.'
                        : 'Yakin hapus akun ini? Tindakan ini tidak bisa dibatalkan.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Bulk hapus custom: setiap baris dicek hasHrHistory()
                    // & error FK per-baris ditangkap satu-satu (pola sama
                    // dengan VehicleResource/FilmProductResource) — bukan
                    // DeleteBulkAction polos yang bisa cascade riwayat HR
                    // tanpa peringatan atau berhenti total kalau 1 baris
                    // gagal.
                    Tables\Actions\BulkAction::make('delete')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Akun dengan riwayat HR (absensi/cuti/gaji/SP/kontrak) akan DILEWATI, bukan dihapus — nonaktifkan akun itu satu-satu lewat aksi "Nonaktifkan".')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $deleted = 0;
                            $blocked = 0;

                            foreach ($records as $record) {
                                if (auth()->id() === $record->id || $record->hasHrHistory()) {
                                    $blocked++;
                                    continue;
                                }

                                try {
                                    $record->delete();
                                    $deleted++;
                                } catch (\Illuminate\Database\QueryException $e) {
                                    $blocked++;
                                }
                            }

                            \Filament\Notifications\Notification::make()
                                ->title("{$deleted} akun dihapus" . ($blocked > 0 ? ", {$blocked} dilewati (punya riwayat HR/terkunci)" : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getEloquentQuery(): Builder
    {
        // User dengan role "partner" (mitra referral, akun dibuat massal
        // lewat form GIIAS/signup) SENGAJA disembunyikan dari sini — dulu
        // mereka bercampur dengan user internal (staff/direksi) di satu
        // list yang sama, padahal jumlahnya jauh lebih banyak dan profil
        // lengkapnya (nama bisnis, referral code, poin) sudah ada
        // tersendiri di menu Partner (PartnerResource). Menu "User" ini
        // sekarang khusus akun internal perusahaan.
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'partner'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}