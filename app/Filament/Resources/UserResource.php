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

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'User';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    protected static ?int $navigationSort = 60;

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
        return auth()->user()?->isFullAccess()
            && auth()->id() !== $record->id;
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
        return [
            'Penjualan' => [
                'CustomerResource' => 'Akun Customer',
                'PartnershipInquiryResource' => 'Pengajuan Kemitraan',
                'ProductInquiryResource' => 'Inquiry Produk',
                'PointTransactionResource' => 'Riwayat Poin Customer',
            ],
            'Operasional' => [
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
            ],
            'Konten' => [
                'CaseStudyResource' => 'Galeri Pemasangan',
                'CustomerGalleryPhotoResource' => 'Galeri Customer',
                'NewsResource' => 'News / Berita',
                'CarouselResource' => 'Banner / Carousel',
                'FeaturedProductResource' => 'Seri Produk (Beranda)',
                'JobOpeningResource' => 'Lowongan Kerja',
                'MaterialResource' => 'Materi Download',
                'MaterialCategoryResource' => 'Kategori Materi',
            ],
            'Master Data' => [
                'FilmProductResource' => 'Produk Film',
                'VehicleResource' => 'Kendaraan',
                'StoreResource' => 'Toko/Dealer',
                'ScrollCodeResource' => 'Kode Gulungan',
                'PriceRuleResource' => 'Koefisien Harga',
            ],
            'Sistem' => [
                'CustomerNotificationResource' => 'Riwayat Notifikasi',
                'SendNotification' => 'Kirim Notifikasi',
            ],
            'Partnership Referral' => [
                'PartnerResource' => 'Partner',
                'VoucherResource' => 'Voucher Promo',
                'RewardResource' => 'Katalog Reward',
                'RewardRedemptionResource' => 'Klaim Reward',
                'PartnerPointTransactionResource' => 'Riwayat Poin Partner',
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
                                ])
                                ->helperText('Contoh: hrd, warehouse_staff, sales_admin.'),
                            Forms\Components\Hidden::make('guard_name')->default('web'),
                        ])
                        ->createOptionUsing(fn (array $data) => \Spatie\Permission\Models\Role::create($data)->getKey())
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