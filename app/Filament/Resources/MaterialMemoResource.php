<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialMemoResource\Pages;
use App\Filament\Resources\MaterialMemoResource\RelationManagers\ItemsRelationManager;
use App\Models\MaterialMemo;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaterialMemoResource extends Resource
{
    protected static ?string $model = MaterialMemo::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Inventaris';
    protected static ?string $navigationLabel = 'Memo Pengambilan/Pengembalian';

    protected static ?string $modelLabel = 'Memo Pengambilan/Pengembalian';

    protected static ?string $pluralModelLabel = 'Memo Pengambilan/Pengembalian';

    protected static ?int $navigationSort = 70;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Info Memo')->schema([
                Forms\Components\Select::make('store_id')
                    ->label('Toko / Workshop')
                    ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                    ->disabled(! $isSuperAdmin)
                    ->dehydrated(),
                Forms\Components\TextInput::make('vehicle_info')
                    ->label('Info Kendaraan')
                    ->placeholder('Mis. Toyota Avanza - B 1234 XYZ')
                    ->maxLength(255),
                Forms\Components\TextInput::make('spk_number')
                    ->label('SPK No (opsional)')
                    ->maxLength(100),
                Forms\Components\Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('memo_number')
                    ->label('No Memo')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->searchable(),
                Tables\Columns\TextColumn::make('vehicle_info')
                    ->label('Kendaraan')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('spk_number')
                    ->label('SPK No')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Jumlah Barang')
                    ->counts('items')
                    ->badge(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id')),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterialMemos::route('/'),
            'create' => Pages\CreateMaterialMemo::route('/create'),
            'edit' => Pages\EditMaterialMemo::route('/{record}/edit'),
        ];
    }
}
