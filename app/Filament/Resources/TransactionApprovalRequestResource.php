<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionApprovalRequestResource\Pages;
use App\Models\TransactionApprovalRequest;
use App\Services\TransactionApprovalService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Audit framework 2026-09-14, "Segregation of duties finansial" --
 * antrean persetujuan untuk "Proses Referral"/"Proses Refund"/"DP" yang
 * diajukan staff non-full-access dari BookingResource. KEPUTUSAN approve/
 * reject TERBATAS full-access (super_admin/direksi), SENGAJA tidak bisa
 * didelegasikan ke store_manager, supaya pemisahan tugas (yang input ≠
 * yang menyetujui) benar-benar tertegak.
 *
 * Sejak 2026-09-29 (gap audit Persetujuan Transaksi): staff & store manager
 * BOLEH MELIHAT pengajuan (miliknya sendiri / tokonya) untuk melacak status
 * dan membatalkan/mengajukan ulang -- tapi tombol Setujui/Tolak tetap hanya
 * full-access (visible() + otorisasi di service).
 */
class TransactionApprovalRequestResource extends Resource
{
    protected static ?string $model = TransactionApprovalRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 400-499 "Approval".
    protected static ?string $navigationGroup = 'Approval';

    protected static ?int $navigationSort = 400;

    protected static ?string $navigationLabel = 'Persetujuan Transaksi';

    protected static ?string $modelLabel = 'Persetujuan Transaksi';

