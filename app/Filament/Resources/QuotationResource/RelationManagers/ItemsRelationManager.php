<?php

namespace App\Filament\Resources\QuotationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Item Produk Diminati';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('film_product_id')
                ->label('Produk Film')
                ->relationship('filmProduct', 'name')
                ->searchable()
                ->preload()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('filmProduct.name')
                    ->label('Produk Film'),

                Tables\Columns\TextColumn::make('filmProduct.product_type')
                    ->label('Tipe'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
