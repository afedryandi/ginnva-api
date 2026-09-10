<?php

namespace App\Filament\Resources\SpendPromoResource\RelationManagers;

use App\Models\Booking;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Booking yang memakai promo ini — read-only (promo diterapkan lewat
 * form Booking, bukan dari sini).
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Booking yang Pakai Promo Ini';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('booking_number')
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('No. Booking')
                    ->searchable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('spend_promo_discount')
                    ->label('Potongan')
                    ->money('IDR'),

                Tables\Columns\TextColumn::make('transaction_amount')
                    ->label('Nilai Transaksi (net)')
                    ->money('IDR'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
