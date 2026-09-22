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

    protected static ?string $navigationLabel = 'Koreksi Absensi';

    protected static ?string $modelLabel = 'Permintaan Koreksi Absensi';

    protected static ?string $pluralModelLabel = 'Koreksi Absensi';

    protected static ?int $navigationSort = 31;

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
     * Staff biasa cuma lihat permintaan MEREKA SENDIRI (transparansi
     * status, tanpa bisa lihat/approve punya orang lain). store_manager
     * lihat seluruh permintaan TOKONYA. full-access lihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
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
                    ->label('Diajukan Oleh'),

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
                        default => $state,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        AttendanceCorrectionRequest::STATUS_PENDING => 'Menunggu',
                        AttendanceCorrectionRequest::STATUS_APPROVED => 'Disetujui',
                        AttendanceCorrectionRequest::STATUS_REJECTED => 'Ditolak',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AttendanceCorrectionRequest $record) => $record->isPending()
                        && (auth()->user()?->isFullAccess() || auth()->user()?->isStoreManager()))
                    ->requiresConfirmation()
                    ->form([
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
                        && (auth()->user()?->isFullAccess() || auth()->user()?->isStoreManager()))
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

                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceCorrectionRequests::route('/'),
            'view' => Pages\ViewAttendanceCorrectionRequest::route('/{record}'),
        ];
    }
}
