<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockOpnameResource\Pages;
use App\Filament\Resources\StockOpnameResource\RelationManagers\ItemsRelationManager;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\StockOpname;
use App\Models\Store;
use App\Services\StockOpnameService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * "Stok Opname" -- keputusan atasan 2026-09-19 (Topik 4, Fase 1,
 * "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"). List + View saja (sama
 * pola dgn StockWriteOffResource) -- sesi baru dibuat lewat aksi header
 * "Buat Sesi Stok Opname" (bukan CreateRecord biasa, karena penyesuaian
 * kuantitas WAJIB lewat StockOpnameService/adjustStock(), bukan
 * mass-assignment langsung). Lihat catatan lengkap di StockOpnameService.
 */
class StockOpnameResource extends Resource
{
    protected static ?string $model = StockOpname::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?string $navigationGroup = 'Kelola Stok';

    protected static ?int $navigationSort = 112;

    protected static ?string $navigationLabel = 'Stok Opname';

    protected static ?string $modelLabel = 'Stok Opname';

    protected static ?string $pluralModelLabel = 'Stok Opname';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(static::class);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    // Sesi dibuat lewat aksi header "Buat Sesi Stok Opname" (form modal,
    // panggil StockOpnameService), bukan CreateRecord/EditRecord biasa
    // -- penyesuaian stok WAJIB lewat adjustStock() yang sudah teruji,
    // form Filament default (mass-assignment) akan melewatinya.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Sama pola dgn StockWriteOffResource -- Global Scope
        // (HasStoreScope) jadi proteksi utama, filter manual di sini
        // eksplisit supaya konsisten & tidak diam-diam bergantung
        // sepenuhnya pada Global Scope.
        $query = parent::getEloquentQuery()->with(['store', 'creator']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('opname_number')
                    ->label('Nomor')
                    ->searchable(),
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko'),
                Tables\Columns\TextColumn::make('opname_date')
                    ->label('Tanggal Opname')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Jumlah Item')
                    ->counts('items'),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('create_opname')
                    ->label('Buat Sesi Stok Opname')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->modalWidth('4xl')
                    ->form([
                        Forms\Components\Select::make('store_id')
                            ->label('Toko')
                            ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                            ->default(fn () => auth()->user()?->isFullAccess() ? null : auth()->user()?->store_id)
                            ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                            ->dehydrated()
                            ->required(),
                        Forms\Components\DatePicker::make('opname_date')
                            ->label('Tanggal Opname')
                            ->default(now())
                            ->native(false)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan (opsional)')
                            ->rows(2),
                        Forms\Components\Repeater::make('items')
                            ->label('Item yang Dihitung')
                            ->addActionLabel('+ Tambah item')
                            ->reorderable(false)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->columns(3)
                            ->schema([
                                Forms\Components\Select::make('item_type')
                                    ->label('Jenis')
                                    ->options([
                                        'raw_material' => 'Bahan Baku',
                                        'consumable_item' => 'Barang Habis Pakai',
                                    ])
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('item_id', null)),
                                Forms\Components\Select::make('item_id')
                                    ->label('Item')
                                    ->options(fn (Forms\Get $get) => match ($get('item_type')) {
                                        'raw_material' => RawMaterial::orderBy('name')->get()
                                            ->mapWithKeys(fn (RawMaterial $m) => [$m->id => "{$m->name} ({$m->code}) — stok sistem: {$m->current_stock} {$m->unit}"])->all(),
                                        'consumable_item' => ConsumableItem::orderBy('name')->get()
                                            ->mapWithKeys(fn (ConsumableItem $c) => [$c->id => "{$c->name} ({$c->code}) — stok sistem: {$c->current_stock} {$c->unit}"])->all(),
                                        default => [],
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Forms\Components\TextInput::make('actual_quantity')
                                    ->label('Hasil Hitung Fisik')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ]),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(StockOpnameService::class)->create(
                                (int) $data['store_id'],
                                $data['opname_date'],
                                $data['notes'] ?: null,
                                $data['items'],
                                auth()->id()
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Sesi Stok Opname dicatat, stok disesuaikan.')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListStockOpnames::route('/'),
            'view'  => Pages\ViewStockOpname::route('/{record}'),
        ];
    }
}
