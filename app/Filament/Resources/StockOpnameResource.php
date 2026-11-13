<?php

namespace App\Filament\Resources;

use App\Exports\StockOpnameSheetExport;
use App\Filament\Resources\StockOpnameResource\Pages;
use App\Filament\Resources\StockOpnameResource\RelationManagers\ItemsRelationManager;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\StockOpname;
use App\Models\Store;
use App\Services\OpnameNeedsNoteException;
use App\Services\StockCardService;
use App\Services\StockOpnameService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * "Stok Opname" -- keputusan atasan 2026-09-19 (Topik 4, Fase 1,
 * "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"). List + View saja (sama
 * pola dgn StockWriteOffResource) -- sesi baru dibuat lewat aksi header
 * "Buat Sesi Stok Opname" (bukan CreateRecord biasa, karena penyesuaian
 * kuantitas WAJIB lewat StockOpnameService/adjustStock(), bukan
 * mass-assignment langsung). Lihat catatan lengkap di StockOpnameService.
 *
 * Audit 2026-11-03: akses eksplisit + izin Tambah untuk membuat sesi, alur dua tahap (draf lalu diterapkan akses penuh), hitung buta
 * (stok sistem tidak ditampilkan saat menghitung), lembar hitung dan impor hasil hitung dari Excel, ringkasan selisih, filter, export.
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

    /** Eksplisit (bukan akses bawaan): sesi opname mengubah stok seluruh persediaan (audit Stok Opname 2026-11-03). */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false) && $user->hasExplicitMenuAccess(static::class);
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

    /** Boleh menyetor hasil hitung (draf): akses penuh atau izin Tambah pada menu ini. */
    public static function canSubmit(): bool
    {
        $user = auth()->user();

        return static::canAccess() && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'create', false));
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * Bug diperbaiki 2026-09-30 (audit Stok Opname) -- SEBELUMNYA tidak ada override sama sekali
     * di sini dan tidak ada StockOpnamePolicy terdaftar, canView() bawaan Resource selalu FALSE
     * tanpa policy (default-deny Laravel, sama bug class dengan ScrollCodeResource/dll di
     * audit-audit sebelumnya). ViewAction di table() DAN halaman getPages()['view'] (satu-satunya
     * cara lihat rincian item lewat ItemsRelationManager) jadi tidak terjangkau sama sekali.
     */
    public static function canView($record): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        // Sama pola dgn StockWriteOffResource -- Global Scope
        // (HasStoreScope) jadi proteksi utama, filter manual di sini
        // eksplisit supaya konsisten & tidak diam-diam bergantung
        // sepenuhnya pada Global Scope.
        $query = parent::getEloquentQuery()
            ->with(['store', 'creator', 'applier', 'canceller'])
            ->withCount(['items as diff_count' => fn ($q) => $q->where('delta', '!=', 0)]);
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

    // ------------------------------------------------------------- pencarian item (hitung buta: tanpa stok sistem)

    /** @return array<int, string> */
    public static function searchItems(?string $type, string $search): array
    {
        $model = match ($type) {
            'raw_material' => RawMaterial::class,
            'consumable_item' => ConsumableItem::class,
            default => null,
        };

        if ($model === null) {
            return [];
        }

        // Barang nonaktif hanya bila masih berstok (boleh dihitung ulang), sama aturan dengan Daftar Stok.
        return $model::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('current_stock', '>', 0))
            ->when(trim($search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%' . trim($search) . '%')->orWhere('code', 'like', '%' . trim($search) . '%')))
            ->orderBy('name')->limit(50)->get()
            ->mapWithKeys(fn ($m) => [$m->id => $m->name . ($m->code ? " ({$m->code})" : '') . ' · ' . $m->unit])
            ->all();
    }

    public static function itemLabel(?string $type, mixed $id): ?string
    {
        $model = match ($type) {
            'raw_material' => RawMaterial::class,
            'consumable_item' => ConsumableItem::class,
            default => null,
        };
        $item = $model && $id ? $model::query()->find($id) : null;

        return $item ? $item->name . ($item->code ? " ({$item->code})" : '') . ' · ' . $item->unit : null;
    }

    // ------------------------------------------------------------- aksi bersama

    /** Kolom toko dipaksa di sisi server: staf non-akses-penuh hanya boleh tokonya sendiri. */
    private static function resolveStoreId(array $data): int
    {
        $user = auth()->user();

        return $user->isFullAccess() ? (int) ($data['store_id'] ?? 0) : (int) $user->store_id;
    }

    private static function storeField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('store_id')
            ->label('Toko')
            ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
            ->default(fn () => auth()->user()?->isFullAccess() ? null : auth()->user()?->store_id)
            ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
            ->dehydrated()
            ->required();
    }

    private static function applyNowField(): Forms\Components\Toggle
    {
        return Forms\Components\Toggle::make('apply_now')
            ->label('Langsung terapkan ke stok')
            ->helperText('Hanya akses penuh. Bila dimatikan, hasil hitung tersimpan sebagai draf dan stok baru berubah setelah diterapkan.')
            ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
            ->default(false);
    }

    /** Simpan hasil hitung (draf), dan terapkan langsung bila diminta oleh akses penuh. */
    private static function submitSession(array $data, array $items): void
    {
        $user = auth()->user();
        $storeId = static::resolveStoreId($data);

        if ($storeId <= 0) {
            Notification::make()->title('Akun Anda belum terikat ke toko, sesi tidak bisa dibuat.')->danger()->send();

            return;
        }

        try {
            $service = app(StockOpnameService::class);
            $opname = $service->submit($storeId, $data['opname_date'], ($data['notes'] ?? null) ?: null, $items, auth()->id());

            if (($data['apply_now'] ?? false) && $user->isFullAccess()) {
                $service->apply($opname, auth()->id());
                Notification::make()->title('Sesi Stok Opname dicatat, stok disesuaikan.')->success()->send();

                return;
            }
        } catch (OpnameNeedsNoteException $e) {
            Notification::make()->title($e->getMessage())->body(collect($e->problems)->pluck('message')->implode("\n"))->danger()->persistent()->send();

            return;
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title("Hasil hitung dicatat sebagai draf {$opname->opname_number}")
            ->body('Stok belum berubah. Akses penuh meninjau selisihnya lalu menerapkannya.')
            ->success()
            ->send();
    }

    public static function applyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('apply')
            ->label('Terapkan')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (StockOpname $record) => (auth()->user()?->isFullAccess() ?? false) && $record->isDraft())
            ->requiresConfirmation()
            ->modalHeading(fn (StockOpname $record) => "Terapkan {$record->opname_number}?")
            ->modalDescription(function (StockOpname $record) {
                $diff = $record->items()->where('delta', '!=', 0)->count();
                $total = $record->items()->count();

                return "{$diff} dari {$total} item berselisih. Selisih hasil hitung diterapkan ke stok terkini, jadi barang yang keluar atau masuk sejak dihitung ({$record->snapshot_at?->format('d M Y H:i')}) tidak ikut merusak hasilnya. Setelah diterapkan, sesi tidak bisa dibatalkan; koreksi dengan menghitung ulang.";
            })
            ->action(function (StockOpname $record) {
                try {
                    app(StockOpnameService::class)->apply($record, auth()->id());
                } catch (RuntimeException $e) {
                    Notification::make()->title('Tidak bisa menerapkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Sesi diterapkan, stok disesuaikan.')->success()->send();
            });
    }

    public static function discardAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('discard')
            ->label('Buang Draf')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (StockOpname $record) => $record->isDraft()
                && ((auth()->user()?->isFullAccess() ?? false) || (int) auth()->id() === (int) $record->created_by))
            ->form([
                Forms\Components\Textarea::make('reason')->label('Alasan')->required()->maxLength(500)->rows(2)
                    ->helperText('Mis. salah toko, hitung ulang, atau barang belum selesai dihitung.'),
            ])
            ->modalDescription('Draf dibuang tanpa mengubah stok. Sesinya tetap tersimpan bertanda Dibuang.')
            ->action(function (StockOpname $record, array $data) {
                try {
                    app(StockOpnameService::class)->discard($record, auth()->id(), $data['reason']);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Tidak bisa membuang')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Draf dibuang')->success()->send();
            });
    }

    // ------------------------------------------------------------- lembar hitung & impor

    /**
     * Baca lembar hitung (hasil unduhan "Unduh Lembar Hitung" yang sudah diisi). Kolom dikenali dari judulnya: Jenis, ID, Hasil Hitung,
     * Catatan. Baris tanpa Hasil Hitung dilewati (belum dihitung); angka tidak valid dilaporkan, bukan ditebak.
     *
     * @return array{items: list<array<string, mixed>>, errors: list<string>}
     */
    public static function parseCountSheet(string $fullPath): array
    {
        $rows = Excel::toArray(null, $fullPath)[0] ?? [];
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $col = fn (string $name) => array_search($name, $header, true);
        [$typeCol, $idCol, $qtyCol, $noteCol] = [$col('jenis'), $col('id'), $col('hasil hitung'), $col('catatan')];

        if ($typeCol === false || $idCol === false || $qtyCol === false) {
            return ['items' => [], 'errors' => ['Kolom "Jenis", "ID", dan "Hasil Hitung" tidak ditemukan. Pakai file dari tombol "Download Lembar Hitung".']];
        }

        $items = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $raw = $row[$qtyCol] ?? null;

            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }

            $type = match (mb_strtolower(trim((string) ($row[$typeCol] ?? '')))) {
                'bahan baku', 'raw_material' => 'raw_material',
                'barang habis pakai', 'consumable_item' => 'consumable_item',
                default => null,
            };
            $quantity = str_replace(',', '.', trim((string) $raw));

            if ($type === null || ! is_numeric($row[$idCol] ?? null)) {
                $errors[] = "Baris {$line}: jenis atau ID tidak dikenal.";

                continue;
            }

            if (! is_numeric($quantity) || (float) $quantity < 0) {
                $errors[] = "Baris {$line}: hasil hitung \"{$raw}\" bukan angka yang valid.";

                continue;
            }

            $items[] = [
                'item_type' => $type,
                'item_id' => (int) $row[$idCol],
                'actual_quantity' => (float) $quantity,
                'note' => $noteCol !== false ? (trim((string) ($row[$noteCol] ?? '')) ?: null) : null,
            ];
        }

        return ['items' => $items, 'errors' => $errors];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn (StockOpname $record) => $record->status === StockOpname::STATUS_CANCELLED ? 'opacity-60' : null)
            ->columns([
                Tables\Columns\TextColumn::make('opname_number')
                    ->label('Nomor')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        StockOpname::STATUS_DRAFT => 'warning',
                        StockOpname::STATUS_APPLIED => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => StockOpname::STATUS_LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko'),
                Tables\Columns\TextColumn::make('opname_date')
                    ->label('Tanggal Opname')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Jumlah Item')
                    ->counts('items'),
                Tables\Columns\TextColumn::make('diff_count')
                    ->label('Berselisih')
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(StockOpname::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                Tables\Filters\TernaryFilter::make('has_difference')
                    ->label('Ada selisih')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya yang berselisih')
                    ->falseLabel('Tanpa selisih')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('items', fn ($q) => $q->where('delta', '!=', 0)),
                        false: fn (Builder $query) => $query->whereDoesntHave('items', fn ($q) => $q->where('delta', '!=', 0)),
                        blank: fn (Builder $query) => $query,
                    ),
                Tables\Filters\Filter::make('opname_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q, $date) => $q->whereDate('opname_date', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->whereDate('opname_date', '<=', $date))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('create_opname')
                    ->label('Buat Sesi Stok Opname')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->visible(fn () => static::canSubmit())
                    ->modalWidth('4xl')
                    ->form([
                        static::storeField(),
                        Forms\Components\DatePicker::make('opname_date')
                            ->label('Tanggal Opname')
                            ->default(now())
                            ->maxDate(now())
                            ->native(false)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan (opsional)')
                            ->rows(2),
                        static::applyNowField(),
                        // Mengisi daftar dengan semua barang satu kategori (hasil hitung dikosongkan), seperti menghitung satu rak.
                        Forms\Components\Select::make('fill_category')
                            ->label('Isi dari kategori (opsional)')
                            ->options(fn () => app(StockCardService::class)->categories())
                            ->searchable()
                            ->placeholder('Pilih kategori untuk menambahkan semua barangnya')
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Forms\Get $get, Forms\Set $set) {
                                if (! $state) {
                                    return;
                                }

                                $existing = collect($get('items') ?? [])->filter(fn ($row) => ! empty($row['item_id']));
                                $known = $existing->map(fn ($row) => ($row['item_type'] ?? '') . ':' . $row['item_id'])->all();
                                $added = [];

                                foreach ([['raw_material', RawMaterial::class], ['consumable_item', ConsumableItem::class]] as [$type, $model]) {
                                    $model::query()
                                        ->where('category', $state)
                                        ->where(fn ($q) => $q->where('is_active', true)->orWhere('current_stock', '>', 0))
                                        ->orderBy('name')->get(['id'])
                                        ->each(function ($item) use ($type, $known, &$added) {
                                            if (! in_array("{$type}:{$item->id}", $known, true)) {
                                                $added[(string) \Illuminate\Support\Str::uuid()] = ['item_type' => $type, 'item_id' => $item->id, 'actual_quantity' => null, 'note' => null];
                                            }
                                        });
                                }

                                $set('items', $existing->all() + $added);
                                $set('fill_category', null);
                            }),
                        Forms\Components\Repeater::make('items')
                            ->label('Item yang Dihitung')
                            ->addActionLabel('+ Tambah item')
                            ->reorderable(false)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->columns(4)
                            ->schema([
                                Forms\Components\Select::make('item_type')
                                    ->label('Jenis')
                                    ->options(\App\Support\HiddenInventoryTypes::filter([
                                        'raw_material' => 'Bahan Baku',
                                        'consumable_item' => 'Barang Habis Pakai',
                                    ]))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (Forms\Set $set) => $set('item_id', null)),
                                Forms\Components\Select::make('item_id')
                                    ->label('Item')
                                    ->getSearchResultsUsing(fn (string $search, Forms\Get $get) => static::searchItems($get('item_type'), $search))
                                    ->getOptionLabelUsing(fn ($value, Forms\Get $get) => static::itemLabel($get('item_type'), $value))
                                    ->searchable()
                                    ->required(),
                                Forms\Components\TextInput::make('actual_quantity')
                                    ->label('Hasil Hitung Fisik')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                                Forms\Components\TextInput::make('note')
                                    ->label('Catatan')
                                    ->maxLength(255)
                                    ->helperText('Wajib bila selisihnya besar.'),
                            ]),
                    ])
                    ->action(fn (array $data) => static::submitSession($data, $data['items'])),

                Tables\Actions\Action::make('download_sheet')
                    ->label('Download Lembar Hitung')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('format')->label('Format')->options(['xlsx' => 'Excel (bisa diisi lalu diimpor)', 'pdf' => 'PDF (untuk dicetak)'])->default('xlsx')->required()->native(false),
                        Forms\Components\Select::make('jenis')->label('Jenis')->options(\App\Support\HiddenInventoryTypes::filter(['all' => 'Semua', 'raw_material' => 'Bahan Baku', 'consumable_item' => 'Barang Habis Pakai']))->default('all')->required()->native(false),
                        Forms\Components\Select::make('category')->label('Kategori (opsional)')->options(fn () => app(StockCardService::class)->categories())->searchable()->placeholder('Semua kategori'),
                    ])
                    ->modalDescription('Lembar berisi daftar barang dengan kolom hasil hitung kosong. Stok sistem sengaja tidak dicantumkan (hitung buta).')
                    ->action(function (array $data) {
                        $rows = StockOpnameSheetExport::rows($data['jenis'], $data['category'] ?? null);

                        if ($data['format'] === 'pdf') {
                            $pdf = Pdf::loadView('pdf.stock_opname_sheet', ['rows' => $rows, 'date' => now()])->setPaper('a4', 'portrait');

                            return response()->streamDownload(fn () => print($pdf->output()), 'lembar-hitung-' . now()->format('Ymd-His') . '.pdf');
                        }

                        return Excel::download(new StockOpnameSheetExport($rows), 'lembar-hitung-' . now()->format('Ymd-His') . '.xlsx');
                    }),

                Tables\Actions\Action::make('import_count')
                    ->label('Import Hasil Hitung')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn () => static::canSubmit())
                    ->form([
                        static::storeField(),
                        Forms\Components\DatePicker::make('opname_date')->label('Tanggal Opname')->default(now())->maxDate(now())->native(false)->required(),
                        Forms\Components\Textarea::make('notes')->label('Catatan (opsional)')->rows(2),
                        static::applyNowField(),
                        Forms\Components\FileUpload::make('file')
                            ->label('Lembar hitung yang sudah diisi')
                            ->required()
                            ->disk('local')
                            ->directory('opname-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai file dari "Download Lembar Hitung" (Excel). Baris tanpa Hasil Hitung dilewati.'),
                    ])
                    ->modalSubmitActionLabel('Import')
                    ->action(function (array $data) {
                        $path = $data['file'];

                        try {
                            $parsed = static::parseCountSheet(Storage::disk('local')->path($path));
                        } catch (\Throwable $e) {
                            Storage::disk('local')->delete($path);
                            Notification::make()->title('Gagal membaca file')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Storage::disk('local')->delete($path);

                        if ($parsed['errors'] !== []) {
                            Notification::make()->title('File tidak diimpor')->body(implode("\n", array_slice($parsed['errors'], 0, 10)))->danger()->persistent()->send();

                            return;
                        }

                        if ($parsed['items'] === []) {
                            Notification::make()->title('Tidak ada baris yang berisi Hasil Hitung.')->warning()->send();

                            return;
                        }

                        static::submitSession($data, $parsed['items']);
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::applyAction(),
                static::discardAction(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
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
