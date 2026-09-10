<?php

namespace App\Filament\Resources\MaterialMemoResource\RelationManagers;

use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemoItem;
use App\Models\FilmProduct;
use App\Models\RawMaterial;
use App\Services\MaterialMemoStockService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

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

    /**
     * Produk Film yang resepnya bisa dipakai auto-isi memo ini:
     * varian film yang dipasang (film_product_id) + produk "Detailing"
     * kalau booking-nya ber-product_detailing. Di-unique per id supaya
     * kalau kebetulan film_product_id itu SVC-DETAILING sendiri tidak
     * dobel.
     */
    protected function recipeSourceProducts(): Collection
    {
        $booking = $this->getOwnerRecord()->booking;

        if ($booking === null) {
            return collect();
        }

        return collect([
            $booking->filmProduct,
            $booking->product_detailing ? FilmProduct::detailing() : null,
        ])->filter()->unique('id')->values();
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
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu Diambil')
                    ->dateTime('d M Y H:i'),
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

                // "Isi dari Master Resep" — diminta 2026-09-10. Kalau memo
                // tertaut ke booking, tombol ini menambahkan bahan dari
                // Master Resep (BOM) sekaligus ke memo lewat
                // MaterialMemoStockService yang SAMA (stok ikut berkurang,
                // bukan insert baris mentah).
                //
                // Sumber resep = varian film yang dipasang (film_product_id)
                // + produk "Detailing" kalau booking ber-product_detailing
                // (lihat recipeSourceProducts()).
                //
                // Baris resep 'film_roll' DILEWATI — auto-fill tidak tahu
                // gulungan spesifik mana yang dipakai (itu dipilih saat
                // pemakaian nyata), jadi roll tetap ditambah manual lewat
                // "Tambah Barang". Bahan yang SUDAH ada di memo juga
                // dilewati supaya aman diklik berulang (memo boleh diedit
                // di tengah pekerjaan).
                Tables\Actions\Action::make('isi_dari_resep')
                    ->label('Isi dari Master Resep')
                    ->icon('heroicon-o-beaker')
                    ->color('info')
                    ->visible(fn () => $this->recipeSourceProducts()
                        ->contains(fn (FilmProduct $p) => $p->recipeItems()
                            ->whereIn('item_type', ['raw_material', 'consumable_item'])
                            ->where('standard_qty', '>', 0)
                            ->exists()))
                    ->requiresConfirmation()
                    ->modalHeading('Isi Barang dari Master Resep')
                    ->modalDescription(function () {
                        $blocks = $this->recipeSourceProducts()
                            ->map(function (FilmProduct $product) {
                                $lines = $product->recipeItems
                                    ->map(fn ($r) => '• ' . e($r->item_name) . ': ' . rtrim(rtrim(number_format((float) $r->standard_qty, 2), '0'), '.') . ' ' . e($r->unit ?? '')
                                        . ($r->item_type === 'film_roll' ? ' <em>(roll — dilewati, tambah manual)</em>' : ''))
                                    ->implode('<br>');

                                return '<strong>' . e($product->name) . '</strong><br>' . ($lines ?: '<em>(resep kosong)</em>');
                            })
                            ->implode('<br><br>');

                        return new HtmlString(
                            'Bahan berikut akan ditambahkan ke memo. Stok ikut berkurang. Bahan yang sudah ada di memo dilewati.<br><br>' . $blocks
                        );
                    })
                    ->modalSubmitActionLabel('Tambahkan')
                    ->action(function () {
                        $memo = $this->getOwnerRecord();
                        $sources = $this->recipeSourceProducts();

                        if ($sources->isEmpty()) {
                            return;
                        }

                        $userId = auth()->id();
                        $added = 0;
                        $skippedExisting = 0;
                        $skippedRoll = 0;
                        $failed = [];

                        foreach ($sources as $product) {
                            foreach ($product->recipeItems as $recipe) {
                                if ($recipe->item_type === 'film_roll') {
                                    $skippedRoll++;

                                    continue;
                                }

                                if ((float) $recipe->standard_qty <= 0) {
                                    continue;
                                }

                                $model = match ($recipe->item_type) {
                                    'raw_material' => RawMaterial::find($recipe->item_id),
                                    'consumable_item' => ConsumableItem::find($recipe->item_id),
                                    default => null,
                                };

                                if ($model === null) {
                                    continue;
                                }

                                $alreadyInMemo = $memo->items()
                                    ->where('item_type', $recipe->item_type)
                                    ->where('item_id', $model->id)
                                    ->exists();

                                if ($alreadyInMemo) {
                                    $skippedExisting++;

                                    continue;
                                }

                                try {
                                    MaterialMemoStockService::addMaterial(
                                        $model,
                                        $recipe->item_type,
                                        $memo,
                                        (float) $recipe->standard_qty,
                                        $userId,
                                        'Auto dari Master Resep (' . $product->sku . ')',
                                    );
                                    $added++;
                                } catch (\InvalidArgumentException $e) {
                                    $failed[] = $model->name . ' — ' . $e->getMessage();
                                }
                            }
                        }

                        $body = "{$added} bahan ditambahkan ke memo.";
                        if ($skippedExisting > 0) {
                            $body .= " {$skippedExisting} sudah ada, dilewati.";
                        }
                        if ($skippedRoll > 0) {
                            $body .= " {$skippedRoll} baris Roll Film dilewati — tambahkan manual lewat \"Tambah Barang\" (pilih gulungan).";
                        }
                        if (! empty($failed)) {
                            $body .= ' Gagal: ' . implode('; ', $failed);
                        }

                        Notification::make()
                            ->title($added > 0 ? 'Barang ditambahkan dari resep' : 'Tidak ada barang baru ditambahkan')
                            ->body($body)
                            ->{! empty($failed) ? 'warning' : 'success'}()
                            ->send();
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

                        // Opsional — sistem TIDAK PERNAH tahu harga batch
                        // asli yang dulu dikonsumsi (movement 'out' tidak
                        // menyimpan unit_cost), jadi tanpa ini otomatis
                        // pakai "harga terakhir tersimpan" sebagai
                        // perkiraan. Isi kalau admin kebetulan tahu harga
                        // sebenarnya, supaya valuasi stok tidak makin kabur.
                        Forms\Components\TextInput::make('unit_cost')
                            ->label('Harga per Satuan (opsional)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->helperText('Kosongkan untuk pakai harga terakhir tersimpan.'),
                    ])
                    ->action(function (MaterialMemoItem $record, array $data) {
                        try {
                            MaterialMemoStockService::returnMaterial(
                                $record,
                                (float) $data['qty_returned'],
                                auth()->id(),
                                $this->getOwnerRecord(),
                                isset($data['unit_cost']) && $data['unit_cost'] !== '' ? (float) $data['unit_cost'] : null,
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

                        // Cuma relevan kalau koreksi ini TURUN (sebagian
                        // dikembalikan ke stok) — lihat catatan yang sama
                        // di aksi "Catat Pengembalian" di atas.
                        Forms\Components\TextInput::make('unit_cost')
                            ->label('Harga per Satuan (opsional)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->helperText('Kosongkan untuk pakai harga terakhir tersimpan. Cuma relevan kalau koreksi ini menurunkan jumlah (sebagian balik ke stok).')
                            ->visible(fn () => $record->item_type !== 'inventory_item'),
                    ])
                    ->action(function (MaterialMemoItem $record, array $data) {
                        try {
                            if ($record->item_type === 'inventory_item') {
                                MaterialMemoStockService::updateInventoryQty($record, (float) $data['qty'], $this->getOwnerRecord());
                            } else {
                                MaterialMemoStockService::updateMaterialQty(
                                    $record,
                                    (float) $data['qty'],
                                    auth()->id(),
                                    $this->getOwnerRecord(),
                                    isset($data['unit_cost']) && $data['unit_cost'] !== '' ? (float) $data['unit_cost'] : null,
                                );
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
