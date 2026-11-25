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
                    ->weight('semibold')
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

                // Foto bukti di lokasi: tanda centang kalau sudah dikirim, tanda silang bila disetujui tetapi harinya tiba dan belum ada foto.
                Tables\Columns\IconColumn::make('location_photo')
                    ->label('Foto Awal')
                    ->state(fn (FieldDutyRequest $record) => $record->status !== FieldDutyRequest::STATUS_APPROVED ? null : (filled($record->location_photo) ? true : ($record->needsLocationPhoto() ? false : null)))
                    ->boolean()
                    ->tooltip(fn (FieldDutyRequest $record) => $record->location_photo_at
                        ? 'Dikirim ' . $record->location_photo_at->translatedFormat('d M Y H:i')
                            . ($record->location_accuracy !== null ? ' · akurasi GPS ±' . round($record->location_accuracy) . ' m' : ($record->location_photo ? ' · tanpa koordinat' : ''))
                        : null),

                // Foto akhir (saat selesai dinas): sama seperti foto awal, tetapi hanya dinilai setelah foto awal ada.
                Tables\Columns\IconColumn::make('end_photo')
                    ->label('Foto Akhir')
                    ->state(fn (FieldDutyRequest $record) => $record->status !== FieldDutyRequest::STATUS_APPROVED ? null : (filled($record->end_photo) ? true : ($record->needsEndPhoto() ? false : null)))
                    ->boolean()
                    ->tooltip(fn (FieldDutyRequest $record) => $record->end_photo_at
                        ? 'Dikirim ' . $record->end_photo_at->translatedFormat('d M Y H:i')
                            . ($record->end_accuracy !== null ? ' · akurasi GPS ±' . round($record->end_accuracy) . ' m' : ($record->end_photo ? ' · tanpa koordinat' : ''))
                        : null),

                // Jam foto dikirim, terlihat langsung (bukan hanya tooltip) dan tidak bergantung pada status pengajuan.
                Tables\Columns\TextColumn::make('location_photo_at')
                    ->label('Jam Foto Awal')
                    ->dateTime('d M H:i')
                    ->placeholder('Belum')
                    ->description(fn (FieldDutyRequest $record) => $record->location_photo_at && $record->location_accuracy !== null ? 'GPS ±' . round($record->location_accuracy) . ' m' : null)
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('end_photo_at')
                    ->label('Jam Foto Akhir')
                    ->dateTime('d M H:i')
                    ->placeholder('Belum')
                    ->description(fn (FieldDutyRequest $record) => $record->end_photo_at && $record->end_accuracy !== null ? 'GPS ±' . round($record->end_accuracy) . ' m' : null)
                    ->sortable()
                    ->toggleable(),

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
                Tables\Filters\Filter::make('tanpa_foto')
                    ->label('Disetujui, foto awal/akhir belum lengkap')
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', FieldDutyRequest::STATUS_APPROVED)->whereDate('date', '<=', today())->where(fn ($q) => $q->whereNull('location_photo')->orWhereNull('end_photo'))),

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
                // Karyawan tanpa aplikasi (mis. iPhone) mengirim foto di lokasi dari panel; halaman ini memakai layanan yang sama dengan aplikasi.
                Tables\Actions\Action::make('kirim_foto')
                    ->label(fn (FieldDutyRequest $record) => $record->needsLocationPhoto() ? 'Absen Masuk Lokasi' : 'Absen Keluar Lokasi')
                    ->icon('heroicon-o-camera')
                    ->color('warning')
                    ->visible(fn (FieldDutyRequest $record) => $record->user_id === auth()->id() && ($record->needsLocationPhoto() || $record->needsEndPhoto()) && $record->date->isToday())
                    ->url(fn () => \App\Filament\Pages\FieldDutyPhoto::getUrl()),

                Tables\Actions\Action::make('lihat_foto')
                    ->label('Foto Awal')
                    ->icon('heroicon-o-photo')
                    ->iconButton()
                    ->tooltip(fn (FieldDutyRequest $record) => 'Foto Awal' . ($record->location_photo_at ? ' · dikirim ' . $record->location_photo_at->translatedFormat('d M Y H:i') : ''))
                    ->color('gray')
                    ->visible(fn (FieldDutyRequest $record) => filled($record->location_photo))
                    ->url(fn (FieldDutyRequest $record) => \App\Support\SensitiveFiles::url($record->location_photo))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('lihat_foto_akhir')
                    ->label('Foto Akhir')
                    ->icon('heroicon-o-photo')
                    ->iconButton()
                    ->tooltip(fn (FieldDutyRequest $record) => 'Foto Akhir' . ($record->end_photo_at ? ' · dikirim ' . $record->end_photo_at->translatedFormat('d M Y H:i') : ''))
                    ->color('gray')
                    ->visible(fn (FieldDutyRequest $record) => filled($record->end_photo))
                    ->url(fn (FieldDutyRequest $record) => \App\Support\SensitiveFiles::url($record->end_photo))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('mapsEnd')
                    ->label('Lokasi Akhir di Peta')
                    ->icon('heroicon-o-map-pin')
                    ->iconButton()
                    ->tooltip('Lokasi akhir di Google Maps')
                    ->color('gray')
                    ->visible(fn (FieldDutyRequest $record) => $record->endMapsUrl() !== null)
                    ->url(fn (FieldDutyRequest $record) => $record->endMapsUrl())
                    ->openUrlInNewTab(),

                // Titik koordinat saat foto dikirim (terbaca otomatis dari GPS ponsel): membuka Google Maps. Tidak ada untuk foto lama atau dari panel.
                Tables\Actions\Action::make('mapsLocation')
                    ->label('Lokasi di Peta')
                    ->icon('heroicon-o-map-pin')
                    ->iconButton()
                    ->tooltip('Lokasi di Google Maps')
                    ->color('gray')
                    ->visible(fn (FieldDutyRequest $record) => $record->mapsUrl() !== null)
                    ->url(fn (FieldDutyRequest $record) => $record->mapsUrl())
                    ->openUrlInNewTab(),

                // Dinas sehari penuh yang dibatalkan (foto tidak lengkap) bisa dipulihkan atasan, mis. karena karyawan lupa foto akhir tetapi memang bekerja di luar.
                Tables\Actions\Action::make('pulihkan')
                    ->label('Pulihkan Kehadiran')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(function (FieldDutyRequest $record) {
                        $user = auth()->user();
                        $service = app(FieldDutyRequestService::class);

                        return $user && $service->canDecide($user, $record) && $service->needsRestore($record);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Pulihkan kehadiran dinas luar?')
                    ->modalDescription('Hari itu kembali tercatat Dinas Luar (menggantikan Alpha). Pastikan karyawan memang bekerja di luar pada hari itu.')
                    ->action(function (FieldDutyRequest $record) {
                        try {
                            app(FieldDutyRequestService::class)->restoreByManager($record, auth()->user());
                        } catch (\RuntimeException $e) {
                            \Filament\Notifications\Notification::make()->title('Tidak bisa dipulihkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        \Filament\Notifications\Notification::make()->title('Kehadiran dinas luar dipulihkan')->success()->send();
                    }),

                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->iconButton()
                    ->tooltip('Setujui')
                    ->color('success')
                    ->visible(fn (FieldDutyRequest $record) => static::canDecide($record))
                    ->modalHeading('Setujui dinas luar?')
                    ->modalDescription(fn (FieldDutyRequest $record) => $record->isPartialDay()
                        ? 'Telat dan pulang cepat di rentang jam ini tidak dihitung; absen masuk dan keluar tetap wajib. Jam boleh diubah sesuai kenyataan sebelum menyetujui.'
                        : 'Absensi karyawan pada tanggal itu akan tercatat sebagai Dinas Luar (tanpa telat atau pulang cepat). Hari yang sudah ditandai Alpha diganti. Karyawan wajib mengirim dua foto pada hari dinas (foto awal saat tiba dan foto akhir saat selesai); tanpa foto yang lengkap, kehadiran dinasnya dibatalkan dan hari itu menjadi Alpha.')
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
                    ->iconButton()
                    ->tooltip('Tolak')
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
                    ->iconButton()
                    ->tooltip('Batalkan')
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
