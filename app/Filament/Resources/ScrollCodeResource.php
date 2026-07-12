<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScrollCodeResource\Pages;
use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ScrollCodeExport;

class ScrollCodeResource extends Resource
{
    protected static ?string $model = ScrollCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Kode Gulungan';

    protected static ?string $modelLabel = 'Kode Gulungan';

    protected static ?string $pluralModelLabel = 'Kode Gulungan';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->hasRole('super_admin')) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')->label('Kode')->disabled(),
                    Forms\Components\TextInput::make('filmProduct.name')->label('Produk')->disabled(),
                    Forms\Components\TextInput::make('store.name')->label('Toko')->disabled(),
                    Forms\Components\TextInput::make('status')->label('Status')->disabled(),
                    Forms\Components\TextInput::make('warranty_code')->label('No. Garansi')->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('filmProduct.name')
                    ->label('Produk Film')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'gray'    => 'unallocated',
                        'warning' => 'allocated',
                        'success' => 'used',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unallocated' => 'Belum Dialokasi',
                        'allocated'   => 'Dialokasi',
                        'used'        => 'Terpakai',
                        default       => $state,
                    }),

                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('No. Garansi')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('allocated_at')
                    ->label('Dialokasi Pada')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('used_at')
                    ->label('Dipakai Pada')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'unallocated' => 'Belum Dialokasi',
                        'allocated'   => 'Dialokasi',
                        'used'        => 'Terpakai',
                    ]),

                Tables\Filters\SelectFilter::make('film_product_id')
                    ->label('Produk Film')
                    ->options(fn () => FilmProduct::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->hasRole('super_admin')),
            ])
            ->headerActions([
                // Input kode dari China — hanya super_admin
                Tables\Actions\Action::make('add_code')
                    ->label('Tambah Kode')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                    ->form([
                        Forms\Components\TextInput::make('code')
                            ->label('Kode Gulungan')
                            ->placeholder('Masukkan kode dari gulungan fisik')
                            ->required()
                            ->rules(['unique:scroll_codes,code']),

                        Forms\Components\Select::make('film_product_id')
                            ->label('Produk Film')
                            ->options(fn () => FilmProduct::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        ScrollCode::create([
                            'code'            => trim($data['code']),
                            'film_product_id' => $data['film_product_id'],
                            'status'          => 'unallocated',
                        ]);
                    }),


                // Export Excel
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                    ->action(fn () => Excel::download(
                        new ScrollCodeExport(),
                        'scroll-codes-' . now()->format('Ymd') . '.xlsx'
                    )),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (ScrollCode $record) => auth()->user()?->hasRole('super_admin')
                        && $record->status === 'unallocated'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('allocate')
                        ->label('Alokasi ke Toko')
                        ->icon('heroicon-o-building-storefront')
                        ->color('warning')
                        ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                        ->form([
                            Forms\Components\Select::make('store_id')
                                ->label('Toko Tujuan')
                                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $records->each(fn (ScrollCode $record) => $record->update([
                                'store_id'     => $data['store_id'],
                                'status'       => 'allocated',
                                'allocated_at' => now(),
                            ]));
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->hasRole('super_admin')),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScrollCodes::route('/'),
        ];
    }
}
