<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationDeliveryResource\Pages;
use App\Models\NotificationDelivery;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Log Pengiriman" (Notifikasi Fase 3, 2026-10-21): hasil tiap push per token, termasuk kuitansi Expo. Baca-saja; jawaban untuk
 * "apakah notifikasi X sampai ke Y". Dibersihkan otomatis oleh notifications:prune-old (90 hari).
 */
class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $cluster = \App\Filament\Clusters\NotifikasiCluster::class;

    protected static ?string $navigationLabel = 'Log Pengiriman';

    protected static ?string $modelLabel = 'Pengiriman';

    protected static ?string $pluralModelLabel = 'Log Pengiriman';

    protected static ?int $navigationSort = 35;

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
        return parent::getEloquentQuery()->with(['user:id,name', 'customer:id,name,deleted_at']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sent_at')->label('Dikirim')->dateTime('d M Y, H:i')->sortable(),

                Tables\Columns\TextColumn::make('recipient')
                    ->label('Penerima')
                    ->state(fn (NotificationDelivery $r): string => $r->user?->name
                        ?? ($r->customer ? ($r->customer->deleted_at ? '(Akun Dihapus)' : $r->customer->name) : 'Perangkat tanpa akun'))
                    ->badge()
                    ->color(fn (NotificationDelivery $r): string => $r->user_id ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('title')->label('Judul')->searchable()->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Hasil')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => NotificationDelivery::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        NotificationDelivery::DELIVERED => 'success',
                        NotificationDelivery::ACCEPTED => 'warning',
                        NotificationDelivery::UNKNOWN => 'gray',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('error')->label('Keterangan')->placeholder('—')->limit(60)->tooltip(fn (NotificationDelivery $r) => $r->error)->toggleable(),

                Tables\Columns\TextColumn::make('checked_at')->label('Kuitansi Diperiksa')->dateTime('d M Y, H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Hasil')->options(NotificationDelivery::STATUS_LABELS),

                Tables\Filters\Filter::make('sent_at')
                    ->form([
                        DatePicker::make('from')->label('Dari Tanggal'),
                        DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('sent_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('sent_at', '<=', $date))),
            ])
            ->actions([])
            ->bulkActions([])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationDeliveries::route('/'),
        ];
    }
}
