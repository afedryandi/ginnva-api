<?php

namespace App\Filament\Resources\CustomerGroupResource\RelationManagers;

use App\Models\FilmProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Harga khusus per produk untuk grup ini. Produk tanpa harga khusus memakai harga normal dikurangi Diskon (%) grup. */
class GroupPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'groupPrices';

    protected static ?string $title = 'Harga Khusus Produk';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('film_product_id')
                ->label('Produk')
                ->options(fn () => FilmProduct::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('customer_group_id', $this->getOwnerRecord()->getKey()))
                ->validationMessages(['unique' => 'Produk ini sudah punya harga khusus untuk grup ini.']),

            Forms\Components\TextInput::make('price')
                ->label('Harga Khusus (flat)')
                ->numeric()
                ->prefix('Rp')
                ->minValue(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('filmProduct.name')->label('Produk')->searchable(),
                Tables\Columns\TextColumn::make('price')->label('Harga Khusus')->money('IDR')->sortable(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Tambah Harga Khusus')])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
