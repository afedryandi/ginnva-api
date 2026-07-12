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
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'User';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    protected static ?int $navigationSort = 90;

    /**
     * Resource ini HANYA boleh diakses super_admin. Tidak pakai Policy
     * terpisah karena scope-nya simpel (binary: super_admin atau tidak
     * sama sekali) — beda dengan Warranty/Quotation yang perlu scope
     * per-store yang lebih kompleks.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        // User tidak boleh hapus akun miliknya sendiri lewat panel ini,
        // supaya tidak ada super_admin yang tidak sengaja terkunci keluar.
        return auth()->user()?->hasRole('super_admin')
            && auth()->id() !== $record->id;
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
                        ->multiple()
                        ->preload()
                        ->live()
                        ->required()
                        ->helperText('super_admin: akses penuh. regional_admin & installer: wajib pilih Toko di bawah (installer tidak bisa login ke panel ini, hanya via mobile app).'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko (khusus regional_admin & installer)')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->required(function (Forms\Get $get): bool {
                            $roleIds = $get('roles') ?? [];
                            if (empty($roleIds)) return false;
                            return \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
                                ->whereIn('name', ['regional_admin', 'installer'])
                                ->exists();
                        })
                        ->helperText('Wajib diisi kalau role-nya regional_admin atau installer. Kosongkan untuk super_admin.'),

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
                        'super_admin' => 'danger',
                        'regional_admin' => 'warning',
                        default => 'gray',
                    }),

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
        // Tidak perlu scope tambahan — canViewAny() sudah memastikan
        // hanya super_admin yang bisa sampai ke titik ini, jadi semua
        // user boleh terlihat (tidak ada konsep "user milik toko lain
        // yang harus disembunyikan" di sini).
        return parent::getEloquentQuery();
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