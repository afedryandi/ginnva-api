<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartialLeaveRequestResource\Pages;
use App\Models\PartialLeaveRequest;
use App\Services\PartialLeaveRequestService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pengajuan Izin Sebagian Hari (2026-11-18, keputusan pemilik): izin singkat yang tidak memotong jatah cuti maupun gaji, dalam tiga kategori (Terlambat
 * Masuk Kerja, Keluar Lingkungan Kantor, Pulang Cepat). Diputuskan di sini oleh akses penuh, store manager toko itu, atau pengguna yang diberi aksi Ubah
 * di Hak Akses Detail. Pengajuan dibuat dari aplikasi staf. Aturan di PartialLeaveRequestService.
 */
class PartialLeaveRequestResource extends Resource
{
    protected static ?string $model = PartialLeaveRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Absensi & Cuti';

    protected static ?string $navigationLabel = 'Pengajuan Izin Sebagian Hari';

    protected static ?string $modelLabel = 'Pengajuan Izin Sebagian Hari';

    protected static ?string $pluralModelLabel = 'Pengajuan Izin Sebagian Hari';

    // Setelah Pengajuan Dinas Luar (105), sebelum Laporan Absensi (106).
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

    /** Karyawan biasa hanya melihat pengajuannya sendiri; pemutus melihat seluruh pengajuan tokonya; akses penuh melihat semua. */
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

    private static function canDecide(PartialLeaveRequest $record): bool
    {
        $user = auth()->user();

        return $record->isPending() && $user && app(PartialLeaveRequestService::class)->canDecide($user, $record);
    }

    private static function statusOptions(): array
    {
        return [
            PartialLeaveRequest::STATUS_PENDING => 'Menunggu',
            PartialLeaveRequest::STATUS_APPROVED => 'Disetujui',
            PartialLeaveRequest::STATUS_REJECTED => 'Ditolak',
            PartialLeaveRequest::STATUS_CANCELLED => 'Dibatalkan',
        ];
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
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->formatStateUsing(fn (string $state) => PartialLeaveRequest::CATEGORY_LABELS[$state] ?? $state)
                    ->description(fn (PartialLeaveRequest $record) => $record->windowLabel()),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->limit(60)
                    ->tooltip(fn (PartialLeaveRequest $record) => $record->reason),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        PartialLeaveRequest::STATUS_PENDING => 'warning',
                        PartialLeaveRequest::STATUS_APPROVED => 'success',
                        PartialLeaveRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => static::statusOptions()[$state] ?? $state),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Pemutus langsung melihat antrean yang menunggu; karyawan melihat seluruh riwayatnya.
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->default(fn () => static::isDecider() ? PartialLeaveRequest::STATUS_PENDING : null)
                    ->options(static::statusOptions()),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(PartialLeaveRequest::CATEGORY_LABELS),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->iconButton()
                    ->tooltip('Setujui')
                    ->color('success')
                    ->visible(fn (PartialLeaveRequest $record) => static::canDecide($record))
                    ->modalHeading('Setujui izin sebagian hari?')
                    ->modalDescription(fn (PartialLeaveRequest $record) => $record->affectsTiming()
                        ? 'Telat atau pulang cepat pada jam yang diizinkan tidak dihitung di rekap absensi. Izin ini tidak memotong jatah cuti maupun gaji.'
                        : 'Izin keluar ini hanya tercatat; tidak mengubah hitungan absensi maupun memotong apa pun.')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')->label('Catatan (opsional)')->rows(2),
                    ])
                    ->action(function (PartialLeaveRequest $record, array $data) {
                        try {
                            app(PartialLeaveRequestService::class)->approve($record, auth()->user(), $data['review_notes'] ?: null);

                            Notification::make()->title('Izin sebagian hari disetujui')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menyetujui')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->iconButton()
                    ->tooltip('Tolak')
                    ->color('danger')
                    ->visible(fn (PartialLeaveRequest $record) => static::canDecide($record))
                    ->modalHeading('Tolak izin sebagian hari?')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')->label('Alasan Penolakan')->required()->rows(2),
                    ])
                    ->action(function (PartialLeaveRequest $record, array $data) {
                        try {
                            app(PartialLeaveRequestService::class)->reject($record, auth()->user(), $data['review_notes']);

                            Notification::make()->title('Izin sebagian hari ditolak')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal menolak')->body($e->getMessage())->danger()->send();
                        }
                    }),

                // Pemohon menarik pengajuannya sendiri (menunggu, atau disetujui untuk tanggal yang belum tiba).
                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->iconButton()
                    ->tooltip('Batalkan')
                    ->color('gray')
                    ->visible(fn (PartialLeaveRequest $record) => $record->user_id === auth()->id() && app(PartialLeaveRequestService::class)->isCancellable($record))
                    ->requiresConfirmation()
                    ->modalDescription('Pengajuan ini ditarik kembali dan tidak berlaku lagi.')
                    ->action(function (PartialLeaveRequest $record) {
                        try {
                            app(PartialLeaveRequestService::class)->cancel($record, auth()->id());

                            Notification::make()->title('Pengajuan dibatalkan')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Gagal membatalkan')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\ViewAction::make()->iconButton()->tooltip('Lihat detail'),
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
            ->where('status', PartialLeaveRequest::STATUS_PENDING)
            ->where('user_id', '!=', $user->id)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartialLeaveRequests::route('/'),
            'view' => Pages\ViewPartialLeaveRequest::route('/{record}'),
        ];
    }
}
