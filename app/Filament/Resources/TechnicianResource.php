<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TechnicianResource\Pages;
use App\Models\Technician;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TechnicianResource extends Resource
{
    protected static ?string $model = Technician::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Booking';

    protected static ?string $navigationLabel = 'Teknisi';

    protected static ?string $modelLabel = 'Teknisi';

    protected static ?string $pluralModelLabel = 'Teknisi';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Teknisi')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->default(fn () => auth()->user()?->store_id)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama Teknisi')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('user_id')
                        ->label('Akun Installer')
                        ->helperText('Hubungkan ke akun installer supaya level sertifikasi ini terlihat saat menugaskan installer di Booking. Boleh dikosongkan kalau akun login-nya belum dibuat.')
                        ->options(fn (Forms\Get $get) => User::where('store_id', $get('store_id'))
                            ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->preload()
                        // SEBELUMNYA 1 akun installer bisa tertaut ke lebih
                        // dari 1 baris Technician tanpa ditolak — level
                        // sertifikasi jadi ambigu (User::technician() pakai
                        // hasOne, cuma ambil baris pertama kalau ada
                        // duplikat). Lihat audit modul Teknisi 2026-08-27.
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Akun installer ini sudah tertaut ke baris Teknisi lain.',
                        ]),

                    Forms\Components\TextInput::make('phone')
                        ->label('No. Telepon / HP')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\Select::make('level')
                        ->label('Level Sertifikasi')
                        ->options([
                            'intermediate' => 'Intermediate',
                            'advanced'     => 'Advanced',
                            'mentor'       => 'Mentor',
                        ])
                        ->required()
                        ->default('intermediate'),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'pending_review' => 'Menunggu Review',
                            'active'         => 'Aktif',
                            'inactive'       => 'Nonaktif',
                        ])
                        ->required()
                        ->default('pending_review')
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * SEBELUMNYA tidak ada halaman View sama sekali — sama gap dengan
     * Booking sebelum diperbaiki (dampaknya lebih rendah di sini karena
     * form Edit-nya sudah ringkas, tapi tetap dibetulkan untuk
     * konsistensi pola). Lihat audit modul Teknisi 2026-08-27.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name')->label('Nama Teknisi'),
            TextEntry::make('store.name')->label('Toko'),
            TextEntry::make('user.name')->label('Akun Installer')->placeholder('Belum terhubung'),
            TextEntry::make('phone')->label('No. Telepon / HP')->placeholder('—'),
            TextEntry::make('level')
                ->label('Level Sertifikasi')
                ->badge()
                ->formatStateUsing(fn (string $state) => match ($state) {
                    'intermediate' => 'Intermediate',
                    'advanced'     => 'Advanced',
                    'mentor'       => 'Mentor',
                    default        => $state,
                }),
            TextEntry::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (string $state) => match ($state) {
                    'pending_review' => 'Menunggu Review',
                    'active'         => 'Aktif',
                    'inactive'       => 'Nonaktif',
                    default          => $state,
                })
                ->color(fn (string $state) => match ($state) {
                    'pending_review' => 'warning',
                    'active'         => 'success',
                    'inactive'       => 'danger',
                    default          => 'gray',
                }),
            TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
            TextEntry::make('created_at')->label('Ditambahkan')->dateTime('d M Y H:i'),
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

                Tables\Columns\TextColumn::make('phone')
                    ->label('No. Telepon')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Akun Installer')
                    ->placeholder('Belum terhubung')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('level')
                    ->label('Level')
                    ->colors([
                        'gray'    => 'intermediate',
                        'warning' => 'advanced',
                        'success' => 'mentor',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'intermediate' => 'Intermediate',
                        'advanced'     => 'Advanced',
                        'mentor'       => 'Mentor',
                        default        => $state,
                    }),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending_review',
                        'success' => 'active',
                        'danger'  => 'inactive',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_review' => 'Menunggu Review',
                        'active'         => 'Aktif',
                        'inactive'       => 'Nonaktif',
                        default          => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ditambahkan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')
                    ->label('Level')
                    ->options([
                        'intermediate' => 'Intermediate',
                        'advanced'     => 'Advanced',
                        'mentor'       => 'Mentor',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending_review' => 'Menunggu Review',
                        'active'         => 'Aktif',
                        'inactive'       => 'Nonaktif',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Aktifkan')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Technician $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending_review')
                    ->requiresConfirmation()
                    ->action(fn (Technician $record) => $record->update(['status' => 'active'])),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending_review')->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTechnicians::route('/'),
            'create' => Pages\CreateTechnician::route('/create'),
            'view'   => Pages\ViewTechnician::route('/{record}'),
            'edit'   => Pages\EditTechnician::route('/{record}/edit'),
        ];
    }
}
