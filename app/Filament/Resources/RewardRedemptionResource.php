<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RewardRedemptionResource\Pages;
use App\Models\RewardRedemption;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only-ish — admin cuma bisa lihat siapa yang redeem apa & ubah
 * status (pending -> fulfilled/cancelled) setelah hadiahnya dikirim.
 * Tidak ada create (redemption hanya dibuat lewat mobile app oleh
 * partner/customer sendiri, bukan diinput manual admin).
 */
class RewardRedemptionResource extends Resource
{
    protected static ?string $model = RewardRedemption::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift-top';

    protected static ?string $navigationGroup = 'Partnership Referral';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Klaim Reward';

    protected static ?string $modelLabel = 'Klaim Reward';

    protected static ?string $pluralModelLabel = 'Klaim Reward';

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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options([
                    'pending'   => 'Menunggu Diproses',
                    'fulfilled' => 'Sudah Dikirim',
                    'cancelled' => 'Dibatalkan',
                ])
                ->required(),

            Forms\Components\Textarea::make('notes')
                ->label('Catatan Admin')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('redeemer_name')
                    ->label('Ditukar Oleh'),

                Tables\Columns\TextColumn::make('reward.name')
                    ->label('Reward')
                    ->searchable(),

                Tables\Columns\TextColumn::make('points_spent')
                    ->label('Poin')
                    ->numeric(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'fulfilled',
                        'danger'  => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => 'Menunggu Diproses',
                        'fulfilled' => 'Sudah Dikirim',
                        'cancelled' => 'Dibatalkan',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Menunggu Diproses',
                        'fulfilled' => 'Sudah Dikirim',
                        'cancelled' => 'Dibatalkan',
                    ]),
                Tables\Filters\SelectFilter::make('redeemer_type')
                    ->label('Tipe')
                    ->options([
                        'partner'  => 'Partner',
                        'customer' => 'Customer',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRewardRedemptions::route('/'),
            'edit'  => Pages\EditRewardRedemption::route('/{record}/edit'),
        ];
    }
}
