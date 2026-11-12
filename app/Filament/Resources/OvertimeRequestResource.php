<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OvertimeRequestResource\Pages;
use App\Models\OvertimeRequest;
use App\Services\OvertimeRequestService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pengajuan Lembur (2026-11-12, keputusan pemilik): antrean pengajuan lembur dari karyawan. Lembur wajib diajukan sebelum dimulai, dengan pekerjaan
 * yang jelas, dan disetujui di sini oleh store manager toko itu atau akses penuh. Hanya jam yang disetujui yang diakui sebagai lembur. Pengajuan
 * dibuat dari aplikasi staf; panel hanya untuk memutuskan dan melihat. Aturan lengkap di OvertimeRequestService.
 */
class OvertimeRequestResource extends Resource
{
    protected static ?string $model = OvertimeRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Pengajuan Lembur';

    protected static ?string $modelLabel = 'Pengajuan Lembur';

    protected static ?string $pluralModelLabel = 'Pengajuan Lembur';

    // Setelah Izin & Cuti (103), sebelum Laporan Absensi (105).
    protected static ?int $navigationSort = 104;

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

    /**
     * Karyawan biasa hanya melihat pengajuannya sendiri; store manager melihat seluruh pengajuan tokonya; akses penuh melihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'store', 'booking', 'reviewedBy']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $user->isStoreManager()
                ? $query->where('store_id', $user->store_id)
                : $query->where('user_id', $user->id);
        }

        return $query;
    }

    private static function canDecide(OvertimeRequest $record): bool
    {
        $user = auth()->user();

        return $record->isPending()
            && $record->user_id !== $user?->id
            && ($user?->isFullAccess() || ($user?->isStoreManager() && (int) $user->store_id === (int) $record->store_id));
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
                    ->label('Tanggal Lembur')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Jam')
                    ->formatStateUsing(fn (OvertimeRequest $record) => $record->starts_at->format('H:i') . '–' . $record->ends_at->format('H:i')),

                Tables\Columns\TextColumn::make('minutes')
                    ->label('Durasi')
                    ->formatStateUsing(fn (int $state) => rtrim(rtrim(number_format($state / 60, 1, ',', ''), '0'), ',') . ' jam'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Pekerjaan')
                    ->getStateUsing(fn (OvertimeRequest $record) => $record->workLabel())
                    ->limit(50)
                    ->tooltip(fn (OvertimeRequest $record) => $record->workLabel()),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        OvertimeRequest::STATUS_PENDING => 'warning',
                        OvertimeRequest::STATUS_APPROVED => 'success',
                        OvertimeRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        OvertimeRequest::STATUS_PENDING => 'Menunggu',
                        OvertimeRequest::STATUS_APPROVED => 'Disetujui',
                        OvertimeRequest::STATUS_REJECTED => 'Ditolak',
                        OvertimeRequest::STATUS_CANCELLED => 'Dibatalkan',
                        default => $state,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Pemutus langsung melihat antrean yang menunggu; karyawan melihat seluruh riwayatnya.
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->default(fn () => (auth()->user()?->isFullAccess() || auth()->user()?->isStoreManager()) ? OvertimeRequest::STATUS_PENDING : null)
                    ->options([
                        OvertimeRequest::STATUS_PENDING => 'Menunggu',
                        OvertimeRequest::STATUS_APPROVED => 'Disetujui',
                        OvertimeRequest::STATUS_REJECTED => 'Ditolak',
                        OvertimeRequest::STATUS_CANCELLED => 'Dibatalkan',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (OvertimeRequest $record) => static::canDecide($record))
                    ->modalHeading('Setujui lembur?')
                    ->modalDescription('Hanya jam yang disetujui ini yang diakui sebagai lembur. Jam kerja di luar jam resmi tanpa persetujuan tidak diakui.')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')->label('Catatan (opsional)')->rows(2),
                    ])
                    ->action(function (OvertimeRequest $record, array $data) {
                        try {
                            app(OvertimeRequestService::class)->approve($record, auth()->user(), $data['review_notes'] ?: null);

                            Notification::make()->title('Lembur disetujui')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menyetujui')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OvertimeRequest $record) => static::canDecide($record))
                    ->modalHeading('Tolak lembur?')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')->label('Alasan Penolakan')->required()->rows(2),
                    ])
                    ->action(function (OvertimeRequest $record, array $data) {
                        try {
                            app(OvertimeRequestService::class)->reject($record, auth()->user(), $data['review_notes']);

                            Notification::make()->title('Lembur ditolak')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menolak')->body($e->getMessage())->danger()->send();
                        }
                    }),

                // Pemohon menarik pengajuannya sendiri (menunggu, atau disetujui tetapi belum dimulai).
                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (OvertimeRequest $record) => $record->user_id === auth()->id()
                        && ($record->isPending() || ($record->isApproved() && $record->starts_at->greaterThan(now()))))
                    ->requiresConfirmation()
                    ->modalDescription('Pengajuan ini ditarik kembali dan tidak berlaku lagi.')
                    ->action(function (OvertimeRequest $record) {
                        try {
                            app(OvertimeRequestService::class)->cancel($record, auth()->id());

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

        if (! $user || ! ($user->isFullAccess() || $user->isStoreManager())) {
            return null;
        }

        $count = static::getEloquentQuery()
            ->where('status', OvertimeRequest::STATUS_PENDING)
            ->where('user_id', '!=', $user->id)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOvertimeRequests::route('/'),
            'view' => Pages\ViewOvertimeRequest::route('/{record}'),
        ];
    }
}
