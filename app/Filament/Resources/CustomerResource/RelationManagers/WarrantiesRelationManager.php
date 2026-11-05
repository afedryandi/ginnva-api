<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Riwayat garansi pelanggan, hanya untuk dibaca: perubahan dilakukan lewat menu Garansi. */
class WarrantiesRelationManager extends RelationManager
{
    protected static string $relationship = 'warranties';

    protected static ?string $title = 'Riwayat Garansi';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('warranty_code')
            ->columns([
                Tables\Columns\TextColumn::make('warranty_code')->label('Kode Garansi')->searchable(),
                Tables\Columns\TextColumn::make('car_plate')->label('Plat')->placeholder('—'),
                Tables\Columns\TextColumn::make('product_series')->label('Produk')->placeholder('—'),
                Tables\Columns\TextColumn::make('expiry_date')->label('Berlaku Sampai')->date('d M Y')->placeholder('—')->sortable(),
            ])
            ->defaultSort('expiry_date', 'desc')
            ->paginated([10, 25]);
    }
}
