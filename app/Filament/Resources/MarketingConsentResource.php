<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketingConsentResource\Pages;
use App\Models\MarketingConsent;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Izin Foto Pelanggan (Marketing/Konten Fase 3): daftar izin yang dicatat saat serah terima. Hanya dibaca; satu-satunya tindakan adalah
 * menarik izin atas permintaan pelanggan, yang langsung menonaktifkan materi galeri bersumber booking itu.
 */
class MarketingConsentResource extends Resource
{
    protected static ?string $model = MarketingConsent::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $cluster = \App\Filament\Clusters\MarketingKontenCluster::class;

    protected static ?string $navigationGroup = 'Konten Beranda';

    protected static ?string $navigationLabel = 'Izin Foto Pelanggan';

    protected static ?string $modelLabel = 'Izin Foto';

    protected static ?string $pluralModelLabel = 'Izin Foto Pelanggan';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
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
        return parent::getEloquentQuery()->with('booking:id,booking_number,customer_name,vehicle_brand,vehicle_model');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking.booking_number')->label('Booking')->searchable(),
                Tables\Columns\TextColumn::make('booking.customer_name')->label('Pelanggan')->searchable(),
                Tables\Columns\TextColumn::make('booking.vehicle_model')
                    ->label('Kendaraan')
                    ->formatStateUsing(fn ($state, MarketingConsent $record) => trim("{$record->booking?->vehicle_brand} {$record->booking?->vehicle_model}") ?: '—'),
                Tables\Columns\TextColumn::make('granted_at')->label('Diberikan')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (MarketingConsent $record) => $record->isActive() ? 'aktif' : 'ditarik')
                    ->formatStateUsing(fn (string $state) => $state === 'aktif' ? 'Aktif' : 'Ditarik')
                    ->color(fn (string $state) => $state === 'aktif' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('withdrawn_at')->label('Ditarik')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('withdrawn_note')->label('Catatan')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('granted_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('withdrawn')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Ditarik')
                    ->falseLabel('Aktif')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('withdrawn_at'),
                        false: fn (Builder $q) => $q->whereNull('withdrawn_at'),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('withdraw')
                    ->label('Tarik Izin')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Materi galeri yang bersumber dari booking ini langsung dinonaktifkan. Foto yang sudah terlanjur dibagikan di luar sistem perlu ditarik manual.')
                    ->form([Forms\Components\TextInput::make('note')->label('Catatan (opsional)')->maxLength(255)])
                    ->visible(fn (MarketingConsent $record) => $record->isActive()
                        && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false))
                    ->action(function (MarketingConsent $record, array $data) {
                        $record->withdraw(auth()->user(), $data['note'] ?? null);
                        Notification::make()->title('Izin ditarik')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketingConsents::route('/'),
        ];
    }
}
