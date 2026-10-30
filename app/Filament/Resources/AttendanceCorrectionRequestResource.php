<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceCorrectionRequestResource\Pages;
use App\Models\AttendanceCorrectionRequest;
use App\Services\AttendanceCorrectionService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Alur approval utk absensi anomali" (audit Majoo, f24), dibangun
 * 2026-09-22 atas keputusan user — antrean permintaan koreksi/entri
 * baru absensi dari staff non-manager, approve/reject oleh
 * store_manager (toko sendiri) / full-access (semua toko). Lihat
 * AttendanceCorrectionService untuk logika approve/reject.
 */
class AttendanceCorrectionRequestResource extends Resource
{
    protected static ?string $model = AttendanceCorrectionRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 100-199 "Absensi".
    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Koreksi Absensi';

    protected static ?string $modelLabel = 'Permintaan Koreksi Absensi';

    protected static ?string $pluralModelLabel = 'Koreksi Absensi';

    protected static ?int $navigationSort = 101;

    private static function accessGate(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'view', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Staff biasa cuma lihat permintaan MEREKA SENDIRI (transparansi
     * status, tanpa bisa lihat/approve punya orang lain). store_manager
     * lihat seluruh permintaan TOKONYA. full-access lihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        // ->with(['user', 'requestedBy']) ditambah 2026-09-27 (audit
        // Koreksi Absensi) -- kolom dot-notation 'user.name'/
        // 'requestedBy.name' di table() sebelumnya N+1 per baris (resource
        // ini dibangun 2026-09-22, setelah sweep N+1 2026-09-14, terlewat).
        $query = parent::getEloquentQuery()->with(['user', 'requestedBy']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            if ($user->isStoreManager()) {
                $query->where('store_id', $user->store_id);
            } else {
                $query->where('requested_by', $user->id);
            }
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal Absensi')
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('entry_type')
                    ->label('Jenis')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'manual' => 'Manual',
                        'field_duty' => 'Dinas Luar',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('clock_in_at')
                    ->label('Jam Masuk')
                    ->dateTime('H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('clock_out_at')
                    ->label('Jam Keluar')
                    ->dateTime('H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(40),

                Tables\Columns\TextColumn::make('requestedBy.name')
                    ->label('Diajukan Oleh')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        AttendanceCorrectionRequest::STATUS_PENDING => 'warning',
                        AttendanceCorrectionRequest::STATUS_APPROVED => 'success',
                        AttendanceCorrectionRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        AttendanceCorrectionRequest::STATUS_PENDING => 'Menunggu',
                        AttendanceCorrectionRequest::STATUS_APPROVED => 'Disetujui',
                        AttendanceCorrectionRequest::STATUS_REJECTED => 'Ditolak',
                        AttendanceCorrectionRequest::STATUS_CANCELLED => 'Dibatalkan',
                        default => $state,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Penyetuju membuka daftar langsung pada antrean yang menunggu; pengaju tetap melihat seluruh riwayatnya.
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->default(fn () => (auth()->user()?->isFullAccess() || auth()->user()?->isStoreManager()) ? AttendanceCorrectionRequest::STATUS_PENDING : null)
                    ->options([
                        AttendanceCorrectionRequest::STATUS_PENDING => 'Menunggu',
                        AttendanceCorrectionRequest::STATUS_APPROVED => 'Disetujui',
                        AttendanceCorrectionRequest::STATUS_REJECTED => 'Ditolak',
                        AttendanceCorrectionRequest::STATUS_CANCELLED => 'Dibatalkan',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AttendanceCorrectionRequest $record) => $record->isPending()
                        && (auth()->user()?->isFullAccess() || auth()->user()?->isStoreManager())
                        // Bug diperbaiki 2026-09-29 (audit Absensi) -- guard UI, penegakan
                        // sebenarnya ada di AttendanceCorrectionService (dalam lock).
                        && $record->requested_by !== auth()->id())
                    ->requiresConfirmation()
                    // Gap ditutup 2026-09-27 (audit Koreksi Absensi) --
                    // SEBELUMNYA admin approve "buta": form cuma tampilkan
                    // field permintaan BARU, tidak ada info baris Attendance
                    // yang SUDAH ADA untuk tanggal itu (bisa 'leave'/cuti
                    // resmi, 'alpha', dst) yang akan DITIMPA jadi 'manual'
                    // begitu disetujui (lihat AttendanceCorrectionService::
                    // approve(), updateOrCreate()). Placeholder ini kasih
                    // konteks itu SEBELUM admin klik Setujui.
                    ->form(fn (AttendanceCorrectionRequest $record) => [
                        Forms\Components\Placeholder::make('existing_attendance_info')
                            ->label('Data Absensi Saat Ini (tanggal ini)')
                            ->content(function () use ($record) {
                                $existing = \App\Models\Attendance::where('user_id', $record->user_id)
                                    ->where('date', $record->date->toDateString())
                                    ->first();

                                $payrollNote = static::payrollNote($record);

                                if (! $existing) {
                                    return 'Belum ada baris absensi untuk tanggal ini — koreksi ini akan MEMBUAT baris baru.' . $payrollNote;
                                }

                                $label = match ($existing->entry_type) {
                                    'clock' => 'Normal (App)',
                                    'leave' => 'Izin/Cuti (resmi disetujui)',
                                    'alpha' => 'Alpha (Tidak Ada Keterangan)',
                                    'manual' => 'Manual',
                                    'field_duty' => 'Dinas Luar',
                                    default => $existing->entry_type,
                                };

                                return "Jenis saat ini: {$label}. Menyetujui koreksi ini akan MENIMPA baris tersebut jadi \"Manual\" sesuai jam yang diajukan." . $payrollNote;
                            })
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('review_notes')
                            ->label('Catatan (opsional)')
                            ->rows(2),
                    ])
                    ->action(function (AttendanceCorrectionRequest $record, array $data) {
                        try {
                            app(AttendanceCorrectionService::class)->approve($record, auth()->id(), $data['review_notes'] ?: null);

                            Notification::make()->title('Koreksi disetujui, data absensi diperbarui')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menyetujui')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AttendanceCorrectionRequest $record) => $record->isPending()
                        && (auth()->user()?->isFullAccess() || auth()->user()?->isStoreManager())
                        // Bug diperbaiki 2026-09-29 (audit Absensi) -- guard UI, penegakan
                        // sebenarnya ada di AttendanceCorrectionService (dalam lock).
                        && $record->requested_by !== auth()->id())
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('review_notes')
                            ->label('Alasan Penolakan')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (AttendanceCorrectionRequest $record, array $data) {
                        try {
                            app(AttendanceCorrectionService::class)->reject($record, auth()->id(), $data['review_notes']);

                            Notification::make()->title('Koreksi ditolak')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menolak')->body($e->getMessage())->danger()->send();
                        }
                    }),

                // Pengaju menarik kembali pengajuannya sendiri selama masih menunggu.
                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (AttendanceCorrectionRequest $record) => $record->isPending() && $record->requested_by === auth()->id())
                    ->requiresConfirmation()
                    ->modalDescription('Pengajuan ini ditarik kembali dan tidak akan diputuskan siapa pun. Anda bisa mengajukan lagi untuk tanggal yang sama.')
                    ->action(function (AttendanceCorrectionRequest $record) {
                        try {
                            app(AttendanceCorrectionService::class)->cancel($record, auth()->id());

                            Notification::make()->title('Pengajuan dibatalkan')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal membatalkan')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),
            ]);
    }

    /** Peringatan bila penggajian bulan pengajuan sudah dikunci atau berupa draf yang perlu digenerate ulang. */
    private static function payrollNote(AttendanceCorrectionRequest $request): string
    {
        $payroll = \App\Models\Payroll::where('user_id', $request->user_id)
            ->whereDate('period_month', $request->date->copy()->startOfMonth()->toDateString())
            ->first();

        if (! $payroll) {
            return '';
        }

        $month = $request->date->translatedFormat('F Y');

        return $payroll->status === 'draft'
            ? " Catatan: slip gaji {$month} masih draf; generate ulang supaya angkanya ikut berubah."
            : " PERHATIAN: slip gaji {$month} sudah {$payroll->status_label}; angka gaji TIDAK otomatis berubah oleh koreksi ini.";
    }

    /** Jumlah pengajuan orang lain yang menunggu keputusan; hanya untuk penyetuju. */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user || ! ($user->isFullAccess() || $user->isStoreManager())) {
            return null;
        }

        $count = static::getEloquentQuery()
            ->where('status', AttendanceCorrectionRequest::STATUS_PENDING)
            ->where('requested_by', '!=', $user->id)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceCorrectionRequests::route('/'),
            'view' => Pages\ViewAttendanceCorrectionRequest::route('/{record}'),
        ];
    }
}
