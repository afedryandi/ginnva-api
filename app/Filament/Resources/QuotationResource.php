<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationResource\Pages;
use App\Models\Quotation;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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

            /* ── INFO UTAMA ─────────────────────────────────────────── */
            Forms\Components\Section::make('Informasi Quotation')
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
                            'new'       => 'New',
                            'contacted' => 'Contacted',
                            'closed'    => 'Closed',
                            'cancelled' => 'Cancelled',
                        ])
                        ->required()
                        ->default('new'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko/Dealer')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->visible($isSuperAdmin)
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id),
                ]),

            /* ── DATA PELANGGAN ─────────────────────────────────────── */
            Forms\Components\Section::make('Data Pelanggan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('customer_phone')
                        ->label('No. Telepon / WhatsApp')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('customer_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('license_plate')
                        ->label('Plat Nomor')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('message')
                        ->label('Catatan / Kebutuhan Pelanggan')
                        ->columnSpanFull(),
                ]),

            /* ── KENDARAAN (cascading brand → tipe) ────────────────── */
            Forms\Components\Section::make('Kendaraan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('vehicle_brand')
                        ->label('Merek Kendaraan')
                        ->options(
                            Vehicle::query()
                                ->distinct()
                                ->orderBy('brand')
                                ->pluck('brand', 'brand')
                        )
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('vehicle_id', null))
                        ->required()
                        ->dehydrated(false),  // tidak disimpan ke DB

                    Forms\Components\Select::make('vehicle_id')
                        ->label('Tipe Kendaraan')
                        ->options(fn (Get $get) => Vehicle::query()
                            ->where('brand', $get('vehicle_brand'))
                            ->orderBy('model')
                            ->get()
                            ->mapWithKeys(fn ($v) => [$v->id => "{$v->model} (Size {$v->size_category})"])
                        )
                        ->disabled(fn (Get $get) => blank($get('vehicle_brand')))
                        ->live()
                        ->required()
                        ->searchable(),
                ]),

            /* ── PRODUK YANG DIMINATI ───────────────────────────────── */
            Forms\Components\Section::make('Produk yang Diminati')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship('items')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('film_product_id')
                                ->label('Produk Film')
                                ->relationship('filmProduct', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Jumlah')
                                ->numeric()
                                ->default(1)
                                ->minValue(1)
                                ->required(),

                            Forms\Components\Textarea::make('notes')
                                ->label('Keterangan')
                                ->placeholder('Contoh: warna preferensi, bagian kendaraan tertentu')
                                ->columnSpan(2),
                        ])
                        ->columns(3)
                        ->addActionLabel('+ Tambah Produk')
                        ->reorderable(false)
                        ->defaultItems(1),
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
                    ->formatStateUsing(fn ($record) => $record->vehicle
                        ? "{$record->vehicle->brand} {$record->vehicle->model}"
                        : '—'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'info'    => 'new',
                        'warning' => 'contacted',
                        'success' => 'closed',
                        'danger'  => 'cancelled',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Masuk')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'new'       => 'New',
                        'contacted' => 'Contacted',
                        'closed'    => 'Closed',
                        'cancelled' => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            \App\Filament\Resources\QuotationResource\RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/create'),
            'view'   => Pages\ViewQuotation::route('/{record}'),
            'edit'   => Pages\EditQuotation::route('/{record}/edit'),
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