    protected static ?string $pluralModelLabel = 'Persetujuan Transaksi';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || (($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(BookingResource::class));
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['booking', 'requester', 'approver']);
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            return $query;
        }

        // store_manager melihat semua pengajuan tokonya (kolom store_id biasa,
        // bukan JSON); staff biasa hanya pengajuan miliknya sendiri.
        if ($user?->isStoreManager() ?? false) {
            return $query->where('store_id', $user->store_id);
        }

        return $query->where('requested_by', $user?->id);
    }

    public static function getNavigationBadge(): ?string
    {
        // Badge "menunggu" hanya untuk yang berwenang memutuskan.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            return null;
        }

        $count = static::getEloquentQuery()->withoutEagerLoads()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('request_number')
                    ->label('No. Pengajuan')
                    ->searchable()
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->copyable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Jenis')
                    ->formatStateUsing(fn (string $state) => TransactionApprovalRequest::TYPE_LABELS[$state] ?? $state)
                    ->badge(),

                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking')
                    ->searchable()
                    ->url(fn (TransactionApprovalRequest $record) => $record->booking
                        ? BookingResource::getUrl('view', ['record' => $record->booking])
                        : null),

                Tables\Columns\TextColumn::make('nominal')
                    ->label('Nominal Diajukan')
                    ->getStateUsing(fn (TransactionApprovalRequest $record): string => 'Rp' . number_format($record->amount(), 0, ',', '.')),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Diajukan Oleh')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                        'gray' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state) => TransactionApprovalRequest::STATUS_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('approver.name')
                    ->label('Diputuskan Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada pengajuan')
            ->emptyStateDescription('Pengajuan Proses Referral, Refund, dan Catat DP dari staff muncul di sini.')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(TransactionApprovalRequest::STATUS_LABELS),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(TransactionApprovalRequest::TYPE_LABELS),
            ])
            ->actions([
                // Detail + riwayat keputusan (audit Persetujuan Transaksi
                // 2026-09-29): payload, alasan, siapa/kapan memutuskan.
                Tables\Actions\ViewAction::make()
                    ->label('Detail')
                    ->modalHeading(fn (TransactionApprovalRequest $r) => 'Pengajuan #' . $r->id . ' — ' . (TransactionApprovalRequest::TYPE_LABELS[$r->type] ?? $r->type))
                    ->infolist(fn (TransactionApprovalRequest $r) => [
                        Infolists\Components\Section::make('Pengajuan')->columns(2)->schema([
                            Infolists\Components\TextEntry::make('summary')->label('Ringkasan')->state($r->summaryLine())->columnSpanFull(),
                            Infolists\Components\TextEntry::make('nomor')->label('No. Pengajuan')->state($r->request_number ?? '—'),
                            Infolists\Components\TextEntry::make('resub')->label('Diajukan Ulang')->state($r->resubmitted_at?->format('d M Y H:i') ?? '—'),
                            Infolists\Components\TextEntry::make('status')->label('Status')->state(TransactionApprovalRequest::STATUS_LABELS[$r->status] ?? $r->status)->badge(),
                            Infolists\Components\TextEntry::make('diajukan')->label('Diajukan')->state($r->created_at?->format('d M Y H:i')),
                            Infolists\Components\TextEntry::make('alasan')->label('Alasan / Catatan Pengaju')
                                ->state($r->payload['reason'] ?? $r->payload['notes'] ?? '—')->columnSpanFull(),
                            Infolists\Components\TextEntry::make('metode')->label('Metode Pembayaran')->state($r->payload['payment_method'] ?? '—'),
                            Infolists\Components\TextEntry::make('kode')->label('Kode Referral')->state($r->payload['referral_code'] ?? '—'),
                        ]),
                        Infolists\Components\Section::make('Keputusan')->columns(2)->schema([
                            Infolists\Components\TextEntry::make('oleh')->label('Diputuskan Oleh')->state($r->approver?->name ?? '—'),
                            Infolists\Components\TextEntry::make('kapan')->label('Waktu Keputusan')->state($r->decided_at?->format('d M Y H:i') ?? '—'),
                            Infolists\Components\TextEntry::make('catatan')->label('Catatan Keputusan')->state($r->decision_note ?: '—')->columnSpanFull(),
                        ]),
                    ]),

                Tables\Actions\Action::make('cancelRequest')
                    ->label('Batalkan Pengajuan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (TransactionApprovalRequest $r) => $r->isPending() && (int) $r->requested_by === (int) auth()->id())
                    ->requiresConfirmation()
                    ->modalDescription(fn (TransactionApprovalRequest $r) => 'Batalkan pengajuan: ' . $r->summaryLine())
                    ->action(function (TransactionApprovalRequest $r) {
                        try {
                            app(TransactionApprovalService::class)->cancel($r, auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa dibatalkan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pengajuan dibatalkan.')->success()->send();
                    }),

                Tables\Actions\Action::make('resubmit')
                    ->label('Ajukan Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (TransactionApprovalRequest $r) => (int) $r->requested_by === (int) auth()->id()
                        && in_array($r->status, ['rejected', 'cancelled'], true)
                        && $r->resubmitted_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Data yang sama diajukan lagi sebagai pengajuan baru (sekali saja per pengajuan).')
                    ->action(function (TransactionApprovalRequest $r) {
                        try {
                            app(TransactionApprovalService::class)->resubmit($r, auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa diajukan ulang')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pengajuan dikirim ulang.')->success()->send();
                    }),

                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (TransactionApprovalRequest $record) => $record->isPending()
                        && (auth()->user()?->isFullAccess() ?? false)
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->requiresConfirmation()
                    ->modalHeading(fn (TransactionApprovalRequest $record) => 'Setujui ' . (TransactionApprovalRequest::TYPE_LABELS[$record->type] ?? 'pengajuan') . '?')
                    // Teks konfirmasi sesuai JENIS -- sebelumnya selalu "diposting ke
                    // Jurnal Umum" walau referral juga memberi poin dan DP dicatat
                    // sebagai Pendapatan Diterima Dimuka.
                    ->modalDescription(fn (TransactionApprovalRequest $record) => $record->summaryLine() . '. ' . match ($record->type) {
                        'booking_referral' => 'Nominal transaksi booking akan diperbarui dan diposting ke Jurnal Umum, serta poin referral diberikan kalau ada kode referral.',
                        'refund' => 'Refund akan diposting ke Jurnal Umum (kas keluar ke pelanggan).',
                        'booking_down_payment' => 'DP akan dicatat sebagai Pendapatan Diterima Dimuka (belum pendapatan) di Jurnal Umum.',
                        default => 'Pengajuan akan dieksekusi.',
                    })
                    ->action(function (TransactionApprovalRequest $record) {
                        $service = app(TransactionApprovalService::class);

                        try {
                            $warnings = $service->approve($record, auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Tidak bisa disetujui — pengajuan tertahan')
                                ->body($e->getMessage() . ' Perbaiki penyebabnya, atau tolak pengajuan ini dengan alasan.')
                                ->danger()
                                ->persistent()
                                ->send();

                            // Pengaju diberi tahu pengajuannya tertahan.
                            $service->notifyRequesterStuck($record, $e->getMessage());

                            return;
                        }

                        Notification::make()
                            ->title(match ($record->type) {
                                'booking_down_payment' => 'DP disetujui & dicatat.',
                                'booking_referral' => 'Referral disetujui & nominal diposting.',
                                default => 'Refund disetujui & diposting.',
                            })
                            ->success()
                            ->send();

                        foreach ($warnings as $warning) {
                            Notification::make()->title('Perhatian')->body($warning)->warning()->persistent()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TransactionApprovalRequest $record) => $record->isPending()
                        && (auth()->user()?->isFullAccess() ?? false)
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->modalDescription(fn (TransactionApprovalRequest $record) => $record->summaryLine())
                    ->form([
                        Forms\Components\Textarea::make('decision_note')
                            ->label('Alasan Penolakan')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (TransactionApprovalRequest $record, array $data) {
                        try {
                            app(TransactionApprovalService::class)->reject($record, auth()->id(), $data['decision_note']);
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Tidak bisa ditolak')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Permintaan ditolak.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactionApprovalRequests::route('/'),
        ];
    }
}
