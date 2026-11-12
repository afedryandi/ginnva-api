<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FieldDutyRequestResource\Pages;
use App\Models\FieldDutyRequest;
use App\Services\FieldDutyRequestService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pengajuan Dinas Luar (2026-11-12, keputusan pemilik): antrean pengajuan karyawan yang bekerja di luar toko (mis. kanvasing), sehari penuh atau sebagian hari dengan rentang jam.
 * Boleh diajukan susulan. Diputuskan di sini oleh akses penuh, store manager toko itu, atau pengguna yang diberi aksi Ubah di Hak Akses Detail;
 * setelah disetujui, absensi hari itu tercatat sebagai Dinas Luar. Pengajuan dibuat dari aplikasi staf. Aturan di FieldDutyRequestService.
 */
class FieldDutyRequestResource extends Resource
{
    protected static ?string $model = FieldDutyRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Pengajuan Dinas Luar';

    protected static ?string $modelLabel = 'Pengajuan Dinas Luar';

    protected static ?string $pluralModelLabel = 'Pengajuan Dinas Luar';

    // Setelah Pengajuan Lembur (104), sebelum Laporan Absensi (106).
    protected static ?int $navigationSort = 105;

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
        return static::accessGate();
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

    /** Pengguna yang boleh memutuskan pengajuan toko: akses penuh, store manager, atau yang diberi aksi Ubah di menu ini. */
    private static function isDecider(): bool
    {
        $user = auth()->user();

        return $user && ($user->isFullAccess() || $user->isStoreManager() || $user->hasModuleAction(static::class, 'update', false));
    }

    /**
     * Karyawan biasa hanya melihat pengajuannya sendiri; pemutus (store manager atau yang diberi hak) melihat seluruh pengajuan tokonya;
     * akses penuh melihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'store', 'reviewedBy']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            static::isDecider()
                ? $query->where('store_id', $user->store_id)
                : $query->where('user_id', $user->id);
        }

        return $query;
    }

    private static function canDecide(FieldDutyRequest $record): bool
    {
        $user = auth()->user();

        return $record->isPending() && $user && app(FieldDutyRequestService::class)->canDecide($user, $record);
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

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal Dinas Luar')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Jam')
                    ->getStateUsing(fn (FieldDutyRequest $record) => $record->windowLabel()),

                Tables\Columns\TextColumn::make('description')
                    ->label('Tujuan / Pekerjaan')
                    ->limit(60)
                    ->tooltip(fn (FieldDutyRequest $record) => $record->description),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        FieldDutyRequest::STATUS_PENDING => 'warning',
                        FieldDutyRequest::STATUS_APPROVED => 'success',
                        FieldDutyRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        FieldDutyRequest::STATUS_PENDING => 'Menunggu',
                        FieldDutyRequest::STATUS_APPROVED => 'Disetujui',
                        FieldDutyRequest::STATUS_REJECTED => 'Ditolak',
                        FieldDutyRequest::STATUS_CANCELLED => 'Dibatalkan',
                        default => $state,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Pemutus langsung melihat antrean yang menunggu; karyawan melihat seluruh riwayatnya.
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->default(fn () => static::isDecider() ? FieldDutyRequest::STATUS_PENDING : null)
                    ->options([
                        FieldDutyRequest::STATUS_PENDING => 'Menunggu',
                        FieldDutyRequest::STATUS_APPROVED => 'Disetujui',
                        FieldDutyRequest::STATUS_REJECTED => 'Ditolak',
                        FieldDutyRequest::STATUS_CANCELLED => 'Dibatalkan',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FieldDutyRequest $record) => static::canDecide($record))
                    ->modalHeading('Setujui dinas luar?')
                    ->modalDescription(fn (FieldDutyRequest $record) => $record->isPartialDay()
                        ? 'Telat dan pulang cepat pada rentang jam ini tidak dihitung; absen masuk dan keluar di toko tetap wajib. Bila rentang berlangsung sampai jam tutup toko, absen keluar yang kosong ditutup otomatis. Jam boleh Anda ubah sesuai kenyataan sebelum menyetujui.'
                        : 'Absensi karyawan pada tanggal itu akan tercatat sebagai Dinas Luar (tanpa telat atau pulang cepat). Hari yang sudah ditandai Alpha diganti.')
                    ->fillForm(fn (FieldDutyRequest $record) => [
                        'start_time' => $record->isPartialDay() ? substr($record->start_time, 0, 5) : null,
                        'end_time' => $record->isPartialDay() ? substr($record->end_time, 0, 5) : null,
                    ])
                    ->form(fn (FieldDutyRequest $record) => array_filter([
                        $record->isPartialDay() ? Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TimePicker::make('start_time')->label('Jam Mulai')->seconds(false)->required(),
                            Forms\Components\TimePicker::make('end_time')->label('Jam Selesai')->seconds(false)->required(),
                        ]) : null,
                        Forms\Components\Textarea::make('review_notes')->label('Catatan (opsional)')->rows(2),
                    ]))
                    ->action(function (FieldDutyRequest $record, array $data) {
                        try {
                            app(FieldDutyRequestService::class)->approve(
                                $record,
                                auth()->user(),
                                $data['review_notes'] ?: null,
                                $record->isPartialDay() ? ['start_time' => $data['start_time'] ?? null, 'end_time' => $data['end_time'] ?? null] : null,
                            );

                            Notification::make()->title('Dinas luar disetujui')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menyetujui')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (FieldDutyRequest $record) => static::canDecide($record))
                    ->modalHeading('Tolak dinas luar?')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')->label('Alasan Penolakan')->required()->rows(2),
                    ])
                    ->action(function (FieldDutyRequest $record, array $data) {
                        try {
                            app(FieldDutyRequestService::class)->reject($record, auth()->user(), $data['review_notes']);

                            Notification::make()->title('Dinas luar ditolak')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menolak')->body($e->getMessage())->danger()->send();
                        }
                    }),

                // Pemohon menarik pengajuannya sendiri (menunggu, atau disetujui untuk tanggal yang belum tiba).
                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (FieldDutyRequest $record) => $record->user_id === auth()->id() && app(FieldDutyRequestService::class)->isCancellable($record))
                    ->requiresConfirmation()
                    ->modalDescription('Pengajuan ini ditarik kembali dan tidak berlaku lagi.')
                    ->action(function (FieldDutyRequest $record) {
                        try {
                            app(FieldDutyRequestService::class)->cancel($record, auth()->id());

                            Notification::make()->title('Pengajuan dibatalkan')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal membatalkan')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),
            ]);
    }

    /** Jumlah pengajuan orang lain yang menunggu keputusan; hanya untuk pemutus. */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user || ! static::isDecider()) {
            return null;
        }

        $count = static::getEloquentQuery()
            ->where('status', FieldDutyRequest::STATUS_PENDING)
            ->where('user_id', '!=', $user->id)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFieldDutyRequests::route('/'),
            'view' => Pages\ViewFieldDutyRequest::route('/{record}'),
        ];
    }
}
