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

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $navigationLabel = 'Izin & Cuti';

    protected static ?string $modelLabel = 'Izin/Cuti';

    protected static ?string $pluralModelLabel = 'Izin & Cuti';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
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
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->live()
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

                    Forms\Components\Select::make('type')
                        ->label('Jenis')
                        ->options([
                            'izin' => 'Izin',
                            'sakit' => 'Sakit',
                            'cuti' => 'Cuti',
                        ])
                        ->required()
                        ->live()
                        // Cuma 'cuti' yang potong jatah tahunan (Izin/Sakit
                        // tidak) — lihat LeaveRequest::annualQuotaFor().
                        ->helperText(function (Forms\Get $get, ?LeaveRequest $record) {
                            if ($get('type') !== 'cuti') return null;
                            $userId = $get('user_id');
                            if (! $userId) return null;
                            $user = User::find($userId);
                            if (! $user) return null;
                            $remaining = LeaveRequest::remainingCutiFor($user, today()->year) + ($record?->type === 'cuti' ? $record->dayCount() : 0);
                            return "Sisa jatah cuti {$user->name} tahun ini: {$remaining} hari.";
                        }),

                    Forms\Components\DatePicker::make('start_date')
                        ->label('Dari Tanggal')
                        ->required()
                        ->live()
                        // Bukan minDate keras untuk EDIT (baris lama bisa
                        // saja dari tanggal yang sekarang sudah lewat, mis.
                        // dicatat admin belakangan) — cuma dipaksa untuk
                        // pengajuan BARU lewat CreateLeaveRequest.
                        ->minDate(fn (?LeaveRequest $record) => $record ? null : today()),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('Sampai Tanggal')
                        ->required()
                        ->minDate(fn (Forms\Get $get) => $get('start_date'))
                        ->rule(fn (Forms\Get $get, ?LeaveRequest $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            if (! $value || ! $get('start_date') || ! $get('user_id')) return;

                            $dayCount = Carbon::parse($get('start_date'))->diffInDays(Carbon::parse($value)) + 1;
                            if ($dayCount > LeaveRequest::MAX_DURATION_DAYS) {
                                $fail('Durasi pengajuan maksimal ' . LeaveRequest::MAX_DURATION_DAYS . ' hari.');
                                return;
                            }

                            $user = User::find($get('user_id'));
                            if (! $user) return;

                            if (LeaveRequest::hasOverlap($user, $get('start_date'), $value, $record?->id)) {
                                $fail('Karyawan ini sudah punya pengajuan izin/cuti lain yang tanggalnya tumpang tindih.');
                                return;
                            }

                            if ($get('type') === 'cuti') {
                                $remaining = LeaveRequest::remainingCutiFor($user, Carbon::parse($get('start_date'))->year)
                                    + ($record?->type === 'cuti' ? $record->dayCount() : 0);
                                if ($dayCount > $remaining) {
                                    $fail("Sisa jatah cuti {$user->name} tahun ini tinggal {$remaining} hari, tidak cukup untuk {$dayCount} hari.");
                                }
                            }
                        }),

                    Forms\Components\FileUpload::make('document')
                        ->label('Lampiran (opsional)')
                        ->helperText('Mis. surat keterangan dokter untuk Sakit — belum wajib, tapi disarankan untuk Sakit lebih dari 1 hari.')
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

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'gray'    => 'izin',
                        'danger'  => 'sakit',
                        'info'    => 'cuti',
                    ])
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Dari')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('Sampai')
                    ->date('d M Y'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger'  => 'rejected',
                        'gray'    => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => 'Menunggu Persetujuan',
                        'approved'  => 'Disetujui',
                        'rejected'  => 'Ditolak',
                        'cancelled' => 'Dibatalkan Sendiri',
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

                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options([
                        'izin' => 'Izin',
                        'sakit' => 'Sakit',
                        'cuti' => 'Cuti',
                    ]),

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
                    ->visible(fn (LeaveRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (LeaveRequest $record) {
                        $record->update([
                            'status'      => 'approved',
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        // Kalau izin ini baru disetujui SETELAH sebagian
                        // tanggalnya sudah kadung ditandai 'alpha' oleh
                        // job harian (attendance:mark-absences) — koreksi
                        // baris itu jadi 'leave', supaya tidak nyangkut
                        // dianggap mangkir padahal izinnya disetujui.
                        \App\Models\Attendance::where('user_id', $record->user_id)
                            ->whereBetween('date', [$record->start_date, $record->end_date])
                            ->where('entry_type', 'alpha')
                            ->update(['entry_type' => 'leave']);

                        app(PushNotificationService::class)->sendToUsers(
                            [$record->user_id],
                            'Pengajuan Izin Disetujui',
                            "Pengajuan {$record->request_number} ({$record->start_date->format('d M')}–{$record->end_date->format('d M Y')}) disetujui."
                        );

                        Notification::make()->title('Izin disetujui')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Alasan Ditolak')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (LeaveRequest $record, array $data) {
                        $record->update([
                            'status'      => 'rejected',
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                            'review_note' => $data['review_note'],
                        ]);

                        app(PushNotificationService::class)->sendToUsers(
                            [$record->user_id],
                            'Pengajuan Izin Ditolak',
                            "Pengajuan {$record->request_number} ditolak: {$data['review_note']}"
                        );

                        Notification::make()->title('Izin ditolak')->warning()->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (LeaveRequest $record) => $record->status === 'pending'),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (LeaveRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending'),
            ])
            ->defaultSort('created_at', 'desc');
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