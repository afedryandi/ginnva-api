<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerNotificationResource\Pages;
use App\Models\PartnerNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Mirror CustomerNotificationResource ('Riwayat Notifikasi') — admin bisa
 * KIRIM notifikasi ke Partner lewat Filament\Pages\SendNotification, tapi
 * SEBELUMNYA tidak ada cara sama sekali meninjau riwayat yang sudah
 * terkirim ke Partner (kebalikan dari gap yang ditemukan di audit Klaim
 * Reward, di sana Partner yang sudah punya riwayat & Customer belum).
 * Ditemukan & dibangun saat audit modul Sistem > Riwayat Notifikasi.
 */
class PartnerNotificationResource extends Resource
{
    protected static ?string $model = PartnerNotification::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Riwayat Notifikasi Partner';

    protected static ?string $modelLabel = 'Notifikasi Partner';

    protected static ?string $pluralModelLabel = 'Riwayat Notifikasi Partner';

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function canView($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->weight('bold')
                    ->wrap(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Isi')
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn ($record) => $record->body),

                Tables\Columns\TextColumn::make('target')
                    ->label('Target')
                    ->state(function (PartnerNotification $record): string {
                        if ($record->partner_id === null) {
                            return 'Broadcast (semua partner)';
                        }
                        return $record->partner?->business_name ?? "Partner #{$record->partner_id}";
                    })
                    ->badge()
                    ->color(fn (PartnerNotification $record) => $record->partner_id === null ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('data')
                    ->label('Deep Link')
                    ->state(function (PartnerNotification $record): string {
                        return $record->data['route'] ?? '-';
                    })
                    ->color('gray')
                    ->size('sm'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dikirim')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('broadcast')
                    ->label('Broadcast saja')
                    ->query(fn ($query) => $query->whereNull('partner_id')),

                Tables\Filters\Filter::make('targeted')
                    ->label('Targeted saja')
                    ->query(fn ($query) => $query->whereNotNull('partner_id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading('Detail Notifikasi')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('title')->label('Judul')->disabled(),
                        \Filament\Forms\Components\Textarea::make('body')->label('Isi')->disabled()->rows(3),
                        \Filament\Forms\Components\KeyValue::make('data')->label('Data / Deep Link')->disabled(),
                    ]),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartnerNotifications::route('/'),
        ];
    }
}
