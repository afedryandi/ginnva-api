<?php

namespace App\Filament\Resources;

use App\Exports\InventoryItemImportTemplateExport;
use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\InventoryItemResource\RelationManagers\ScrollCodeUsagesRelationManager;
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
use Illuminate\Support\Facades\DB;
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

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Eager-load scrollCode — dipakai kolom "Kode Gulungan" DAN
        // visibility tombol "Tandai Habis", hindari N+1 per baris.
        return parent::getEloquentQuery()->with('scrollCode');
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

                    Forms\Components\DatePicker::make('received_date')
                        ->label('Tanggal Masuk')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->helperText(fn (?InventoryItem $record) => $record !== null && $record->received_date === null
                            ? 'Data lama belum punya tanggal ini — isi tanggal perkiraan (boleh hari ini kalau tidak tahu pastinya).'
                            : null),

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
                                ->searchable()
                                // Isi otomatis Total Panjang begitu produk
                                // dipilih — standar PPF 15m, Window Film
                                // 30m, tetap bisa diedit manual.
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    $product = $state ? FilmProduct::find($state) : null;
                                    if ($product) {
                                        $set('total_length_meters', $product->product_type === 'ppf' ? 15 : 30);
                                    }
                                }),

                            Forms\Components\TextInput::make('code')
                                ->label('Kode Gulungan')
                                ->required()
                                ->unique(table: 'scroll_codes', column: 'code')
                                ->helperText('Kode fisik yang tercetak di gulungan.'),

                            Forms\Components\TextInput::make('total_length_meters')
                                ->label('Total Panjang (meter)')
                                ->helperText('Terisi otomatis dari produk yang dipilih (PPF 15m, Window Film 30m) — ubah manual kalau gulungan ini beda, mis. sisa tabungan dari gulungan lain.')
                                ->numeric()
                                ->required()
                                ->minValue(0.01),
                        ])
                        ->createOptionUsing(fn (array $data) => ScrollCode::create([
                            'code' => $data['code'],
                            'film_product_id' => $data['film_product_id'],
                            'total_length_meters' => $data['total_length_meters'] ?? null,
                            'remaining_length_meters' => $data['total_length_meters'] ?? null,
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
                    ->toggleable()
                    // Klik langsung ke halaman detail Kode Gulungan (tab
                    // Riwayat Pemakaian dst) — sebelumnya cuma teks, staff
                    // harus cari manual sendiri di menu Kode Gulungan.
                    ->url(fn (InventoryItem $record) => $record->scroll_code_id
                        ? ScrollCodeResource::getUrl('view', ['record' => $record->scroll_code_id])
                        : null)
                    ->color(fn (InventoryItem $record) => $record->scroll_code_id ? 'primary' : null),

                Tables\Columns\TextColumn::make('scrollCode.remaining_length_meters')
                    ->label('Sisa Panjang')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, InventoryItem $record) => $record->scrollCode?->total_length_meters !== null
                        ? number_format((float) $state, 2) . ' / ' . number_format((float) $record->scrollCode->total_length_meters, 2) . ' m'
                        : null)
                    // Sama gaya dengan kolom yang sama di ScrollCodeResource
                    // — sebelumnya teks polos di sini, badge di sana.
                    ->badge()
                    ->color(fn (InventoryItem $record) => $record->scrollCode?->total_length_meters !== null && (float) $record->scrollCode->remaining_length_meters <= 0
                        ? 'danger'
                        : 'info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Masuk')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'in_stock',
                        'danger' => 'out',
                    ])
                    // "Sudah Keluar" — BUKAN "Habis". Status ini cuma
                    // berarti barangnya sudah keluar dari gudang, TIDAK
                    // berarti materialnya sudah habis terpakai — 1
                    // gulungan PPF (±15m)/Window Film (±30m) bisa dipakai
                    // untuk banyak mobil setelah keluar gudang. "Habis"
                    // yang sebenarnya (materialnya sudah tidak cukup lagi)
                    // ditandai lewat status Kode Gulungan ("Terpakai"),
                    // bukan status barang ini.
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in_stock' => 'Ada Stok',
                        'out' => 'Sudah Keluar',
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
                        'out' => 'Sudah Keluar',
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

                // Duplikat dari "Catat Pemakaian" di menu Kode Gulungan —
                // sama alasannya dengan "Tandai Habis" di bawah, supaya
                // staff yang kerja dari Produk PPF/WF tidak perlu pindah
                // menu untuk catat berapa meter dipakai.
                Tables\Actions\Action::make('record_scroll_code_usage')
                    ->label('Catat Pemakaian')
                    ->icon('heroicon-o-scissors')
                    ->color('warning')
                    ->visible(fn (InventoryItem $record) => $record->scrollCode?->total_length_meters !== null
                        && $record->scrollCode->status !== 'used')
                    ->form([
                        Forms\Components\TextInput::make('meters')
                            ->label('Meter Dipakai')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix('meter')
                            ->helperText(fn (InventoryItem $record) => 'Sisa saat ini: ' . number_format((float) $record->scrollCode->remaining_length_meters, 2) . ' meter.'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->placeholder('Mis. dipakai untuk mobil apa, no. polisi, nama customer'),
                    ])
                    ->action(function (InventoryItem $record, array $data) {
                        try {
                            $record->scrollCode->recordUsage((float) $data['meters'], auth()->id(), $data['note'] ?? null);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Tidak bisa mencatat pemakaian')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Pemakaian dicatat')->success()->send();
                    }),

                // Duplikat dari "Tandai Habis" di menu Kode Gulungan —
                // ditambahkan di sini juga supaya staff yang kerja dari
                // Produk PPF/WF tidak perlu pindah menu. Tetap ada di
                // Kode Gulungan untuk yang terbiasa kerja dari situ.
                Tables\Actions\Action::make('mark_scroll_code_used')
                    ->label('Tandai Habis')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (InventoryItem $record) => $record->scrollCode?->status === 'allocated')
                    ->requiresConfirmation()
                    ->modalDescription('Tandai kode gulungan barang ini sebagai habis? Kode ini tidak akan muncul lagi di pilihan saat input garansi baru.')
                    ->action(function (InventoryItem $record) {
                        DB::transaction(function () use ($record) {
                            $scrollCode = ScrollCode::where('id', $record->scroll_code_id)->lockForUpdate()->first();
                            if (! $scrollCode || $scrollCode->status !== 'allocated') return;

                            $scrollCode->update(['status' => 'used', 'used_at' => now()]);
                        });

                        Notification::make()->title('Kode gulungan ditandai habis')->success()->send();
                    }),
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
            $receivedDate = static::normalizeImportedDate($row[4] ?? null) ?? now()->toDateString();
            $notes = isset($row[5]) ? trim((string) $row[5]) : '';

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
                            $defaultLength = $product->product_type === 'ppf' ? 15 : 30;

                            $scrollCodeId = ScrollCode::create([
                                'code' => $scrollCodeValue,
                                'film_product_id' => $product->id,
                                'total_length_meters' => $defaultLength,
                                'remaining_length_meters' => $defaultLength,
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
                'received_date' => $receivedDate,
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
     * Parse tanggal dari import Excel/CSV — format kolom di template
     * DD/MM/YYYY (sama dengan tampilan di UI), tapi Excel bisa juga
     * mengembalikan cell bertipe Date sebagai serial number float. Null
     * kalau kosong/tidak valid.
     */
    private static function normalizeImportedDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_float($raw) || is_int($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $date = \DateTime::createFromFormat('d/m/Y', trim((string) $raw));

        return $date instanceof \DateTime ? $date->format('Y-m-d') : null;
    }

    /**
     * QR berisi KODE POLOS (mis. "INV-A1B2C3D4"), bukan URL — beda dari
     * QR referral GIIAS. App mobile staff yang scan langsung query
     * GET /api/staff/inventory/{code} pakai isi QR-nya, tidak perlu buka
     * browser sama sekali (QR ini tidak dimaksudkan untuk publik/customer).
     */
    private static function downloadQrPdf(Collection $items)
    {
        $items->loadMissing('scrollCode');

        $rows = SupportCollection::make($items)->map(function (InventoryItem $item) {
            return [
                'name' => $item->name,
                'meta' => $item->category,
                'code' => $item->code,
                'scroll_code' => $item->scrollCode?->code,
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
            ScrollCodeUsagesRelationManager::class,
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
