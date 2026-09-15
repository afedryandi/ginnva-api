<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinanceTransactionApprovalRequestResource\Pages;
use App\Models\FinanceCategory;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\Store;
use App\Services\FinanceTransactionApprovalService;
use Filament\Forms;
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
        $query = parent::getEloquentQuery()->with(['requester', 'managerApprover', 'direksiApprover', 'financeTransaction']);
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            return $query;
        }

        if ($user?->isStoreManager() ?? false) {
            // store_manager lihat SEMUA pengajuan toko sendiri (bukan
            // cuma yang perlu di-approve dia) supaya bisa pantau status
            // pengajuan staff-nya sampai tuntas ke direksi.
            return $query->where(fn ($q) => $q
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.store_id')) = ?", [(string) $user->store_id]));
        }

        // Staff biasa cuma lihat pengajuannya sendiri.
        return $query->where('requested_by', $user?->id);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $query = static::getEloquentQuery();

        if ($user?->isFullAccess() ?? false) {
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
                Tables\Columns\TextColumn::make('payload')
                    ->label('Kategori')
                    ->getStateUsing(fn (FinanceTransactionApprovalRequest $r) => FinanceCategory::find($r->payload['finance_category_id'] ?? null)?->name ?? '—'),

                Tables\Columns\TextColumn::make('store')
                    ->label('Toko')
                    ->getStateUsing(fn (FinanceTransactionApprovalRequest $r) => Store::find($r->payload['store_id'] ?? null)?->name ?? '—'),

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
                    ])
                    ->formatStateUsing(fn (string $state) => FinanceTransactionApprovalRequest::STATUS_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(FinanceTransactionApprovalRequest::STATUS_LABELS),
            ])
            ->actions([
                Tables\Actions\Action::make('approve_manager')
                    ->label('Setujui (Store Manager)')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => $r->isPendingManager()
                        && ((auth()->user()?->isStoreManager() && auth()->user()?->store_id === ($r->payload['store_id'] ?? null))
                            || (auth()->user()?->isFullAccess() ?? false)))
                    ->requiresConfirmation()
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
                    ->visible(fn (FinanceTransactionApprovalRequest $r) => $r->isPendingDireksi() && (auth()->user()?->isFullAccess() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('Transaksi akan langsung dibuat & diposting ke Jurnal Umum. Lanjutkan?')
                    ->action(function (FinanceTransactionApprovalRequest $r) {
                        try {
                            app(FinanceTransactionApprovalService::class)->approveByDireksi($r, auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Tidak bisa disetujui')->body($e->getMessage())->danger()->send();

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
                            (auth()->user()?->isFullAccess() ?? false)
                            || ($r->isPendingManager() && auth()->user()?->isStoreManager() && auth()->user()?->store_id === ($r->payload['store_id'] ?? null))
                        ))
                    ->form([
                        Forms\Components\Textarea::make('rejection_note')
                            ->label('Alasan Penolakan')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (FinanceTransactionApprovalRequest $r, array $data) {
                        try {
                            app(FinanceTransactionApprovalService::class)->reject($r, auth()->user(), $data['rejection_note'] ?: null);
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
