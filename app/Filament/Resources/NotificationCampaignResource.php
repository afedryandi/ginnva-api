<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationCampaignResource\Pages;
use App\Models\NotificationCampaign;
use App\Models\NotificationDelivery;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Kampanye Notifikasi" (Notifikasi Fase 5, 2026-10-21): setiap kiriman dari menu Kirim Notifikasi beserta hasilnya (penerima,
 * dilewati, perangkat terkirim/gagal, dan hasil kuitansi Expo per kampanye). Kampanye terjadwal bisa dibatalkan sebelum waktunya.
 */
class NotificationCampaignResource extends Resource
{
    protected static ?string $model = NotificationCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $cluster = \App\Filament\Clusters\NotifikasiCluster::class;

    protected static ?string $navigationLabel = 'Kampanye Notifikasi';

    protected static ?string $modelLabel = 'Kampanye';

    protected static ?string $pluralModelLabel = 'Kampanye Notifikasi';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->canAccessStaffArea() && $user->hasMenuAccess(static::class));
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('creator:id,name')
            ->withCount([
                'deliveries as delivered_count' => fn ($q) => $q->where('status', NotificationDelivery::DELIVERED),
                'deliveries as waiting_count'   => fn ($q) => $q->where('status', NotificationDelivery::ACCEPTED),
                'deliveries as problem_count'   => fn ($q) => $q->whereIn('status', [NotificationDelivery::FAILED, NotificationDelivery::DEAD_TOKEN]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('when')
                    ->label('Waktu')
                    ->state(fn (NotificationCampaign $r) => $r->sent_at ?? $r->scheduled_at ?? $r->created_at)
                    ->dateTime('d M Y, H:i')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('created_at', $direction)),

                Tables\Columns\TextColumn::make('title')->label('Judul')->searchable()->wrap()->weight('bold'),

                Tables\Columns\TextColumn::make('audience')
                    ->label('Untuk')
                    ->formatStateUsing(fn (NotificationCampaign $r) => $r->audience === 'partner' ? 'Partner' : ($r->category === 'info' ? 'Pelanggan · Informasi' : 'Pelanggan · Promosi'))
                    ->badge()
                    ->color(fn (NotificationCampaign $r) => $r->audience === 'partner' ? 'gray' : ($r->category === 'info' ? 'info' : 'warning')),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => NotificationCampaign::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        NotificationCampaign::SENT => 'success',
                        NotificationCampaign::SCHEDULED, NotificationCampaign::SENDING => 'warning',
                        NotificationCampaign::CANCELLED => 'gray',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('recipients')->label('Penerima')->placeholder('—')->numeric(),
                Tables\Columns\TextColumn::make('skipped')->label('Dilewati')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('devices_sent')->label('Diterima Expo')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('delivered_count')->label('Terkirim')->numeric()->color('success'),
                Tables\Columns\TextColumn::make('waiting_count')->label('Menunggu Kuitansi')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('problem_count')->label('Gagal / Token Mati')->numeric()->color(fn ($state) => $state > 0 ? 'danger' : null),
                Tables\Columns\TextColumn::make('creator.name')->label('Dibuat Oleh')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(NotificationCampaign::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('audience')->label('Untuk')->options(['customer' => 'Pelanggan', 'partner' => 'Partner']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading('Detail Kampanye')
                    ->form([
                        TextInput::make('title')->label('Judul')->disabled(),
                        Textarea::make('body')->label('Isi')->disabled()->rows(3),
                        Textarea::make('message')->label('Keterangan Hasil')->disabled()->rows(2),
                    ]),

                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan kampanye terjadwal?')
                    ->modalDescription('Kampanye tidak akan dikirim. Tindakan ini tidak bisa diurungkan.')
                    ->visible(fn (NotificationCampaign $r) => $r->status === NotificationCampaign::SCHEDULED && static::canViewAny())
                    ->action(function (NotificationCampaign $record): void {
                        // Pembaruan bersyarat: kampanye yang sudah diklaim pengirim terjadwal tidak bisa dibatalkan lagi.
                        $cancelled = NotificationCampaign::where('id', $record->id)
                            ->where('status', NotificationCampaign::SCHEDULED)
                            ->update(['status' => NotificationCampaign::CANCELLED, 'message' => 'Dibatalkan oleh ' . (auth()->user()?->name ?? 'admin') . '.']);

                        $cancelled === 1
                            ? Notification::make()->title('Kampanye dibatalkan')->success()->send()
                            : Notification::make()->title('Tidak bisa dibatalkan')->body('Kampanye sudah mulai dikirim.')->danger()->send();
                    }),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationCampaigns::route('/'),
        ];
    }
}
