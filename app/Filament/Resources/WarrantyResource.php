<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarrantyResource\Pages;
use App\Models\Store;
use App\Models\Warranty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarrantyResource extends Resource
{
    protected static ?string $model = Warranty::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Garansi';

    protected static ?string $modelLabel = 'Garansi';

    protected static ?string $pluralModelLabel = 'Garansi';

    /**
     * regional_admin (admin toko) hanya melihat garansi milik store-nya,
     * atau data lama yang belum punya store_id. super_admin lihat semua.
     */
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
            Forms\Components\Section::make('Data Garansi')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('warranty_code')
                        ->label('Kode Garansi')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'active' => 'Active',
                            'expired' => 'Expired',
                            'pending' => 'Pending',
                        ])
                        ->required()
                        ->default('active'),

                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. Telepon')
                        ->tel()
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('car_plate')
                        ->label('Plat Nomor')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('car_type')
                        ->label('Tipe Mobil')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('product_series')
                        ->label('Seri Produk')
                        ->placeholder('Contoh: Ziwei 70')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('dealer_name')
                        ->label('Nama Dealer (teks bebas)')
                        ->required()
                        ->maxLength(255),

                    // Hanya super_admin yang bisa pindah-pindahkan garansi
                    // antar store. Admin toko otomatis ke store miliknya.
                    Forms\Components\Select::make('store_id')
                        ->label('Toko/Dealer')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->visible($isSuperAdmin)
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id),

                    Forms\Components\DatePicker::make('installation_date')
                        ->label('Tanggal Pasang')
                        ->required(),

                    Forms\Components\DatePicker::make('expiry_date')
                        ->label('Tanggal Berakhir')
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('car_plate')
                    ->label('Plat Nomor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('product_series')
                    ->label('Produk')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'expired',
                        'warning' => 'pending',
                    ]),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Berakhir')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('remaining_days')
                    ->label('Sisa Hari')
                    ->sortable(false),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'expired' => 'Expired',
                        'pending' => 'Pending',
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarranties::route('/'),
            'create' => Pages\CreateWarranty::route('/create'),
            'edit' => Pages\EditWarranty::route('/{record}/edit'),
        ];
    }
}
