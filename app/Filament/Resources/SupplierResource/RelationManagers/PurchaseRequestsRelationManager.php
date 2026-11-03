<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Filament\Resources\PurchaseRequestResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat barang yang diterima dari supplier ini (Permohonan Pembelian terpenuhi), beserta harga satuan terakhir bagi yang boleh
 * melihat harga. Hanya permohonan yang boleh dilihat akun ini (toko sendiri), dan hanya sejak pemasok dicatat saat penerimaan.
 */
class PurchaseRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseRequests';

    protected static ?string $title = 'Riwayat Pembelian';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return PurchaseRequestResource::canViewAny();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('request_number')
            ->modifyQueryUsing(fn ($query) => $query->where('status', 'fulfilled'))
            ->columns([
                Tables\Columns\TextColumn::make('fulfilled_at')->label('Diterima')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('request_number')->label('No. Permohonan')->searchable(),
                Tables\Columns\TextColumn::make('item_name')->label('Barang')->searchable(),
                Tables\Columns\TextColumn::make('quantity_received')->label('Jumlah')
                    ->formatStateUsing(fn ($state, $record) => rtrim(rtrim(number_format((float) $state, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : '')),
                Tables\Columns\TextColumn::make('actual_cost')->label('Total Biaya')->money('IDR')->placeholder('—')
                    ->visible(fn () => PurchaseRequestResource::canSeeCost()),
                Tables\Columns\TextColumn::make('unit_price')->label('Harga Satuan')
                    ->state(fn ($record) => $record->actual_cost !== null && (float) $record->quantity_received > 0 ? (float) $record->actual_cost / (float) $record->quantity_received : null)
                    ->money('IDR')->placeholder('—')
                    ->visible(fn () => PurchaseRequestResource::canSeeCost()),
            ])
            ->defaultSort('fulfilled_at', 'desc');
    }
}