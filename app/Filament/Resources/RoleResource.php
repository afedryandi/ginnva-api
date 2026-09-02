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
 * Role 'super_admin', 'direksi', 'installer', 'partner' & 'store_manager'
 * SENGAJA tidak bisa dihapus/diedit dari sini (lihat canEdit/canDelete) —
 * kelima nama ini di-hardcode sebagai string literal di tempat lain
 * (User::isFullAccess()/NO_PANEL_ROLES, & pengecekan wajib-isi-Toko di
 * UserResource::form()), jadi mengubah namanya akan diam-diam merusak
 * logic itu tanpa ada peringatan apa pun ke admin yang me-rename.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Role / Divisi';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Role / Divisi';

    private const PROTECTED_ROLES = ['super_admin', 'direksi', 'installer', 'partner', 'store_manager'];

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
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
                ->helperText('Pakai huruf kecil & underscore, contoh: finance, hrd, warehouse_staff. Nama ini yang muncul di dropdown Role saat bikin akun User.')
                ->regex('/^[a-z_]+$/')
                ->validationMessages([
                    'regex' => 'Hanya huruf kecil dan underscore, tanpa spasi (mis. "warehouse_staff").',
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

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Jumlah User')
                    ->counts('users'),

                Tables\Columns\IconColumn::make('protected')
                    ->label('Terkunci')
                    ->getStateUsing(fn (Role $record) => in_array($record->name, self::PROTECTED_ROLES, true))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('gray')
                    ->falseColor('success'),

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
