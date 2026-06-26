<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationResource\Pages;
use App\Filament\Resources\QuotationResource\RelationManagers\ItemsRelationManager;
use App\Models\Quotation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Quotation (Lead)';

    protected static ?string $modelLabel = 'Quotation';

    protected static ?string $pluralModelLabel = 'Quotation';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->hasRole('super_admin')) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        return $form->schema([
            Forms\Components\Section::make('Lead Quotation')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('quotation_number')
                        ->label('No. Quotation')
                        ->default(fn () => static::generateQuotationNumber())
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->maxLength(255),

                    Forms\Components\Select::make('status')
                        ->label('Status Follow-up')
                        ->options([
                            'new' => 'New',
                            'contacted' => 'Contacted',
                            'closed' => 'Closed',
                            'cancelled' => 'Cancelled',
                        ])
                        ->required()
                        ->default('new'),

                    Forms\Components\Select::make('vehicle_id')
                        ->label('Kendaraan')
                        ->relationship('vehicle', 'model')
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->brand} {$record->model} ({$record->size_category})")
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko/Dealer')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->visible($isSuperAdmin)
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id),

                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('customer_phone')
                        ->label('No. Telepon')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('license_plate')
                        ->label('Plat Nomor')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('message')
                        ->label('Catatan/Kebutuhan Pelanggan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('quotation_number')
                    ->label('No. Quotation')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('vehicle.model')
                    ->label('Kendaraan')
                    ->formatStateUsing(fn ($record) => $record->vehicle ? "{$record->vehicle->brand} {$record->vehicle->model}" : '—'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => 'new',
                        'warning' => 'contacted',
                        'success' => 'closed',
                        'danger' => 'cancelled',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Masuk')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'new' => 'New',
                        'contacted' => 'Contacted',
                        'closed' => 'Closed',
                        'cancelled' => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/create'),
            'edit' => Pages\EditQuotation::route('/{record}/edit'),
        ];
    }

    public static function generateQuotationNumber(): string
    {
        do {
            $candidate = 'QTN-'.now()->format('Ym').'-'.Str::upper(Str::random(4));
        } while (static::getModel()::where('quotation_number', $candidate)->exists());

        return $candidate;
    }
}
