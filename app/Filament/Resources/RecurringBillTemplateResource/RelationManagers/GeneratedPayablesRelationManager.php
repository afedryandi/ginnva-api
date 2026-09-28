<?php

namespace App\Filament\Resources\RecurringBillTemplateResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Riwayat tagihan (Hutang Usaha) yang dihasilkan template ini -- baca-saja.
 */
class GeneratedPayablesRelationManager extends RelationManager
{
    protected static string $relationship = 'generatedPayables';

    protected static ?string $title = 'Riwayat Tagihan yang Dibuat';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payable_number')
            ->columns([
                Tables\Columns\TextColumn::make('payable_number')->label('No. Tagihan')->weight('bold'),
                Tables\Columns\TextColumn::make('due_date')->label('Jatuh Tempo')->date('d M Y'),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('amount_paid')->label('Dibayar')->money('IDR', locale: 'id'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors(['danger' => 'unpaid', 'warning' => 'partial', 'success' => 'paid', 'gray' => 'cancelled'])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'unpaid' => 'Belum Dibayar',
                        'partial' => 'Dibayar Sebagian',
                        'paid' => 'Lunas',
                        'cancelled' => 'Dibatalkan',
                        default => $state,
                    }),
            ])
            ->defaultSort('due_date', 'desc')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Buka')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => \App\Filament\Resources\PayableResource::getUrl('view', ['record' => $record->id])),
            ]);
    }
}
