<?php

namespace App\Filament\InventoryWidgets;

use App\Models\RawMaterial;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class MaterialsNeedingAttentionWidget extends BaseWidget
{
    protected static ?string $heading = 'Bahan Baku Perlu Perhatian';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                RawMaterial::query()->where(function ($query) {
                    $query->where(fn ($q) => $q->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point'))
                        ->orWhere(fn ($q) => $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', now()->addDays(30)));
                })
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Bahan'),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok')
                    ->formatStateUsing(fn ($state, RawMaterial $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Kedaluwarsa')
                    ->date('d M Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge()
                    ->state(function (RawMaterial $record) {
                        $reasons = [];
                        if ($record->isLowStock()) $reasons[] = 'Stok Menipis';
                        if ($record->isExpired()) $reasons[] = 'Kedaluwarsa';
                        elseif ($record->isNearExpiry()) $reasons[] = 'Mendekati Kedaluwarsa';

                        return implode(' + ', $reasons);
                    })
                    ->color('danger'),
            ])
            ->paginated(false);
    }
}
