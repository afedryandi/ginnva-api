<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarningLetterResource\Pages;
use App\Models\Store;
use App\Models\User;
use App\Models\WarningLetter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarningLetterResource extends Resource
{
    protected static ?string $model = WarningLetter::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationLabel = 'Surat Peringatan';

    protected static ?string $modelLabel = 'Surat Peringatan';

    protected static ?string $pluralModelLabel = 'Surat Peringatan';

    protected static ?int $navigationSort = 60;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * Eager-load user/store/issuer — kolom "user.name"/"store.name"/
     * "issuer.name" di table() sebelumnya N+1 per baris (audit fitur
     * Surat Peringatan 2026-09-27, pola sama dengan 13 resource lain
     * yang sudah disapu sebelumnya, resource ini terlewat).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'store', 'issuer']);
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    /**
     * Store manager/staff biasa cuma boleh MENERBITKAN SP baru — sekali
     * sudah terbit, cuma full-access yang boleh koreksi (level/alasan/
     * tanggal). Sebelum ini SIAPA PUN dengan akses menu bisa mengedit SP
     * yang sudah terbit kapan saja tanpa pengaman apa pun (beda dari
     * AttendanceResource yang mengunci koreksi baris sensitif untuk
     * non-full-access) — dokumen disipliner seperti ini butuh jejak yang
     * tidak gampang diutak-atik pihak yang menerbitkannya sendiri.
     */
    public static function canEdit($record): bool
    {
        $user = auth()->user();

        // hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
        // default FALSE (tetap ketat spt sebelumnya, isFullAccess()-only).
        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    /**
     * SEBELUMNYA tidak ada canCreate()/canDelete() sama sekali, dan tidak
     * ada WarningLetterPolicy terdaftar — Gate default-deny bikin
     * CreateAction (halaman "Terbitkan SP") dan DeleteAction (->visible()
     * sudah ada, isFullAccess saja) TIDAK PERNAH muncul untuk siapa pun,
     * termasuk super_admin. canCreate() dibiarkan seluas canViewAny()
     * (siapa saja dengan akses menu boleh MENERBITKAN SP baru, sesuai
     * komentar di canEdit() di atas), canDelete() disamakan persis dengan
     * guard ->visible() yang sudah ada di DeleteAction.
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'create', true);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Surat Peringatan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        ->disabled(! $isSuperAdmin)
                        ->dehydrated()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('user_id', null)),

                    // Karyawan difilter per toko yang dipilih — beda dari
                    // AttendanceResource/LeaveRequestResource yang sengaja
                    // dibalik urutannya, karena di sini toko SELALU sudah
                    // ada isinya lebih dulu (default ke toko sendiri untuk
                    // non-super-admin), jadi tidak ada masalah "toko dulu
                    // baru karyawan hilang dari daftar" seperti kasus itu.
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn (Forms\Get $get) => User::where('store_id', $get('store_id'))
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->live(),

                    /**
                     * Gap standar enterprise diperbaiki 2026-09-27 (audit
                     * Surat Peringatan) -- SEBELUMNYA form tidak
                     * menampilkan riwayat SP karyawan yang sama sama
                     * sekali, admin bisa menerbitkan SP3 tanpa tahu
                     * karyawan itu belum pernah dapat SP1/SP2, atau
                     * menerbitkan SP1 lagi setelah karyawan sudah SP3.
                     * SENGAJA berupa info, bukan validasi keras/blocking
                     * -- pelanggaran baru bisa saja memang layak SP3
                     * langsung tanpa riwayat, keputusan tetap di tangan
                     * admin, sistem cuma wajib menampilkan konteksnya.
                     */
                    Forms\Components\Placeholder::make('warning_history')
                        ->label('Riwayat SP Karyawan Ini')
                        ->visible(fn (Forms\Get $get) => filled($get('user_id')))
                        ->content(function (Forms\Get $get) {
                            $userId = $get('user_id');
                            if (! $userId) {
                                return '—';
                            }

                            $previous = \App\Models\WarningLetter::where('user_id', $userId)
                                ->orderByDesc('issued_date')
                                ->get(['level', 'issued_date', 'warning_number']);

                            if ($previous->isEmpty()) {
                                return 'Belum pernah menerima SP sebelumnya.';
                            }

                            $lines = $previous->map(fn ($w) => e(strtoupper(str_replace('sp', 'SP ', $w->level)))
                                . ' — ' . e($w->warning_number)
                                . ' (' . e($w->issued_date->translatedFormat('d M Y')) . ')');

                            return new \Illuminate\Support\HtmlString($lines->implode('<br>'));
                        })
                        ->columnSpanFull(),

                    Forms\Components\Select::make('level')
                        ->label('Tingkat')
                        ->options([
                            'sp1' => 'SP 1',
                            'sp2' => 'SP 2',
                            'sp3' => 'SP 3',
                        ])
                        ->required(),

                    Forms\Components\DatePicker::make('issued_date')
                        ->label('Tanggal Diterbitkan')
                        ->required()
                        ->default(today()),

                    Forms\Components\DatePicker::make('valid_until')
                        ->label('Berlaku Sampai (opsional)')
                        ->helperText('Kosongkan kalau tidak ada batas waktu berlaku.')
                        ->minDate(fn (Forms\Get $get) => $get('issued_date')),

                    Forms\Components\FileUpload::make('document')
                        ->label('Scan Surat (opsional)')
                        ->directory('warning-letters')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240),

                    /**
                     * Read-only -- HANYA karyawan sendiri yang bisa
                     * mengisi ini (lewat tombol "Tandai Sudah Dibaca" di
                     * mobile), admin tidak boleh menandai atas nama
                     * karyawan (lihat migrasi acknowledged_at).
                     */
                    Forms\Components\Placeholder::make('acknowledged_at')
                        ->label('Dibaca Karyawan')
                        ->visible(fn (?WarningLetter $record) => $record !== null)
                        ->content(fn (?WarningLetter $record) => $record?->acknowledged_at
                            ? $record->acknowledged_at->translatedFormat('d M Y H:i')
                            : 'Belum dibaca karyawan'),

                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan / Pelanggaran')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warning_number')
                    ->label('No. Surat')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('level')
                    ->label('Tingkat')
                    ->colors([
                        'warning' => 'sp1',
                        'danger'  => 'sp2',
                        'gray'    => 'sp3',
                    ])
                    ->formatStateUsing(fn (string $state) => strtoupper(str_replace('sp', 'SP ', $state))),

                Tables\Columns\TextColumn::make('issued_date')
                    ->label('Diterbitkan')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Berlaku Sampai')
                    ->date('d M Y')
                    ->placeholder('Tidak ada batas'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issuer.name')
                    ->label('Diterbitkan Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('acknowledged_at')
                    ->label('Dibaca Karyawan')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (WarningLetter $record) => $record->acknowledged_at
                        ? 'Dibaca ' . $record->acknowledged_at->translatedFormat('d M Y H:i')
                        : 'Belum dibaca karyawan')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')
                    ->label('Tingkat')
                    ->options(['sp1' => 'SP 1', 'sp2' => 'SP 2', 'sp3' => 'SP 3']),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),

                // Gap standar enterprise (audit 2026-09-27) -- SEBELUMNYA
                // tidak ada filter per karyawan/rentang tanggal sama
                // sekali, tidak mungkin membuat rekap SP per karyawan
                // atau per periode dari tabel ini tanpa filter dasar ini.
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Karyawan')
                    ->relationship('user', 'name')
                    ->searchable(),

                Tables\Filters\Filter::make('issued_date')
                    ->label('Rentang Tanggal Terbit')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('issued_date', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('issued_date', '<=', $date));
                    })
                    ->indicateUsing(function (array $data) {
                        $indicators = [];
                        if ($data['from'] ?? null) $indicators[] = 'Dari ' . \Carbon\Carbon::parse($data['from'])->translatedFormat('d M Y');
                        if ($data['until'] ?? null) $indicators[] = 'Sampai ' . \Carbon\Carbon::parse($data['until'])->translatedFormat('d M Y');

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('viewDocument')
                    ->label('Lihat Scan')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (WarningLetter $record) => filled($record->document))
                    ->url(fn (WarningLetter $record) => \Illuminate\Support\Facades\Storage::disk('public')->url($record->document))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->defaultSort('issued_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarningLetters::route('/'),
            'create' => Pages\CreateWarningLetter::route('/create'),
            'edit'   => Pages\EditWarningLetter::route('/{record}/edit'),
        ];
    }
}
