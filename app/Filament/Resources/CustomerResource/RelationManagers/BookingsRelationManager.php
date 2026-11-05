<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Riwayat booking pelanggan, hanya untuk dibaca: perubahan dilakukan lewat menu Booking. */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Riwayat Booking';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('booking_number')
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')->label('No. Booking')->searchable(),
                Tables\Columns\TextColumn::make('sale_date')->label('Tanggal Penjualan')->date('d M Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge(),
                Tables\Columns\TextColumn::make('transaction_amount')->label('Nilai')->money('IDR')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25]);
    }
}
