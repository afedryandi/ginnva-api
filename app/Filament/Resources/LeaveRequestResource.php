<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeaveRequestResource\Pages;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 300-399 "Cuti & Kontrak".
    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Izin & Cuti';

    protected static ?string $modelLabel = 'Izin/Cuti';

    protected static ?string $pluralModelLabel = 'Izin & Cuti';

    protected static ?int $navigationSort = 102;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * SEBELUMNYA tidak ada canCreate()/canEdit()/canDelete() sama
     * sekali, dan tidak ada LeaveRequestPolicy terdaftar — Gate default-
     * deny bikin CreateAction, EditAction (->visible() sudah ada, status
     * pending saja), dan DeleteAction (->visible() sudah ada, isFullAccess
     * + pending) TIDAK PERNAH muncul untuk siapa pun, termasuk super_admin
     * — sama bug class dengan AttendanceResource. Dibiarkan seluas
     * canViewAny() untuk Create/Edit (guard status='pending' yang sudah
     * ada di ->visible() DeleteAction/EditAction cukup sebagai pembatas
     * tambahan), canDelete() disamakan persis dengan guard ->visible()
     * yang sudah ada.
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'create', true);
    }

    /**
     * Boleh melihat dan mengelola izin/cuti SELURUH toko: akses penuh, store manager, atau staf yang diberi izin Ubah di Hak Akses Detail.
     * Staf lain hanya melihat dan mengajukan miliknya sendiri (izin sakit dan lampiran surat dokter bersifat pribadi).
     */
    public static function seesWholeStore(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->isFullAccess() || $user->isStoreManager() || $user->hasRole('spv_finance') || $user->hasModuleAction(static::class, 'update', false));
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'update', true)
            // Pengajuan yang sudah diputuskan (disetujui/ditolak/dibatalkan) terkunci: tombol Edit sudah
            // disembunyikan di tabel, tapi halaman edit tetap bisa dibuka lewat URL dan mengubah tanggal/jenis
            // pengajuan yang sudah disetujui tanpa persetujuan ulang.
            && (! $record || $record->status === 'pending')
            && (! $record || static::seesWholeStore() || $record->user_id === $user->id);
    }

    /**
     * hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
     * default FALSE, guard status='pending' TETAP DIPERTAHANKAN.
     */
    public static function canDelete($record): bool
    {
        $user = auth()->user();

        $allowed = $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));

        return $allowed && $record->status === 'pending';
    }

    public static function getEloquentQuery(): Builder
    {
        // ->with(['user', 'store']) ditambah 2026-09-27 (audit ulang
        // Izin & Cuti) -- kolom dot-notation 'user.name'/'store.name' di
        // table() sebelumnya N+1 per baris.
        $query = parent::getEloquentQuery()->with(['user', 'store']);
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);

            if (! static::seesWholeStore()) {
                $query->where('user_id', $user->id);
            }
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Pengajuan Izin/Cuti')
                ->columns(2)
                ->schema([
                    // Karyawan dipilih duluan, Toko ikut otomatis — lihat
                    // catatan yang sama di AttendanceResource (kalau Toko
                    // duluan yang jadi filter, akun super_admin/direksi
                    // tanpa store_id tidak pernah bisa dipilih apapun toko
                    // yang dipilih).
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn () => User::when(
                                ! $isSuperAdmin,
                                fn ($q) => $q->where('store_id', auth()->user()?->store_id)
                            )
                            ->when(! static::seesWholeStore(), fn ($q) => $q->whereKey(auth()->id()))
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabled(fn (?LeaveRequest $record) => ! $record && ! static::seesWholeStore())
                        ->dehydrated()
                        ->default(fn () => $isSuperAdmin ? null : auth()->id())
                        ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('store_id', $state ? User::find($state)?->store_id : null)),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->helperText('Otomatis terisi dari toko karyawan yang dipilih.')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->disabled(! $isSuperAdmin)
                        ->dehydrated(),

                    // Jenis dari master Jenis Izin & Cuti (Karyawan Fase 2); kategori (type) diturunkan darinya saat disimpan.
                    Forms\Components\Select::make('leave_type_id')
                        ->label('Jenis')
                        ->options(fn () => \App\Models\LeaveType::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->native(false)
                        ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('type', $state ? \App\Models\LeaveType::find($state)?->category : null))
                        // Hanya jenis yang memotong jatah tahunan menampilkan sisa jatah -- lihat LeaveRequest::annualQuotaFor().
                        ->helperText(function (Forms\Get $get, ?LeaveRequest $record) {
                            $leaveType = $get('leave_type_id') ? \App\Models\LeaveType::find($get('leave_type_id')) : null;
                            if (! $leaveType) return null;
                            $rule = $leaveType->ruleSummary();
                            $userId = $get('user_id');
                            $user = $userId ? User::find($userId) : null;
                            if (! $leaveType->deducts_annual_quota || ! $user) return $rule ?: null;
                            $remaining = LeaveRequest::remainingCutiFor($user, today()->year) + ($record?->deductsAnnualQuota() ? $record->dayCount() : 0);
                            return trim("Sisa jatah cuti {$user->name} tahun ini: {$remaining} hari. {$rule}");
                        }),

                    Forms\Components\Hidden::make('type')
                        ->dehydrated()
                        ->dehydrateStateUsing(fn (Forms\Get $get, $state) => $get('leave_type_id') ? \App\Models\LeaveType::find($get('leave_type_id'))?->category : $state),

                    Forms\Components\DatePicker::make('start_date')
                        ->label('Dari Tanggal')
                        ->required()
                        ->live()
                        // Bukan minDate keras untuk EDIT (baris lama bisa
                        // saja dari tanggal yang sekarang sudah lewat, mis.
                        // dicatat admin belakangan) — cuma dipaksa untuk
                        // pengajuan BARU lewat CreateLeaveRequest.
                        // Akses penuh boleh mencatat izin/sakit untuk tanggal yang sudah lewat (karyawan melapor belakangan); saat disetujui,
                        // baris Alpha di tanggal itu menjadi Izin. Staf lain tetap hanya untuk hari ini dan seterusnya.
                        ->minDate(fn (?LeaveRequest $record) => ($record || (auth()->user()?->isFullAccess() ?? false)) ? null : today())
                        ->helperText(fn (?LeaveRequest $record) => (! $record && (auth()->user()?->isFullAccess() ?? false))
                            ? 'Boleh tanggal yang sudah lewat (mis. sakit yang dilaporkan belakangan). Saat disetujui, absensi Alpha di tanggal itu otomatis menjadi Izin.'
                            : null),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('Sampai Tanggal')
                        ->required()
                        ->minDate(fn (Forms\Get $get) => $get('start_date'))
                        ->rule(fn (Forms\Get $get, ?LeaveRequest $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            if (! $value || ! $get('start_date') || ! $get('user_id')) return;

                            $dayCount = Carbon::parse($get('start_date'))->diffInDays(Carbon::parse($value)) + 1;
                            $leaveType = $get('leave_type_id') ? \App\Models\LeaveType::find($get('leave_type_id')) : null;
                            $maxDays = $leaveType?->max_days ?: LeaveRequest::MAX_DURATION_DAYS;
                            if ($dayCount > $maxDays) {
                                $fail($leaveType?->max_days ? "{$leaveType->name} maksimal {$maxDays} hari." : 'Durasi pengajuan maksimal ' . LeaveRequest::MAX_DURATION_DAYS . ' hari.');
                                return;
                            }

                            $user = User::find($get('user_id'));
                            if (! $user) return;

                            if (LeaveRequest::hasOverlap($user, $get('start_date'), $value, $record?->id)) {
                                $fail('Karyawan ini sudah punya pengajuan izin/cuti lain yang tanggalnya tumpang tindih.');
                                return;
                            }

                            if ($leaveType?->deducts_annual_quota) {
                                $remaining = LeaveRequest::remainingCutiFor($user, Carbon::parse($get('start_date'))->year)
                                    + ($record?->deductsAnnualQuota() ? $record->dayCount() : 0);
                                if ($dayCount > $remaining) {
                                    $fail("Sisa jatah cuti {$user->name} tahun ini tinggal {$remaining} hari, tidak cukup untuk {$dayCount} hari.");
                                }
                            }
                        }),

                    Forms\Components\FileUpload::make('document')
                        ->label('Lampiran')
                        ->helperText('Mis. surat keterangan dokter. Wajib atau tidaknya mengikuti aturan jenis yang dipilih (menu Jenis Izin & Cuti).')
                        ->disk('local')
                        ->visibility('private')
                        ->directory('leave-requests')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240),

                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan')
                        ->required()
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('request_number')
                    ->label('No. Pengajuan')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('leaveType.name')
                    ->label('Jenis')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (LeaveRequest $record) => match ($record->type) {
                        'sakit' => 'danger',
                        'cuti' => 'info',
                        default => 'gray',
                    })
                    ->state(fn (LeaveRequest $record) => $record->leaveType?->name ?? ucfirst($record->type)),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Dari')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Sampai')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Hari')
                    ->state(fn (LeaveRequest $record) => $record->dayCount())
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(40)
                    ->tooltip(fn (LeaveRequest $record) => $record->reason),

                Tables\Columns\IconColumn::make('document')
                    ->label('Lampiran')
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('info')
                    ->falseColor('gray')
                    ->state(fn (LeaveRequest $record) => filled($record->document)),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger'  => 'rejected',
                        'gray'    => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state, LeaveRequest $record): string => match ($state) {
                        'pending'   => 'Menunggu Persetujuan',
                        'approved'  => 'Disetujui',
                        'rejected'  => 'Ditolak',
                        'cancelled' => $record->isRevoked() ? 'Dicabut Admin' : 'Dibatalkan Sendiri',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Menunggu Persetujuan',
                        'approved'  => 'Disetujui',
                        'rejected'  => 'Ditolak',
                        'cancelled' => 'Dibatalkan Sendiri',
                    ]),

                Tables\Filters\SelectFilter::make('leave_type_id')
                    ->label('Jenis')
                    ->options(fn () => \App\Models\LeaveType::orderBy('sort_order')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (LeaveRequest $record) => $record->status === 'pending'
                        && (auth()->user()?->canDecideLeaveFor($record) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading(fn (LeaveRequest $record) => "Setujui $record->request_number?")
                    ->form(fn (LeaveRequest $record) => [
                        Forms\Components\Placeholder::make('context')
                            ->label('Yang perlu diperhatikan')
                            ->content(static::approvalContext($record))
                            ->columnSpanFull(),
                    ])
                    ->action(function (LeaveRequest $record) {
                        // Bug diperbaiki 2026-09-27 (audit ulang Izin &
                        // Cuti) -- approveLocked() sekarang lockForUpdate()
                        // + recheck status di dalam DB::transaction(),
                        // lihat komentar lengkap di LeaveRequest model.
                        try {
                            $record = LeaveRequest::approveLocked($record->id, auth()->id());
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menyetujui')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        // Kalau izin ini baru disetujui SETELAH sebagian
                        // tanggalnya sudah kadung ditandai 'alpha' oleh
                        // job harian (attendance:mark-absences) — koreksi
                        // baris itu jadi 'leave', supaya tidak nyangkut
                        // dianggap mangkir padahal izinnya disetujui.
                        \App\Models\Attendance::where('user_id', $record->user_id)
                            ->whereBetween('date', [$record->start_date, $record->end_date])
                            ->where('entry_type', 'alpha')
                            ->update(['entry_type' => 'leave']);

                        app(\App\Services\StaffNotifier::class)->toUsers(
                            [$record->user_id],
                            'Pengajuan Izin Disetujui',
                            "Pengajuan {$record->request_number} ({$record->start_date->format('d M')}–{$record->end_date->format('d M Y')}) disetujui."
                        );

                        // Absensi tanggal lampau yang diubah dari Alpha mengubah potongan gaji; ingatkan bila slip bulan itu sudah dikunci.
                        $locked = \App\Models\Payroll::where('user_id', $record->user_id)
                            ->whereIn('status', ['pending_approval', 'paid'])
                            ->whereDate('period_month', '>=', $record->start_date->copy()->startOfMonth()->toDateString())
                            ->whereDate('period_month', '<=', $record->end_date->copy()->startOfMonth()->toDateString())
                            ->exists();

                        Notification::make()
                            ->title('Izin disetujui')
                            ->body($locked ? 'Perhatian: slip gaji bulan terkait sudah menunggu persetujuan atau dibayar; angka gaji tidak otomatis berubah.' : null)
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record) => $record->status === 'pending'
                        && (auth()->user()?->canDecideLeaveFor($record) ?? false))
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Alasan Ditolak')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (LeaveRequest $record, array $data) {
                        try {
                            $record = LeaveRequest::rejectLocked($record->id, auth()->id(), $data['review_note']);
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menolak')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(\App\Services\StaffNotifier::class)->toUsers(
                            [$record->user_id],
                            'Pengajuan Izin Ditolak',
                            "Pengajuan {$record->request_number} ditolak: {$data['review_note']}"
                        );

                        Notification::make()->title('Izin ditolak')->warning()->send();
                    }),

                // Mencabut persetujuan yang sudah diberikan (salah setuju atau rencana berubah); hanya akses penuh.
                Tables\Actions\Action::make('revoke')
                    ->label('Cabut Persetujuan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (LeaveRequest $record) => (auth()->user()?->isFullAccess() ?? false) && $record->status === 'approved')
                    ->modalHeading(fn (LeaveRequest $record) => "Cabut persetujuan $record->request_number?")
                    ->modalDescription('Jatah cuti dikembalikan. Absensi Izin/Cuti di rentang ini kembali menjadi Alpha untuk tanggal yang sudah lewat, dan dihapus untuk hari ini dan seterusnya. Karyawan diberi tahu.')
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Alasan dicabut')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (LeaveRequest $record, array $data) {
                        try {
                            $record = LeaveRequest::revokeLocked($record->id, auth()->id(), $data['review_note']);
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal mencabut')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(\App\Services\StaffNotifier::class)->toUsers(
                            [$record->user_id],
                            'Persetujuan Izin Dicabut',
                            "Persetujuan {$record->request_number} dicabut: {$data['review_note']}"
                        );

                        Notification::make()->title('Persetujuan dicabut')->warning()->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (LeaveRequest $record) => $record->status === 'pending'),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (LeaveRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Ringkasan untuk penyetuju: alasan, durasi, sisa jatah, lampiran, dan rekan se-toko yang juga cuti di tanggal yang sama. */
    private static function approvalContext(LeaveRequest $record): \Illuminate\Support\HtmlString
    {
        $e = fn (?string $text) => e($text ?? '');
        $lines = [
            '<strong>' . $e($record->user?->name) . '</strong> · ' . $e($record->leaveType?->name ?? ucfirst($record->type)),
            $record->dayCount() . ' hari (' . $record->start_date->translatedFormat('d M Y') . ' – ' . $record->end_date->translatedFormat('d M Y') . ')',
            'Alasan: ' . $e($record->reason),
        ];

        if ($record->deductsAnnualQuota() && $record->user) {
            $year = $record->start_date->year;
            $remaining = max(0, LeaveRequest::annualQuotaFor($record->user, $year) - LeaveRequest::usedCutiDaysFor($record->user, $year, $record->id));
            $lines[] = "Sisa jatah cuti {$year} (sebelum pengajuan ini): {$remaining} hari";
        }

        $link = \App\Support\SensitiveFiles::url($record->document);
        $lines[] = $link ? '<a href="' . e($link) . '" target="_blank" rel="noopener" style="text-decoration:underline">Lihat lampiran</a>' : 'Tanpa lampiran';

        $overlapping = LeaveRequest::with('user:id,name')
            ->where('store_id', $record->store_id)
            ->where('user_id', '!=', $record->user_id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $record->end_date)
            ->where('end_date', '>=', $record->start_date)
            ->get();

        $lines[] = $overlapping->isEmpty()
            ? 'Tidak ada rekan se-toko yang izin/cuti di tanggal ini.'
            : 'Rekan se-toko yang juga izin/cuti di tanggal ini: ' . $overlapping->map(fn (LeaveRequest $o) => $e($o->user?->name) . ($o->status === 'pending' ? ' (menunggu)' : ''))->implode(', ');

        return new \Illuminate\Support\HtmlString(implode('<br>', $lines));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLeaveRequests::route('/'),
            'create' => Pages\CreateLeaveRequest::route('/create'),
            'edit'   => Pages\EditLeaveRequest::route('/{record}/edit'),
        ];
    }
}
