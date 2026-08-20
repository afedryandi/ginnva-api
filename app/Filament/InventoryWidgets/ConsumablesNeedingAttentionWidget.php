<?php

namespace App\Filament\InventoryWidgets;

use App\Filament\Resources\ConsumableItemResource;
use App\Models\ConsumableItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ConsumablesNeedingAttentionWidget extends BaseWidget
{
    protected static ?string $heading = 'Barang Habis Pakai Menipis';

    protected int|string|array $columnSpan = 'full';

    // Lihat catatan di InventoryStatsOverview — lazy load default Filament
    // menyebabkan request Livewire susulan yang gagal 419.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(ConsumableItemResource::class) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ConsumableItem::query()
                    ->whereNotNull('reorder_point')
                    ->whereColumn('current_stock', '<=', 'reorder_point')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Barang'),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok')
                    ->formatStateUsing(fn ($state, ConsumableItem $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->label('Ambang Stok Menipis')
                    ->formatStateUsing(fn ($state, ConsumableItem $record) => number_format((float) $state, 2) . ' ' . $record->unit),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge()
                    ->state('Stok Menipis')
                    ->color('danger'),
            ])
            ->paginated(false)
            // Matikan auto-refresh berkala — sama alasannya dengan
            // MaterialsNeedingAttentionWidget.
            ->poll(null);
    }
}
