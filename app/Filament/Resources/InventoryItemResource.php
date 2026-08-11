<?php

namespace App\Filament\Resources;

use App\Exports\InventoryItemImportTemplateExport;
use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers\MovementsRelationManager;
use App\Models\FilmProduct;
use App\Models\InventoryItem;
use App\Models\ScrollCode;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Produk PPF/WF';

    protected static ?string $modelLabel = 'Produk PPF/WF';

    protected static ?string $pluralModelLabel = 'Produk PPF/WF';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Produk')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Produk')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('category')
                        ->label('Kategori')
                        ->options([
                            'PPF' => 'PPF',
                            'Window Film' => 'Window Film',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode / QR')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?InventoryItem $record) => $record !== null)
                        ->helperText('Kode ini otomatis dibuat sistem dan tidak bisa diubah — tempel QR-nya ke kardus fisik.'),

                    Forms\Components\Select::make('scroll_code_id')
                        ->label('Kode Gulungan')
                        ->searchable()
                        ->preload()
                        ->getSearchResultsUsing(fn (string $search, ?InventoryItem $record) => ScrollCode::query()
                            ->where('code', 'like', "%{$search}%")
                            ->where(fn ($q) => $q->doesntHave('inventoryItem')
                                ->when($record, fn ($q) => $q->orWhere('id', $record->scroll_code_id)))
                            ->with('filmProduct')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (ScrollCode $sc) => [$sc->id => $sc->code . ' — ' . ($sc->filmProduct?->name ?? '—')]))
                        ->getOptionLabelUsing(function ($value) {
                            $scrollCode = ScrollCode::with('filmProduct')->find($value);

                            return $scrollCode ? $scrollCode->code . ' — ' . ($scrollCode->filmProduct?->name ?? '—') : null;
                        })
                        // Kalau kodenya belum pernah didaftarkan (mis. belum
                        // sempat diimpor lewat menu Kode Gulungan), bisa
                        // langsung dibuat dari sini juga — supaya staff
                        // cukup isi 1 form saja, tidak perlu bolak-balik ke
                        // menu Kode Gulungan dulu baru balik ke sini.
                        ->createOptionForm([
                            Forms\Components\Select::make('film_product_id')
                                ->label('Produk Film')
                                ->options(fn () => FilmProduct::pluck('name', 'id'))
                                ->required()
                                ->searchable(),

                            Forms\Components\TextInput::make('code')
                                ->label('Kode Gulungan')
                                ->required()
                                ->unique(table: 'scroll_codes', column: 'code')
                                ->helperText('Kode fisik yang tercetak di gulungan.'),
                        ])
                        ->createOptionUsing(fn (array $data) => ScrollCode::create([
                            'code' => $data['code'],
                            'film_product_id' => $data['film_product_id'],
                            'status' => 'unallocated',
                        ])->id)
                        ->helperText('Cari kode yang sudah ada, atau buat baru langsung dari sini kalau belum terdaftar.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode disalin')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Produk')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('scrollCode.code')
                    ->label('Kode Gulungan')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'in_stock',
                        'danger' => 'out',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in_stock' => 'Ada Stok',
                        'out' => 'Habis',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Didaftarkan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'in_stock' => 'Ada Stok',
                        'out' => 'Habis',
                    ]),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options([
                        'PPF' => 'PPF',
                        'Window Film' => 'Window Film',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Download Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->action(fn () => Excel::download(
                        new InventoryItemImportTemplateExport(),
                        'Template-Import-Produk-PPF-WF.xlsx'
                    )),

                // Import massal — 1 baris file = 1 unit fisik baru. Kalau
                // kolom Kode Gulungan diisi, otomatis dikaitkan/dibuatkan
                // ScrollCode-nya juga (lihat importItems()), jadi barang
                // hasil import langsung tercatat di 2 menu sekaligus tanpa
                // kerja dobel.
                Tables\Actions\Action::make('import')
                    ->label('Import Excel')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('File Excel')
                            ->required()
                            ->disk('local')
                            ->directory('inventory-item-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai format dari tombol "Download Template". Baris pertama (header) otomatis dilewati. Kolom Kode Gulungan opsional — kosongkan kalau belum tahu kode fisiknya. Produk hasil import otomatis berstatus "Ada Stok".'),
                    ])
                    ->action(fn (array $data) => static::importItems($data['file']))
                    ->modalSubmitActionLabel('Import'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('download_qr')
                    ->label('Unduh QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->action(fn (InventoryItem $record) => static::downloadQrPdf(new Collection([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('download_qr_bulk')
                        ->label('Unduh QR (PDF Massal)')
                        ->icon('heroicon-o-qr-code')
                        ->action(fn (Collection $records) => static::downloadQrPdf($records))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Baca file Excel/CSV hasil isian template (baris pertama = header,
     * dilewati) lalu bikin 1 InventoryItem baru per baris valid — kode
     * QR-nya digenerate sistem seperti input manual biasa. Kalau kolom
     * Kode Gulungan diisi: dicari dulu apakah kodenya sudah terdaftar di
     * scroll_codes (tinggal dikaitkan), atau belum ada sama sekali (dibuat
     * baru, kode modelnya dicocokkan ke FilmProduct lewat SUFFIX sku, sama
     * seperti pola di ScrollCodeResource). Baris tidak valid/bentrok
     * DILEWATI diam-diam, ringkasannya dilaporkan lewat notifikasi.
     */
    private static function importItems(string $uploadedPath): void
    {
        $fullPath = Storage::disk('local')->path($uploadedPath);

        try {
            $sheets = Excel::toArray(null, $fullPath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($uploadedPath);

            Notification::make()
                ->title('Gagal membaca file')
                ->body('File tidak bisa dibaca sebagai Excel/CSV yang valid: ' . $e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Storage::disk('local')->delete($uploadedPath);

        $rows = $sheets[0] ?? [];
        array_shift($rows); // baris pertama = header

        $createdCount = 0;
        $invalidCount = 0;
        $unknownModels = [];
        $scrollCodeConflicts = 0;
        $productCache = [];
        $seenScrollCodesInFile = [];

        foreach ($rows as $row) {
            $name = isset($row[0]) ? trim((string) $row[0]) : '';

            if ($name === '') {
                $invalidCount++;
                continue;
            }

            $category = isset($row[1]) ? trim((string) $row[1]) : '';
            $modelCode = isset($row[2]) ? trim((string) $row[2]) : '';
            $scrollCodeValue = static::normalizeImportedScrollCode($row[3] ?? null);
            $notes = isset($row[4]) ? trim((string) $row[4]) : '';

            $scrollCodeId = null;

            if ($scrollCodeValue !== null) {
                if (isset($seenScrollCodesInFile[$scrollCodeValue])) {
                    $scrollCodeConflicts++;
                } else {
                    $seenScrollCodesInFile[$scrollCodeValue] = true;
                    $existing = ScrollCode::where('code', $scrollCodeValue)->first();

                    if ($existing) {
                        if ($existing->inventoryItem()->exists()) {
                            $scrollCodeConflicts++;
                        } else {
                            $scrollCodeId = $existing->id;
                        }
                    } elseif ($modelCode !== '') {
                        if (! array_key_exists($modelCode, $productCache)) {
                            $productCache[$modelCode] = FilmProduct::where('sku', 'like', "%-{$modelCode}")->first();
                        }
                        $product = $productCache[$modelCode];

                        if ($product) {
                            $scrollCodeId = ScrollCode::create([
                                'code' => $scrollCodeValue,
                                'film_product_id' => $product->id,
                                'status' => 'unallocated',
                            ])->id;
                        } else {
                            $unknownModels[$modelCode] = ($unknownModels[$modelCode] ?? 0) + 1;
                        }
                    }
                }
            }

            InventoryItem::create([
                'code' => InventoryItem::generateCode(),
                'name' => $name,
                'category' => $category ?: null,
                'scroll_code_id' => $scrollCodeId,
                'status' => 'in_stock',
                'notes' => $notes ?: null,
                'created_by' => auth()->id(),
            ]);
            $createdCount++;
        }

        $unknownSummary = collect($unknownModels)
            ->map(fn (int $count, string $model) => "{$model} ({$count})")
            ->implode(', ');

        $bodyLines = ["{$createdCount} barang berhasil didaftarkan."];
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (Nama Barang kosong).";
        if ($scrollCodeConflicts > 0) $bodyLines[] = "{$scrollCodeConflicts} kode gulungan dilewati (sudah dipakai barang lain / duplikat dalam file).";
        if ($unknownSummary !== '') $bodyLines[] = "Barang tetap dibuat tapi TANPA kode gulungan karena kode model tidak dikenali: {$unknownSummary}.";

        Notification::make()
            ->title('Import selesai')
            ->body(implode(' ', $bodyLines))
            ->color($createdCount > 0 ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    /**
     * Sama seperti normalisasi di fitur import Kode Gulungan yang lama —
     * Excel/PhpSpreadsheet bisa mengembalikan kode sebagai float kalau
     * kolomnya format Number/General di file sumber.
     */
    private static function normalizeImportedScrollCode(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $code = is_float($raw) ? number_format($raw, 0, '', '') : trim((string) $raw);

        return $code !== '' ? $code : null;
    }

    /**
     * QR berisi KODE POLOS (mis. "INV-A1B2C3D4"), bukan URL — beda dari
     * QR referral GIIAS. App mobile staff yang scan langsung query
     * GET /api/staff/inventory/{code} pakai isi QR-nya, tidak perlu buka
     * browser sama sekali (QR ini tidak dimaksudkan untuk publik/customer).
     */
    private static function downloadQrPdf(Collection $items)
    {
        $rows = SupportCollection::make($items)->map(function (InventoryItem $item) {
            return [
                'name' => $item->name,
                'meta' => $item->category,
                'code' => $item->code,
                'qr'   => QrCodeService::generateDataUri($item->code, 260),
            ];
        });

        $pdf = Pdf::loadView('pdf.inventory_qr_batch', ['items' => $rows])->setPaper('a4', 'portrait');

        $filename = $items->count() === 1
            ? "QR-Inventaris-{$items->first()->code}.pdf"
            : 'QR-Inventaris-Batch-' . now()->format('Ymd-His') . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }

    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListInventoryItems::route('/'),
            'create' => Pages\CreateInventoryItem::route('/create'),
            'edit'   => Pages\EditInventoryItem::route('/{record}/edit'),
        ];
    }
}
