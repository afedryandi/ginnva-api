<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

/**
 * Kelola role staff sendiri lewat Filament — TIDAK perlu lagi minta
 * developer tambah role baru langsung di kode (RolePermissionSeeder).
 *
 * Role yang dibuat di sini otomatis:
 * - Bisa login ke mobile app (guard 'api', selalu — login tidak pernah
 *   dibatasi role) & Filament (lihat User::canAccessStaffArea(), yang
 *   mengizinkan role APA PUN kecuali yang ada di User::NO_PANEL_ROLES —
 *   bukan array allowlist hardcode, jadi role baru otomatis kebagian akses
 *   tanpa perlu ada yang ubah kode PHP).
 * - Aksesnya ke tiap menu diatur lewat "Akses Menu" di form User (per akun),
 *   BUKAN dari role ini — role di sini cuma label/pengelompokan.
 *
 * Role 'super_admin', 'direksi', 'installer', 'partner', 'store_manager',
 * 'spv_finance' & 'installer_leader' SENGAJA tidak bisa dihapus/diedit dari
 * sini (lihat canEdit/canDelete) — ketujuh nama ini di-hardcode sebagai
 * string literal di tempat lain (User::isFullAccess()/NO_PANEL_ROLES,
 * pengecekan wajib-isi-Toko di UserResource::form(), wewenang Penggajian
 * dan keputusan izin/cuti, hak membuat SPK), jadi mengubah namanya akan
 * diam-diam merusak logic itu tanpa ada peringatan apa pun ke admin yang
 * me-rename.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Data Karyawan".
    protected static ?string $navigationGroup = 'Pengaturan Karyawan';

    protected static ?int $navigationSort = 600;

    protected static ?string $navigationLabel = 'Role & Divisi';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Role & Divisi';

    /**
     * Nama role yang dipakai langsung di kode sebagai penentu wewenang. spv_finance (Penggajian, pengajuan pembayaran, keputusan izin/cuti)
     * dan installer_leader (membuat SPK, konfirmasi booking di aplikasi) ditambahkan 2026-10-31: mengganti namanya akan diam-diam
     * menghilangkan wewenang itu.
     */
    public const PROTECTED_ROLES = ['super_admin', 'direksi', 'installer', 'partner', 'store_manager', 'spv_finance', 'installer_leader'];

    /** Teks untuk role selain yang dikenal sistem: hanya label, tanpa wewenang bawaan. */
    public const GENERIC_DESCRIPTION = 'Label jabatan. Akses menu diatur per akun di menu User.';

    /** Apa yang dikaitkan sistem dengan role tertentu (ditulis dari yang dibaca kode). */
    public const DESCRIPTIONS = [
        'super_admin' => 'Akses penuh ke semua menu dan semua toko.',
        'direksi' => 'Akses penuh ke semua menu dan semua toko; memutuskan hal yang butuh persetujuan, mis. pembayaran gaji.',
        'store_manager' => 'Wajib terikat satu toko. Memutuskan izin/cuti dan koreksi absensi tokonya, serta wewenang khusus booking (batal, tidak datang, koreksi tahap).',
        'spv_finance' => 'Menjalankan Penggajian (mengajukan pembayaran ke direksi) dan memutuskan izin/cuti tokonya. Menu lain dicentang per akun di User.',
        'installer_leader' => 'Pemimpin tim installer: boleh membuat SPK dan menjalankan booking di aplikasi.',
        'installer' => 'Wajib terikat satu toko. Login lewat aplikasi saja, tidak masuk panel ini.',
        'partner' => 'Akun mitra referral. Dikelola di menu Partner, login lewat aplikasi saja.',
    ];

    public static function descriptionFor(string $roleName): string
    {
        return self::DESCRIPTIONS[$roleName] ?? self::GENERIC_DESCRIPTION;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * SEBELUMNYA tidak ada — canCreate() bawaan Resource selalu FALSE
     * tanpa Policy, jadi CreateAction ("Buat Role") di ListRoles tidak
     * pernah muncul untuk siapa pun. Ditemukan lewat sapu bersih
     * 2026-09-14 (audit framework, "Otorisasi default-deny").
     */
    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isFullAccess()
            && ! in_array($record->name, self::PROTECTED_ROLES, true);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess()
            && ! in_array($record->name, self::PROTECTED_ROLES, true)
            && $record->users()->count() === 0;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Role')
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('Pakai huruf kecil & underscore, contoh: finance, hrd, warehouse_staff. Nama ini yang muncul di dropdown Role saat bikin akun User. Role baru hanya label jabatan: akses menu tiap orang diatur di menu User, bukan di sini.')
                ->regex('/^[a-z_]+$/')
                // Tanpa pesan custom, rule 'unique' bawaan Laravel tampil
                // dalam Bahasa Inggris ("The name has already been
                // taken.") — APP_LOCALE=id tapi tidak ada lang/id/
                // level aplikasi sama sekali, cuma fallback ke vendor 'en'.
                // Ditemukan dari testing live checklist Role/Divisi.
                ->validationMessages([
                    'regex' => 'Hanya huruf kecil dan underscore, tanpa spasi (mis. "warehouse_staff").',
                    'unique' => 'Nama role ini sudah digunakan, pakai nama lain.',
                ]),

            Forms\Components\Hidden::make('guard_name')
                ->default('web'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Role')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title())
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->state(fn (Role $record) => static::descriptionFor($record->name))
                    ->wrap()
                    ->color(fn (Role $record) => isset(self::DESCRIPTIONS[$record->name]) ? null : 'gray'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Jumlah User')
                    ->counts('users')
                    ->description(fn (Role $record) => ($record->users_count ?? 0) > 0 && ! in_array($record->name, self::PROTECTED_ROLES, true)
                        ? 'Tidak bisa dihapus selama masih dipakai'
                        : null),

                Tables\Columns\IconColumn::make('protected')
                    ->label('Terkunci')
                    ->getStateUsing(fn (Role $record) => in_array($record->name, self::PROTECTED_ROLES, true))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('gray')
                    ->falseColor('success')
                    ->tooltip(fn (Role $record) => in_array($record->name, self::PROTECTED_ROLES, true)
                        ? 'Nama role ini dipakai langsung oleh sistem; mengubah atau menghapusnya akan merusak wewenang terkait.'
                        : null),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
