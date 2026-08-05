<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerNotificationResource\Pages;
use App\Models\CustomerNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerNotificationResource extends Resource
{
    protected static ?string $model = CustomerNotification::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Riwayat Notifikasi';

    protected static ?string $modelLabel = 'Notifikasi';

    protected static ?string $pluralModelLabel = 'Riwayat Notifikasi';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

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
                    ->state(function (CustomerNotification $record): string {
                        if ($record->customer_id === null) {
                            return 'Broadcast (semua user)';
                        }
                        return $record->customer?->name ?? "Customer #{$record->customer_id}";
                    })
                    ->badge()
                    ->color(fn (CustomerNotification $record) => $record->customer_id === null ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('data')
                    ->label('Deep Link')
                    ->state(function (CustomerNotification $record): string {
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
                    ->query(fn ($query) => $query->whereNull('customer_id')),

                Tables\Filters\Filter::make('targeted')
                    ->label('Targeted saja')
                    ->query(fn ($query) => $query->whereNotNull('customer_id')),
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
            'index' => Pages\ListCustomerNotifications::route('/'),
        ];
    }
}
