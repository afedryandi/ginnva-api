<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PointTransactionResource\Pages;
use App\Models\PointTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only murni — ini ledger poin customer (earn/spend dari booking,
 * bonus "ajak teman" antar-customer, reward redemption, dst). Tidak ada
 * create/edit/delete sama sekali: setiap baris SELALU dibuat otomatis
 * oleh sistem (ReferralPointService, WarrantyObserver, RewardController)
 * sebagai jejak audit — kalau admin bisa edit manual, ledger-nya jadi
 * tidak bisa dipercaya lagi sebagai bukti berapa poin yang sebenarnya
 * pernah diberikan/dipakai.
 */
class PointTransactionResource extends Resource
{
    protected static ?string $model = PointTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 65;

    protected static ?string $navigationLabel = 'Riwayat Poin Customer';

    protected static ?string $modelLabel = 'Transaksi Poin';

    protected static ?string $pluralModelLabel = 'Riwayat Poin Customer';

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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Transaksi')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('customer.name')
                        ->label('Customer')
                        ->content(fn (?PointTransaction $record) => $record?->customer?->name ?? '—'),

                    Forms\Components\Placeholder::make('type')
                        ->label('Tipe')
                        ->content(fn (?PointTransaction $record) => $record?->type === 'earn' ? 'Dapat Poin' : 'Pakai Poin'),

                    Forms\Components\Placeholder::make('points')
                        ->label('Jumlah Poin')
                        ->content(fn (?PointTransaction $record) => $record?->points),

                    Forms\Components\Placeholder::make('reference_type')
                        ->label('Sumber')
                        ->content(fn (?PointTransaction $record) => match ($record?->reference_type) {
                            'booking'                    => 'Booking (transaksi toko)',
                            'customer_referral'          => 'Bonus Ajak Teman',
                            'warranty'                   => 'Registrasi Garansi',
                            'reward_redemption'          => 'Tukar Reward',
                            'reward_redemption_refund'   => 'Refund Pembatalan Reward',
                            'reward_redemption_reversal' => 'Pembatalan Reward Dibatalkan',
                            default                      => $record?->reference_type ?? '—',
                        }),

                    Forms\Components\Placeholder::make('description')
                        ->label('Deskripsi')
                        ->columnSpanFull()
                        ->content(fn (?PointTransaction $record) => $record?->description ?? '—'),

                    Forms\Components\Placeholder::make('created_at')
                        ->label('Tanggal')
                        ->content(fn (?PointTransaction $record) => $record?->created_at?->format('d M Y H:i')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Tipe')
                    ->colors([
                        'success' => 'earn',
                        'danger'  => 'spend',
                    ])
                    ->formatStateUsing(fn (string $state): string => $state === 'earn' ? 'Dapat Poin' : 'Pakai Poin'),

                Tables\Columns\TextColumn::make('points')
                    ->label('Poin')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Sumber')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'booking'                    => 'Booking',
                        'customer_referral'          => 'Ajak Teman',
                        'warranty'                   => 'Registrasi Garansi',
                        'reward_redemption'          => 'Tukar Reward',
                        'reward_redemption_refund'   => 'Refund Reward',
                        'reward_redemption_reversal' => 'Reward Dibatalkan Ulang',
                        default                      => $state ?? '—',
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options([
                        'earn'  => 'Dapat Poin',
                        'spend' => 'Pakai Poin',
                    ]),
                Tables\Filters\SelectFilter::make('reference_type')
                    ->label('Sumber')
                    ->options([
                        'booking'                    => 'Booking',
                        'customer_referral'          => 'Ajak Teman',
                        'warranty'                   => 'Registrasi Garansi',
                        'reward_redemption'          => 'Tukar Reward',
                        'reward_redemption_refund'   => 'Refund Reward',
                        'reward_redemption_reversal' => 'Reward Dibatalkan Ulang',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPointTransactions::route('/'),
            'view'  => Pages\ViewPointTransaction::route('/{record}'),
        ];
    }
}
