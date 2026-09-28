<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinanceTransactionApprovalRequestResource\Pages;
use App\Models\FinanceCategory;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\Store;
use App\Services\FinanceTransactionApprovalService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Fase 4 — Kontrol & Kepatuhan (diminta user 2026-09-15): antrean
 * approval berjenjang pengeluaran Transaksi Keuangan. store_manager
 * lihat & approve pengajuan TOKONYA SENDIRI yang masih pending_manager,
 * direksi/full-access lihat & approve SEMUA yang pending_direksi
 * (plus lihat semua riwayat untuk pengawasan).
 */
class FinanceTransactionApprovalRequestResource extends Resource
{
    protected static ?string $model = FinanceTransactionApprovalRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Persetujuan Pengeluaran';

    protected static ?string $modelLabel = 'Persetujuan Pengeluaran';

    protected static ?string $pluralModelLabel = 'Persetujuan Pengeluaran';

    /**
     * Siapa pun yang punya akses menu Transaksi Keuangan boleh lihat
     * (staff biasa lihat riwayat pengajuannya sendiri, store_manager &
     * full-access lihat lebih luas -- diatur di getEloquentQuery()).
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(\App\Filament\Resources\FinanceTransactionResource::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
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
        $query = parent::getEloquentQuery()->with(['requester', 'managerApprover', 'direksiApprover', 'rejecter', 'financeTransaction']);
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            return $query;
        }

        if ($user?->isStoreManager() ?? false) {
            // store_manager lihat SEMUA pengajuan toko sendiri (bukan
            // cuma yang perlu di-approve dia) supaya bisa pantau status
            // pengajuan staff-nya sampai tuntas ke direksi.
            return $query->where('store_id', $user->store_id);
        }

        // Staff biasa cuma lihat pengajuannya sendiri.
        return $query->where('requested_by', $user?->id);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        // withoutEagerLoads: badge cuma butuh count() (audit 2026-09-28).
        $query = static::getEloquentQuery()->withoutEagerLoads();

        if ($user?->isFinanceApprover() ?? false) {
            $count = (clone $query)->where('status', 'pending_direksi')->count();
        } elseif ($user?->isStoreManager() ?? false) {
            $count = (clone $query)->where('status', 'pending_manager')->count();
        } else {
            $count = 0;
        }

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

                // Nama kategori & toko dimuat SEKALI per request (bukan
                // find() per baris -- sebelumnya N+1).
                Tables\Columns\TextColumn::make('payload')
                    ->label('Kategori')
                    ->getStateUsing(function (FinanceTransactionApprovalRequest $r) {
                        static $names = null;
                        $names ??= FinanceCategory::pluck('name', 'id');

                        return $names[$r->payload['finance_category_id'] ?? 0] ?? '—';
                    })
                    // Pencarian kategori (payload JSON) -- gap audit 2026-09-28.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $ids = FinanceCategory::where('name', 'like', "%{$search}%")->pluck('id')->map(fn ($id) => (string) $id)->all();

                        return $ids
                            ? $query->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.finance_category_id')) IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)
                            : $query;
                    }),

                Tables\Columns\TextColumn::make('store')
                    ->label('Toko')
                    ->getStateUsing(function (FinanceTransactionApprovalRequest $r) {
                        static $names = null;
                        $names ??= Store::pluck('name', 'id');

                        return $names[$r->payload['store_id'] ?? 0] ?? '—';
                    })
                    ->toggleable(),

                // Konteks yang dibutuhkan approver (sebelumnya menyetujui
                // "buta": tanggal, keterangan, dan nota tidak terlihat).
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Tgl. Transaksi')
                    ->getStateUsing(fn (FinanceTransactionApprovalRequest $r) => isset($r->payload['transaction_date'])
                        ? \Illuminate\Support\Carbon::parse($r->payload['transaction_date'])->format('d M Y')
                        : '—'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->getStateUsing(fn (FinanceTransactionApprovalRequest $r) => $r->payload['description'] ?? null)
                    ->limit(50)
                    ->placeholder('—')
                    ->tooltip(fn (FinanceTransactionApprovalRequest $r) => $r->payload['description'] ?? null)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('has_receipt')
                    ->label('Nota')
                    ->getStateUsing(fn (FinanceTransactionApprovalRequest $r) => ! empty($r->payload['receipt']))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->getStateUsing(fn (FinanceTransactionApprovalRequest $r) => 'Rp' . number_format((float) ($r->payload['amount'] ?? 0), 0, ',', '.')),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Diajukan Oleh')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => ['pending_manager', 'pending_direksi'],
                        'success' => 'approved',
                        'danger' => 'rejected',
                        'gray' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state) => FinanceTransactionApprovalRequest::STATUS_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada pengajuan pengeluaran')
            ->emptyStateDescription('Pengajuan muncul di sini saat staff non-full-access mencatat pengeluaran.')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(FinanceTransactionApprovalRequest::STATUS_LABELS),
            ])
            ->actions([
                // Detail + riwayat approval lengkap (gap audit 2026-09-28):
                // siapa menyetujui tiap tahap & kapan, alasan penolakan, dan
                // nomor transaksi hasilnya.
                Tables\Actions\ViewAction::make()
                    ->label('Detail')
                    ->modalHeading(fn (FinanceTransactionApprovalRequest $r) => 'Pengajuan ' . ($r->request_number ?? '#' . $r->id))
                    ->infolist(fn (FinanceTransactionApprovalRequest $r) => [
                        Infolists\Components\Section::make('Pengajuan')->columns(2)->schema([
                            Infolists\Components\TextEntry::make('summary')->label('Ringkasan')->state($r->summaryLine())->columnSpanFull(),
                            Infolists\Components\TextEntry::make('status')->label('Status')->state(FinanceTransactionApprovalRequest::STATUS_LABELS[$r->status] ?? $r->status)->badge(),
                            Infolists\Components\TextEntry::make('tgl')->label('Tanggal Transaksi')->state(isset($r->payload['transaction_date']) ? \Illuminate\Support\Carbon::parse($r->payload['transaction_date'])->format('d M Y') : '—'),
                            Infolists\Components\TextEntry::make('desc')->label('Keterangan')->state($r->payload['description'] ?? '—')->columnSpanFull(),
                            Infolists\Components\TextEntry::make('diajukan')->label('Diajukan')->state($r->created_at?->format('d M Y H:i')),
                            Infolists\Components\TextEntry::make('resub')->label('Diajukan Ulang')->state($r->resubmitted_at ? $r->resubmitted_at->format('d M Y H:i') : '—'),
                        ]),
                        Infolists\Components\Section::make('Riwayat Persetujuan')->columns(2)->schema([
                            Infolists\Components\TextEntry::make('mgr')->label('Store Manager')
                                ->state($r->managerApprover ? $r->managerApprover->name . ' — ' . $r->manager_approved_at?->format('d M Y H:i') : '—'),
                            Infolists\Components\TextEntry::make('dir')->label('Direksi')
                                ->state($r->direksiApprover ? $r->direksiApprover->name . ' — ' . $r->direksi_approved_at?->format('d M Y H:i') : '—'),
                            Infolists\Components\TextEntry::make('rej')->label('Ditolak Oleh')
                                ->state($r->rejecter ? $r->rejecter->name . ' — ' . $r->rejected_at?->format('d M Y H:i') : '—'),
                            Infolists\Components\TextEntry::make('note')->label('Alasan Penolakan')->state($r->rejection_note ?: '—'),
                            Infolists\Components\TextEntry::make('trx')->label('Transaksi Hasil')->state($r->financeTransaction?->transaction_number ?? '—')->columnSpanFull(),
                        ]),
                    ]),

                Tables\Actions\Action::make('cancelRequest')
                    ->label('Batalkan Pengajuan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => (int) $r->requested_by === (int) auth()->id()
                        && in_array($r->status, ['pending_manager', 'pending_direksi'], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (FinanceTransactionApprovalRequest $r) => 'Batalkan pengajuan: ' . $r->summaryLine())
                    ->action(function (FinanceTransactionApprovalRequest $r) {
                        try {
                            app(FinanceTransactionApprovalService::class)->cancel($r, auth()->user());
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
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => (int) $r->requested_by === (int) auth()->id()
                        && in_array($r->status, ['rejected', 'cancelled'], true)
                        && $r->resubmitted_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Data yang sama diajukan lagi sebagai pengajuan baru (nota perlu diunggah ulang lewat transaksi baru kalau ada).')
                    ->action(function (FinanceTransactionApprovalRequest $r) {
                        try {
                            app(FinanceTransactionApprovalService::class)->resubmit($r, auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa diajukan ulang')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pengajuan dikirim ulang.')->success()->send();
                    }),

                Tables\Actions\Action::make('viewReceipt')
                    ->label('Lihat Nota')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => ! empty($r->payload['receipt']))
                    ->url(fn (FinanceTransactionApprovalRequest $r) => \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk', 'public'))->url($r->payload['receipt']))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('approve_manager')
                    ->label('Setujui (Store Manager)')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => $r->isPendingManager()
                        && ((auth()->user()?->isStoreManager() && (int) auth()->user()?->store_id === (int) $r->store_id_from_payload)
                            || (auth()->user()?->isFinanceApprover() ?? false)))
                    ->requiresConfirmation()
                    ->modalHeading('Setujui pengajuan pengeluaran?')
                    ->modalDescription(fn (FinanceTransactionApprovalRequest $r) => $r->summaryLine() . '. Setelah disetujui, diteruskan ke direksi.')
                    ->action(function (FinanceTransactionApprovalRequest $r) {
                        try {
                            app(FinanceTransactionApprovalService::class)->approveByManager($r, auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa disetujui')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Disetujui, diteruskan ke direksi.')->success()->send();
                    }),

                Tables\Actions\Action::make('approve_direksi')
                    ->label('Setujui (Direksi)')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => $r->isPendingDireksi() && (auth()->user()?->isFinanceApprover() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (FinanceTransactionApprovalRequest $r) => $r->summaryLine() . '. Transaksi akan langsung dibuat & diposting ke Jurnal Umum.')
                    ->action(function (FinanceTransactionApprovalRequest $r) {
                        try {
                            app(FinanceTransactionApprovalService::class)->approveByDireksi($r, auth()->user());
                        } catch (RuntimeException $e) {
                            $hint = str_contains($e->getMessage(), 'ditutup')
                                ? ' Buka kembali periodenya di Tutup Periode, atau tolak pengajuan ini dengan alasan.'
                                : ' Perbaiki penyebabnya, atau tolak pengajuan ini dengan alasan.';

                            Notification::make()->title('Tidak bisa disetujui — pengajuan tertahan')->body($e->getMessage() . $hint)->danger()->persistent()->send();

                            // Pengaju diberi tahu pengajuannya tertahan.
                            app(FinanceTransactionApprovalService::class)->notifyRequesterStuck($r, $e->getMessage());

                            return;
                        }

                        Notification::make()->title('Disetujui & sudah diposting ke Jurnal Umum.')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => in_array($r->status, ['pending_manager', 'pending_direksi'], true)
                        && (
                            (auth()->user()?->isFinanceApprover() ?? false)
                            || ($r->isPendingManager() && auth()->user()?->isStoreManager() && (int) auth()->user()?->store_id === (int) $r->store_id_from_payload)
                        ))
                    ->modalDescription(fn (FinanceTransactionApprovalRequest $r) => $r->summaryLine())
                    ->form([
                        Forms\Components\Textarea::make('rejection_note')
                            ->label('Alasan Penolakan')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (FinanceTransactionApprovalRequest $r, array $data) {
                        try {
                            app(FinanceTransactionApprovalService::class)->reject($r, auth()->user(), $data['rejection_note']);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa ditolak')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pengajuan ditolak.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinanceTransactionApprovalRequests::route('/'),
        ];
    }
}
