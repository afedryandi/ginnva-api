<?php

namespace App\Filament\Resources\MaterialMemoResource\RelationManagers;

use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use App\Services\MaterialMemoStockService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Barang di Memo Ini';

    /**
     * Baris di sini TIDAK boleh diedit/dihapus lewat form biasa —
     * insert/update-nya SELALU lewat action custom di bawah (add_item /
     * return_item / edit_qty / delete_item) supaya selalu dibarengi
     * pencatatan pergerakan stok yang benar, lewat MaterialMemoStockService
     * yang SAMA PERSIS dipakai app mobile — bukan salinan logika sendiri.
     * isReadOnly() cuma matikan Create/Edit/Delete bawaan, action custom
     * tetap jalan.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item_name')
            ->columns([
                Tables\Columns\BadgeColumn::make('item_type')
                    ->label('Jenis')
                    ->colors([
                        'primary' => 'raw_material',
                        'warning' => 'consumable_item',
                        'success' => 'inventory_item',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'raw_material' => 'Bahan Baku',
                        'consumable_item' => 'Habis Pakai',
                        'inventory_item' => 'PPF/WF',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('item_name')
                    ->label('Nama Barang')
                    ->wrap(),
                Tables\Columns\TextColumn::make('qty_taken')
                    ->label('Diambil')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, $record) => $state === null ? '—' : number_format((float) $state, 2) . ' ' . $record->unit),
                Tables\Columns\TextColumn::make('qty_returned')
                    ->label('Dikembalikan')
                    ->placeholder('Belum diisi')
                    ->formatStateUsing(fn ($state, $record) => $state === null ? null : number_format((float) $state, 2) . ' ' . $record->unit),
                Tables\Columns\TextColumn::make('qty_used')
                    ->label('Terpakai')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, $record) => $state === null ? '—' : number_format((float) $state, 2) . ' ' . $record->unit),
                Tables\Columns\TextColumn::make('meters_used')
                    ->label('Meter Dipakai')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 2) . ' meter'),
                Tables\Columns\TextColumn::make('condition_notes')
                    ->label('Keterangan/Kondisi')
                    ->placeholder('—')
                    ->limit(30),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add_item')
                    ->label('Tambah Barang')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Select::make('item_type')
                            ->label('Jenis Barang')
                            ->options([
                                'raw_material' => 'Bahan Baku',
                                'consumable_item' => 'Barang Habis Pakai',
                                'inventory_item' => 'PPF/WF (Gulungan)',
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('raw_material_id')
                            ->label('Bahan Baku')
                            ->visible(fn (Forms\Get $get) => $get('item_type') === 'raw_material')
                            ->required(fn (Forms\Get $get) => $get('item_type') === 'raw_material')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => RawMaterial::where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%")
                                ->limit(20)
                                ->pluck('name', 'id'))
                            ->getOptionLabelUsing(fn ($value) => RawMaterial::find($value)?->name),

                        Forms\Components\Select::make('consumable_item_id')
                            ->label('Barang Habis Pakai')
                            ->visible(fn (Forms\Get $get) => $get('item_type') === 'consumable_item')
                            ->required(fn (Forms\Get $get) => $get('item_type') === 'consumable_item')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => ConsumableItem::where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%")
                                ->limit(20)
                                ->pluck('name', 'id'))
                            ->getOptionLabelUsing(fn ($value) => ConsumableItem::find($value)?->name),

                        Forms\Components\Select::make('inventory_item_id')
                            ->label('Barang PPF/WF')
                            ->visible(fn (Forms\Get $get) => $get('item_type') === 'inventory_item')
                            ->required(fn (Forms\Get $get) => $get('item_type') === 'inventory_item')
                            ->helperText('Cuma barang yang punya kode gulungan terkait yang muncul.')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => InventoryItem::whereNotNull('scroll_code_id')
                                ->with('scrollCode:id,code')
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                                    ->orWhere('code', 'like', "%{$search}%"))
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn ($item) => [$item->id => $item->name . ' (' . $item->scrollCode?->code . ')']))
                            ->getOptionLabelUsing(function ($value) {
                                $item = InventoryItem::with('scrollCode:id,code')->find($value);

                                return $item ? $item->name . ' (' . $item->scrollCode?->code . ')' : null;
                            }),

                        Forms\Components\TextInput::make('qty_taken')
                            ->label('Jumlah Diambil')
                            ->visible(fn (Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item'], true))
                            ->required(fn (Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item'], true))
                            ->numeric()
                            ->minValue(0.01),

                        Forms\Components\TextInput::make('meters_used')
                            ->label('Meter Dipakai')
                            ->visible(fn (Forms\Get $get) => $get('item_type') === 'inventory_item')
                            ->required(fn (Forms\Get $get) => $get('item_type') === 'inventory_item')
                            ->numeric()
                            ->minValue(0.01)
                            ->suffix('meter'),

                        Forms\Components\Textarea::make('condition_notes')
                            ->label('Keterangan/Kondisi (opsional)')
                            ->rows(2),
                    ])
                    ->action(function (array $data) {
                        $memo = $this->getOwnerRecord();
                        $userId = auth()->id();
                        $conditionNotes = $data['condition_notes'] ?? null;

                        try {
                            match ($data['item_type']) {
                                'raw_material' => MaterialMemoStockService::addMaterial(
                                    RawMaterial::findOrFail($data['raw_material_id']),
                                    'raw_material',
                                    $memo,
                                    (float) $data['qty_taken'],
                                    $userId,
                                    $conditionNotes,
                                ),
                                'consumable_item' => MaterialMemoStockService::addMaterial(
                                    ConsumableItem::findOrFail($data['consumable_item_id']),
                                    'consumable_item',
                                    $memo,
                                    (float) $data['qty_taken'],
                                    $userId,
                                    $conditionNotes,
                                ),
                                'inventory_item' => MaterialMemoStockService::addInventory(
                                    InventoryItem::findOrFail($data['inventory_item_id']),
                                    $memo,
                                    (float) $data['meters_used'],
                                    $userId,
                                    $conditionNotes,
                                ),
                            };
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Tidak bisa menambah barang')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('return_item')
                    ->label('Catat Pengembalian')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (MaterialMemoItem $record) => in_array($record->item_type, ['raw_material', 'consumable_item'], true)
                        && $record->qty_returned === null)
                    ->form(fn (MaterialMemoItem $record) => [
                        Forms\Components\TextInput::make('qty_returned')
                            ->label('Jumlah Dikembalikan')
                            ->helperText("Diambil: {$record->qty_taken} {$record->unit}. Isi 0 kalau semua terpakai habis.")
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue((float) $record->qty_taken),
                    ])
                    ->action(function (MaterialMemoItem $record, array $data) {
                        try {
                            MaterialMemoStockService::returnMaterial(
                                $record, (float) $data['qty_returned'], auth()->id(), $this->getOwnerRecord()
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa mencatat pengembalian')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('edit_qty')
                    ->label('Koreksi Jumlah')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->visible(fn (MaterialMemoItem $record) => $record->item_type === 'inventory_item' || $record->qty_returned === null)
                    ->form(fn (MaterialMemoItem $record) => [
                        Forms\Components\TextInput::make('qty')
                            ->label($record->item_type === 'inventory_item' ? 'Meter Dipakai (koreksi)' : 'Jumlah Diambil (koreksi)')
                            ->default($record->item_type === 'inventory_item' ? $record->meters_used : $record->qty_taken)
                            ->numeric()
                            ->required()
                            ->minValue(0.01),
                    ])
                    ->action(function (MaterialMemoItem $record, array $data) {
                        try {
                            if ($record->item_type === 'inventory_item') {
                                MaterialMemoStockService::updateInventoryQty($record, (float) $data['qty'], $this->getOwnerRecord());
                            } else {
                                MaterialMemoStockService::updateMaterialQty($record, (float) $data['qty'], auth()->id(), $this->getOwnerRecord());
                            }
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa mengoreksi jumlah')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('delete_item')
                    ->label('Hapus')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus Barang Ini?')
                    ->modalDescription('Stok/sisa meter yang masih tercatat keluar akan dikembalikan, baru barisnya dihapus.')
                    ->action(function (MaterialMemoItem $record) {
                        MaterialMemoStockService::reverseItem($record, auth()->id(), $this->getOwnerRecord());
                        $record->delete();
                    }),
            ]);
    }
}
