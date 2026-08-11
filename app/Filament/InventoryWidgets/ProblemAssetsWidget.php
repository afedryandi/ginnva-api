<?php

namespace App\Filament\InventoryWidgets;

use App\Models\Asset;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ProblemAssetsWidget extends BaseWidget
{
    protected static ?string $heading = 'Aset Bermasalah';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Asset::query()->whereIn('status', ['rusak', 'diperbaiki', 'hilang']))
            ->columns([
                Tables\Columns\TextColumn::make('asset_tag')
                    ->label('Kode')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Aset'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'diperbaiki',
                        'danger'  => ['rusak', 'hilang'],
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'diperbaiki' => 'Diperbaiki',
                        'rusak'      => 'Rusak',
                        'hilang'     => 'Hilang',
                        default      => $state,
                    }),

                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Dipegang Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Lokasi')
                    ->placeholder('Kantor Pusat'),
            ])
            ->paginated(false);
    }
}
