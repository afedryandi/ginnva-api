<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TechnicianResource\Pages;
use App\Filament\Resources\TechnicianResource\RelationManagers\ServiceRatesRelationManager;
use App\Models\Technician;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TechnicianResource extends Resource
{
    protected static ?string $model = Technician::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

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

    /**
     * SEBELUMNYA tidak ada canCreate()/canView()/canEdit()/canDelete()/
     * canDeleteAny() sama sekali, dan tidak ada TechnicianPolicy
     * terdaftar — Gate default-deny bikin CreateAction, ViewAction,
     * EditAction, DeleteAction/DeleteBulkAction (TIDAK ADA ->visible()
     * guard tambahan di table(), jadi memang tidak pernah diniatkan
     * dibatasi lebih sempit dari canViewAny()) semuanya tidak pernah
     * muncul untuk siapa pun — termasuk super_admin. Resource ini
     * langsung memengaruhi roster installer yang dipakai alur assignment
     * Booking (mobile & Filament), jadi dampaknya cukup luas.
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        // hasModuleAction(..., true) (audit Majoo f64, 2026-09-23) —
        // default TRUE karena SEBELUMNYA aksi ini sama longgarnya dengan
        // canViewAny(), supaya tidak ada akun yang diam-diam kehilangan
        // kemampuan yang sudah biasa mereka pakai; admin baru bisa
        // MEMPERKETAT lewat "Hak Akses Detail" kalau perlu.
        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'create', true);
    }

    public static function canView($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'view', true);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'update', true);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'delete', true);
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'delete', true);
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

                    Forms\Components\TextInput::make('commission_amount')
                        ->label('Komisi per Pekerjaan (Rp)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->helperText('Nominal tetap yang didapat teknisi ini per booking yang dia kerjakan (bukan persentase). Kalau 1 booking dikerjakan >1 teknisi, masing-masing dapat nominal penuh ini, bukan dibagi. Kosongkan kalau belum ada aturan komisi untuk teknisi ini. DIABAIKAN begitu teknisi ini punya minimal 1 baris di tab "Tarif per Layanan" (setelah disimpan) -- lihat tab itu untuk tarif berbeda per jenis layanan.')
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

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
            TextEntry::make('commission_amount')
                ->label('Komisi per Pekerjaan')
                ->placeholder('Belum diatur')
                ->money('IDR', locale: 'id')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
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

                // Data komisi sensitif -- disembunyikan default & khusus
                // isFullAccess, sama filosofi PayrollResource (net_pay).
                Tables\Columns\TextColumn::make('commission_amount')
                    ->label('Komisi/Pekerjaan')
                    ->money('IDR', locale: 'id')
                    ->placeholder('Belum diatur')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),

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
                // BUG DIPERBAIKI 2026-09-25 (audit Teknisi): SEBELUMNYA
                // cuma update status, tidak ada notifikasi apa pun ke
                // installer yang bersangkutan -- dia tidak tahu akunnya
                // sudah bisa ditugaskan ke booking sampai kebetulan buka
                // app. Pola notifikasi disamakan dengan
                // LeaveRequestResource (approve/reject sudah benar di
                // sana sejak awal).
                Tables\Actions\Action::make('approve')
                    ->label('Aktifkan')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Technician $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending_review')
                    ->requiresConfirmation()
                    ->action(function (Technician $record) {
                        $record->update(['status' => 'active']);

                        if ($record->user_id) {
                            app(PushNotificationService::class)->sendToUsers(
                                [$record->user_id],
                                'Akun Teknisi Diaktifkan',
                                'Akun teknisi Anda sudah diaktifkan — sekarang Anda bisa ditugaskan ke booking.'
                            );
                        }

                        Notification::make()->title('Teknisi diaktifkan.')->success()->send();
                    }),

                // BUG DIPERBAIKI 2026-09-25 (audit Teknisi): SEBELUMNYA
                // tidak ada aksi tolak/nonaktifkan yang simetris dengan
                // "Aktifkan" -- staff harus buka form Edit penuh untuk
                // menolak pendaftaran teknisi baru.
                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Technician $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending_review')
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Alasan Ditolak')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (Technician $record, array $data) {
                        // Alasan penolakan disimpan ke 'notes' (kolom yang
                        // sudah ada) -- bukan cuma dikirim sekali lewat
                        // notifikasi lalu hilang, supaya bisa dicek ulang
                        // nanti (mis. installer daftar ulang, admin lupa
                        // kenapa ditolak sebelumnya).
                        $existingNotes = trim((string) $record->notes);
                        $rejectionNote = now()->format('d M Y') . ' — Ditolak: ' . $data['review_note'];

                        $record->update([
                            'status' => 'inactive',
                            'notes'  => $existingNotes !== '' ? "{$existingNotes}\n{$rejectionNote}" : $rejectionNote,
                        ]);

                        if ($record->user_id) {
                            app(PushNotificationService::class)->sendToUsers(
                                [$record->user_id],
                                'Pendaftaran Teknisi Ditolak',
                                "Pendaftaran teknisi Anda ditolak: {$data['review_note']}"
                            );
                        }

                        Notification::make()->title('Teknisi ditolak.')->warning()->send();
                    }),

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

    public static function getRelations(): array
    {
        return [
            ServiceRatesRelationManager::class,
        ];
    }

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