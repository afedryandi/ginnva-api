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

    // Grup sidebar (audit navigasi 2026-09-29) -- band 300-399 "Cuti & Kontrak".
    protected static ?string $navigationGroup = 'Pengaturan Karyawan';

    protected static ?string $navigationLabel = 'Surat Peringatan';

    protected static ?string $modelLabel = 'Surat Peringatan';

    protected static ?string $pluralModelLabel = 'Surat Peringatan';

    protected static ?int $navigationSort = 605;

    /**
     * Surat Peringatan adalah dokumen disipliner: membaca semua SP satu toko dan menerbitkannya (yang langsung memberi tahu karyawan) tidak
     * boleh terbuka lewat akses bawaan akun baru (menu_access kosong). Hanya akses penuh atau staf yang menunya dicentang eksplisit;
     * menerbitkan, mengoreksi, dan menghapus masing-masing butuh izin aksi eksplisit (audit Surat Peringatan, 2026-10-31).
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    /**
     * Eager-load user/store/issuer — kolom "user.name"/"store.name"/
     * "issuer.name" di table() sebelumnya N+1 per baris (audit fitur
     * Surat Peringatan 2026-09-27, pola sama dengan 13 resource lain
     * yang sudah disapu sebelumnya, resource ini terlewat).
     */
    public static function getEloquentQuery(): Builder
    {
        // "Jumlah SP berlaku" karyawan yang sama, ditampilkan di bawah namanya (satu subquery, bukan satu query per baris).
        return static::scopedQuery()
            ->with(['user', 'store', 'issuer'])
            ->select('warning_letters.*')
            ->addSelect(['active_count' => WarningLetter::query()->withoutGlobalScopes()->from('warning_letters as sibling')
                ->selectRaw('count(*)')
                ->whereColumn('sibling.user_id', 'warning_letters.user_id')
                ->whereNull('sibling.cancelled_at')
                ->whereDate('sibling.issued_date', '<=', today()->toDateString())
                ->where(fn ($q) => $q->whereNull('sibling.valid_until')->orWhereDate('sibling.valid_until', '>=', today()->toDateString()))]);
    }

    /** Surat yang boleh dilihat akun ini: akses penuh semuanya, selain itu hanya surat tokonya. */
    private static function scopedQuery(): Builder
    {
        $query = parent::getEloquentQuery();
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
        return static::canViewAny() && ($user?->hasModuleAction(static::class, 'update', false) ?? false);
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
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', false) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    /**
     * Validasi SERVER: Select karyawan cuma dibatasi di UI (opsi dropdown), nilai kiriman tidak
     * divalidasi -- tanpa ini SP bisa diterbitkan untuk partner atau karyawan toko lain, atau
     * tercatat di toko yang tidak sama dengan toko karyawannya (SP jadi tak terlihat/terisolasi salah).
     */
    public static function employeeProblem(array $data): ?string
    {
        $employee = User::find($data['user_id'] ?? 0);

        if (! $employee || $employee->hasRole('partner')) {
            return 'Karyawan tidak valid.';
        }

        if ((int) $employee->store_id !== (int) ($data['store_id'] ?? 0)) {
            return 'Karyawan harus berada di toko yang sama dengan toko surat ini.';
        }

        return null;
    }

    /** Data untuk surat resmi (PDF): tingkat dalam kata, jabatan karyawan, dan SP sebelumnya yang tidak dibatalkan. */
    public static function letterViewData(WarningLetter $letter): array
    {
        $letter->loadMissing(['user', 'store', 'issuer']);
        $number = WarningLetter::levelNumber($letter->level);

        return [
            'letter' => $letter,
            'levelNumber' => $number,
            'levelWords' => match ($number) {
                1 => 'Surat Peringatan Pertama',
                2 => 'Surat Peringatan Kedua',
                default => 'Surat Peringatan Ketiga dan Terakhir',
            },
            'roleLabel' => $letter->user?->primaryRoleName() ? \Illuminate\Support\Str::of($letter->user->primaryRoleName())->replace('_', ' ')->title()->toString() : null,
            'previous' => WarningLetter::withoutGlobalScopes()->where('user_id', $letter->user_id)
                ->whereNull('cancelled_at')
                ->where('id', '!=', $letter->id)
                ->whereDate('issued_date', '<=', $letter->issued_date->toDateString())
                ->orderBy('issued_date')->get(),
        ];
    }

    /** Surat resmi dalam PDF. Surat yang dibatalkan tetap bisa dicetak, dengan tanda DIBATALKAN dan alasannya di bagian atas. */
    public static function downloadLetterPdf(WarningLetter $letter)
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.warning-letter', static::letterViewData($letter))->setPaper('a4', 'portrait');

        return response()->streamDownload(fn () => print($pdf->output()), 'Surat-Peringatan-' . $letter->warning_number . '.pdf');
    }

    /** Tingkat yang boleh diterbitkan akun ini: akses penuh semuanya; yang lain hanya SP 1 (SP 2 dan SP 3 menuju PHK, keputusan 2026-10-31). */
    public static function allowedLevels(): array
    {
        return auth()->user()?->isFullAccess()
            ? ['sp1' => 'SP 1', 'sp2' => 'SP 2', 'sp3' => 'SP 3']
            : ['sp1' => 'SP 1'];
    }

    /**
     * Validasi SERVER (pilihan di form bisa dipalsukan): akun non-penuh tidak boleh menerbitkan SP 2 atau SP 3, dan tidak boleh menaikkan
     * tingkat surat yang ada. Tingkat yang sudah tersimpan pada surat boleh tetap saat bagian lain dikoreksi.
     */
    public static function levelProblem(array $data, ?WarningLetter $existing = null): ?string
    {
        if (auth()->user()?->isFullAccess()) {
            return null;
        }

        $level = $data['level'] ?? null;

        if ($level === 'sp1' || ($existing && $existing->level === $level)) {
            return null;
        }

        return 'SP 2 dan SP 3 hanya bisa diterbitkan oleh akses penuh. Ajukan ke akses penuh, atau terbitkan SP 1.';
    }

    /** Membatalkan SP memakai aksi "Void" di Hak Akses Detail (akses penuh selalu boleh). */
    public static function canVoid(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'void', false) ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();
        // Surat yang sudah dibaca karyawan atau dibatalkan: isinya terkunci (hanya Scan Surat yang bisa dilengkapi).
        $locked = fn (?WarningLetter $record) => $record?->isLocked() ?? false;

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
                        ->disabled(fn (?WarningLetter $record) => ! $isSuperAdmin || $locked($record))
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
                        ->disabled($locked)
                        ->live()
                        // Tingkat diisi otomatis dengan saran berjenjang (Karyawan Fase 3 lanjutan); tetap bisa diganti.
                        ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                            $suggested = $state ? \App\Models\WarningLetter::suggestedLevelFor((int) $state) : null;

                            // Tingkat yang tidak boleh diterbitkan akun ini tidak diisi otomatis; helper di bawah menjelaskan kenapa.
                            $set('level', $suggested !== null && array_key_exists($suggested, static::allowedLevels()) ? $suggested : null);
                        }),

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
                                ->get(['level', 'issued_date', 'warning_number', 'cancelled_at']);

                            if ($previous->isEmpty()) {
                                return 'Belum pernah menerima SP sebelumnya.';
                            }

                            $lines = $previous->map(fn ($w) => e(strtoupper(str_replace('sp', 'SP ', $w->level)))
                                . ' — ' . e($w->warning_number)
                                . ' (' . e($w->issued_date->translatedFormat('d M Y')) . ')' . ($w->cancelled_at ? ' <em>dibatalkan</em>' : ''));

                            return new \Illuminate\Support\HtmlString($lines->implode('<br>'));
                        })
                        ->columnSpanFull(),

                    Forms\Components\Select::make('level')
                        ->label('Tingkat')
                        ->options(fn (?WarningLetter $record) => static::allowedLevels() + ($record ? [$record->level => strtoupper(str_replace('sp', 'SP ', $record->level))] : []))
                        ->required()
                        ->disabled($locked)
                        ->live()
                        ->helperText(function (Forms\Get $get) {
                            $userId = $get('user_id');
                            if (! $userId) return null;

                            $active = \App\Models\WarningLetter::activeFor((int) $userId);
                            $suggested = \App\Models\WarningLetter::suggestedLevelFor((int) $userId);
                            $suggestedLabel = strtoupper(str_replace('sp', 'SP ', $suggested));

                            $text = $active->isEmpty()
                                ? "Tidak ada SP yang masih berlaku; disarankan {$suggestedLabel}."
                                : 'SP yang masih berlaku: ' . $active->map(fn ($w) => strtoupper(str_replace('sp', 'SP ', $w->level)) . ' (s/d ' . ($w->valid_until?->format('d M Y') ?? 'tanpa batas') . ')')->implode(', ') . ". Disarankan {$suggestedLabel}.";

                            if ($get('level') && \App\Models\WarningLetter::levelNumber($get('level')) > \App\Models\WarningLetter::levelNumber($suggested)) {
                                $text .= ' Anda memilih tingkat yang melompati urutan; pastikan pelanggarannya memang layak dan tercatat di Peraturan Perusahaan.';
                            }

                            if (! array_key_exists($suggested, static::allowedLevels())) {
                                $text .= ' SP 2 dan SP 3 hanya bisa diterbitkan oleh akses penuh; ajukan ke mereka, atau terbitkan SP 1.';
                            }

                            return $text;
                        }),

                    Forms\Components\DatePicker::make('issued_date')
                        ->label('Tanggal Diterbitkan')
                        ->required()
                        ->default(today())
                        ->maxDate(today())
                        ->disabled($locked)
                        ->live()
                        ->helperText(function (Forms\Get $get) {
                            $months = (int) config('hr.warning_validity_months', WarningLetter::DEFAULT_VALIDITY_MONTHS);

                            if (filled($get('issued_date')) && blank($get('valid_until')) && \Illuminate\Support\Carbon::parse($get('issued_date'))->addMonths($months)->lt(today())) {
                                return "Tanggal ini sudah lebih dari {$months} bulan lalu: tanpa tanggal Berlaku Sampai, surat ini langsung berstatus kedaluwarsa.";
                            }

                            return 'Tidak boleh tanggal yang akan datang.';
                        }),

                    Forms\Components\DatePicker::make('valid_until')
                        ->label('Berlaku Sampai (opsional)')
                        ->helperText(fn () => 'Kosongkan untuk otomatis ' . (int) config('hr.warning_validity_months', WarningLetter::DEFAULT_VALIDITY_MONTHS) . ' bulan sejak tanggal terbit (PP 35/2021 Pasal 52).')
                        ->disabled($locked)
                        ->minDate(fn (Forms\Get $get) => $get('issued_date')),

                    Forms\Components\FileUpload::make('document')
                        ->label('Scan Surat (opsional)')
                        ->disk('local')
                        ->visibility('private')
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
                        ->disabled($locked)
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('lock_notice')
                        ->label('Status')
                        ->visible(fn (?WarningLetter $record) => $record?->isLocked() ?? false)
                        ->content(fn (?WarningLetter $record) => $record?->isCancelled()
                            ? 'Dibatalkan ' . $record->cancelled_at->translatedFormat('d M Y H:i') . ': ' . $record->cancel_reason
                            : 'Sudah dibaca karyawan: isi surat terkunci. Bila keliru, batalkan lewat tombol Batalkan SP di daftar lalu terbitkan surat baru.')
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
                    ->sortable()
                    ->description(fn (WarningLetter $record) => (int) $record->active_count > 0 ? ((int) $record->active_count . ' SP berlaku') : null),

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

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (WarningLetter $record) => $record->statusLabel())
                    ->color(fn (WarningLetter $record) => match ($record->statusLabel()) {
                        'Berlaku' => 'success',
                        'Dibatalkan' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (WarningLetter $record) => $record->isCancelled() ? 'Alasan: ' . $record->cancel_reason : null),

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
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(['berlaku' => 'Berlaku', 'kedaluwarsa' => 'Kedaluwarsa', 'dibatalkan' => 'Dibatalkan'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'berlaku' => $query->active(),
                        'kedaluwarsa' => $query->whereNull('cancelled_at')->whereNotNull('valid_until')->whereDate('valid_until', '<', today()->toDateString()),
                        'dibatalkan' => $query->whereNotNull('cancelled_at'),
                        default => $query,
                    }),

                Tables\Filters\Filter::make('unread')
                    ->label('Belum dibaca karyawan lebih dari ' . (int) config('hr.warning_unread_days', 3) . ' hari')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->unreadForTooLong()),

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
                // Hanya karyawan yang punya surat dalam cakupan akun ini (relationship() akan memuat semua karyawan, termasuk toko lain).
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Karyawan')
                    ->options(fn () => User::whereIn('id', static::scopedQuery()->reorder()->select('warning_letters.user_id'))->orderBy('name')->pluck('name', 'id'))
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
                    ->url(fn (WarningLetter $record) => \App\Support\SensitiveFiles::url($record->document))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('printLetter')
                    ->label('Cetak Surat')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(fn (WarningLetter $record) => static::downloadLetterPdf($record)),

                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('cancelLetter')
                    ->label('Batalkan SP')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WarningLetter $record) => ! $record->isCancelled() && static::canVoid())
                    ->modalHeading('Batalkan Surat Peringatan')
                    ->modalDescription('Surat tetap tersimpan sebagai bukti, tetapi tidak dihitung sebagai SP berlaku maupun dasar tingkat berikutnya. Karyawan diberi tahu. Tidak bisa dikembalikan; bila perlu, terbitkan surat baru.')
                    ->form([
                        Forms\Components\Textarea::make('cancel_reason')->label('Alasan pembatalan')->required()->rows(3),
                    ])
                    ->action(function (WarningLetter $record, array $data) {
                        try {
                            $cancelled = $record->cancel(auth()->user(), $data['cancel_reason']);
                        } catch (\InvalidArgumentException $e) {
                            \Filament\Notifications\Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(\App\Services\StaffNotifier::class)->toUsers(
                            [$cancelled->user_id],
                            'Surat Peringatan Dibatalkan',
                            "Surat Peringatan {$cancelled->warning_number} dibatalkan. Alasan: {$cancelled->cancel_reason}",
                            ['type' => 'warning_letter', 'route' => '/staff/attendance/warning-letters']
                        );

                        \Filament\Notifications\Notification::make()->title('Surat dibatalkan')->success()->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (WarningLetter $record) => ! $record->isLocked() && static::canDelete($record))
                    ->modalDescription('Hanya untuk surat yang salah input dan belum dibaca karyawan. Surat yang sudah dibaca tidak bisa dihapus; gunakan Batalkan SP.'),
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
