<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionApprovalRequestResource\Pages;
use App\Models\TransactionApprovalRequest;
use App\Services\TransactionApprovalService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Audit framework 2026-09-14, "Segregation of duties finansial" --
 * antrean persetujuan untuk "Proses Referral"/"Proses Refund" yang
 * diajukan staff non-full-access dari BookingResource. TERBATAS
 * full-access (super_admin/direksi) SAJA, sama filosofi dengan
 * JournalEntryResource/PayrollResource -- keputusan approve/reject
 * SENGAJA tidak bisa didelegasikan ke store_manager, supaya
 * pemisahan tugas (yang input ≠ yang menyetujui) benar-benar tertegak.
 */
class TransactionApprovalRequestResource extends Resource
{
    protected static ?string $model = TransactionApprovalRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Persetujuan Transaksi';

    protected static ?string $modelLabel = 'Persetujuan Transaksi';

    protected static ?string $pluralModelLabel = 'Persetujuan Transaksi';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
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
        return parent::getEloquentQuery()->with(['booking', 'requester', 'approver']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

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
                    ->getStateUsing(function (TransactionApprovalRequest $record): string {
                        $amount = in_array($record->type, ['refund', 'booking_down_payment'], true)
                            ? ($record->payload['amount'] ?? 0)
                            : ($record->payload['transaction_amount'] ?? 0);

                        return 'Rp' . number_format((float) $amount, 0, ',', '.');
                    }),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Diajukan Oleh')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Menunggu',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('approver.name')
                    ->label('Diputuskan Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Menunggu',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (TransactionApprovalRequest $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->modalDescription('Nominal akan langsung diposting ke Jurnal Umum. Lanjutkan?')
                    ->action(function (TransactionApprovalRequest $record) {
                        try {
                            app(TransactionApprovalService::class)->approve($record, auth()->id());
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Tidak bisa disetujui')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Permintaan disetujui & sudah diposting.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TransactionApprovalRequest $record) => $record->isPending())
                    ->form([
                        Forms\Components\Textarea::make('decision_note')
                            ->label('Alasan Penolakan')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (TransactionApprovalRequest $record, array $data) {
                        try {
                            app(TransactionApprovalService::class)->reject($record, auth()->id(), $data['decision_note'] ?: null);
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
