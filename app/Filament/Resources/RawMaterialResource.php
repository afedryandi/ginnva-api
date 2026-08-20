<?php

namespace App\Filament\Resources;

use App\Exports\RawMaterialImportTemplateExport;
use App\Filament\Resources\RawMaterialResource\Pages;
use App\Filament\Resources\RawMaterialResource\RelationManagers\BatchesRelationManager;
use App\Filament\Resources\RawMaterialResource\RelationManagers\MovementsRelationManager;
use App\Models\RawMaterial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class RawMaterialResource extends Resource
{
    protected static ?string $model = RawMaterial::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Bahan Baku';

    protected static ?string $modelLabel = 'Bahan Baku';

    protected static ?string $pluralModelLabel = 'Bahan Baku';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Bahan Baku')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Bahan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode Barang')
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->placeholder('Kode internal yang sudah ada, mis. dari sistem lama')
                        ->helperText('Opsional — isi kalau bahan ini sudah punya kode barang sendiri.'),

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. Adhesive, Backing Paper, Packaging'),

                    Forms\Components\TextInput::make('unit')
                        ->label('Satuan')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('Mis. liter, meter, kg, pcs, roll'),

                    Forms\Components\DatePicker::make('received_date')
                        ->label('Tanggal Masuk')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->helperText(fn (?RawMaterial $record) => $record !== null && $record->received_date === null
                            ? 'Data lama belum punya tanggal ini — isi tanggal perkiraan (boleh hari ini kalau tidak tahu pastinya).'
                            : null),

                    Forms\Components\TextInput::make('unit_cost')
                        ->label('Harga per Satuan')
                        ->numeric()
                        ->prefix('Rp')
                        ->helperText('Opsional — untuk estimasi nilai stok.'),

                    Forms\Components\TextInput::make('reorder_point')
                        ->label('Ambang Stok Menipis')
                        ->numeric()
                        ->helperText('Opsional — barang ditandai "Stok Menipis" kalau current stock ≤ angka ini.'),

                    Forms\Components\DatePicker::make('expiry_date')
                        ->label('Tanggal Kedaluwarsa')
                        ->helperText('Opsional — kosongkan kalau bahan ini tidak punya masa pakai.'),

                    // Cuma muncul saat CREATE — sama pola dengan
                    // container_count di action "Catat Stok" (lihat
                    // RawMaterialResource::table()), supaya stok awal JUGA
                    // kebagi jadi batch per botol/wadah (bukan cuma angka
                    // current_stock polos tanpa batch, yang bikin "Botol/
                    // Wadah Tersisa" nongol 0 padahal stoknya ada).
                    Forms\Components\TextInput::make('container_count')
                        ->label('Jumlah Botol/Wadah Awal')
                        ->numeric()
                        ->integer()
                        ->default(1)
                        ->minValue(1)
                        ->visible(fn (?RawMaterial $record) => $record === null)
                        ->helperText('Isi 1 kalau cuma 1 botol/wadah/tong. Kosongkan/isi 0 di "Stok Awal" kalau belum ada stok fisik sama sekali.'),

                    Forms\Components\TextInput::make('current_stock')
                        ->label(fn (?RawMaterial $record) => $record === null ? 'Stok Awal (Total, Semua Botol/Wadah)' : 'Stok Saat Ini')
                        ->numeric()
                        ->default(0)
                        ->disabled(fn (?RawMaterial $record) => $record !== null)
                        ->dehydrated(fn (?RawMaterial $record) => $record === null)
                        ->helperText(fn (?RawMaterial $record) => $record === null
                            ? 'Otomatis dibagi rata ke tiap botol/wadah di atas, dan tercatat sebagai batch (bukan cuma angka polos) — supaya bisa dipantau sisa per botolnya sejak awal.'
                            : 'Stok cuma bisa diubah lewat tombol "Catat Stok" di daftar — supaya selalu ada riwayatnya, tidak diedit langsung di sini.'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * withCount botol/wadah aktif (batch sisa > 0) supaya kolom "Botol
     * Tersisa" tidak query per baris (N+1) di tabel utama.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['batches as active_batches_count' => fn ($q) => $q->where('quantity', '>', 0)]);
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
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Bahan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Masuk')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok (Total)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->formatStateUsing(fn (RawMaterial $record) => number_format((float) $record->current_stock, 2) . ' ' . $record->unit)
                    ->badge()
                    ->color(fn (RawMaterial $record) => $record->isLowStock() ? 'danger' : 'success'),

                // Terpisah dari "Stok (Total)" di atas — ini jumlah botol/
                // wadah fisik yang masih ada isinya (dihitung dari batch,
                // lihat RawMaterial::batches()), BUKAN satuan liter/gram.
                Tables\Columns\TextColumn::make('active_batches_count')
                    ->label('Botol/Wadah Tersisa')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state) => $state . ' botol/wadah')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->label('Ambang Menipis')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, RawMaterial $record) => $state !== null ? number_format((float) $state, 2) . ' ' . $record->unit : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Kedaluwarsa')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (RawMaterial $record) => match (true) {
                        $record->isExpired() => 'danger',
                        $record->isNearExpiry() => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => RawMaterial::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray()),

                Tables\Filters\Filter::make('low_stock')
                    ->label('Stok Menipis')
                    ->query(fn ($query) => $query->whereNotNull('reorder_point')
                        ->whereColumn('current_stock', '<=', 'reorder_point')),

                Tables\Filters\Filter::make('near_expiry')
                    ->label('Mendekati/Sudah Kedaluwarsa')
                    ->query(fn ($query) => $query->whereNotNull('expiry_date')
                        ->whereDate('expiry_date', '<=', now()->addDays(30))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Download Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->action(fn () => Excel::download(
                        new RawMaterialImportTemplateExport(),
                        'Template-Import-Bahan-Baku.xlsx'
                    )),

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
                            ->directory('raw-material-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai format dari tombol "Download Template". Baris pertama (header) otomatis dilewati. Stok Awal otomatis dicatat sebagai riwayat "Masuk" supaya tetap ada jejaknya.'),
                    ])
                    ->action(fn (array $data) => static::importMaterials($data['file']))
                    ->modalSubmitActionLabel('Import'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('adjust_stock')
                    ->label('Sesuaikan Stok')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('actual_quantity')
                            ->label('Hasil Hitung Fisik Sebenarnya')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->suffix(fn (RawMaterial $record) => $record->unit)
                            ->helperText(fn (RawMaterial $record) => 'Stok di sistem saat ini: ' . number_format((float) $record->current_stock, 2) . ' ' . $record->unit . '. Isi jumlah hasil hitung fisik yang SEBENARNYA — selisihnya dihitung otomatis.'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('Mis. hasil stock opname bulanan, alasan selisih (tumpah/rusak/salah catat)'),
                    ])
                    ->action(function (RawMaterial $record, array $data) {
                        $movement = $record->adjustStock((float) $data['actual_quantity'], auth()->id(), $data['note'] ?? null);

                        if ($movement === null) {
                            Notification::make()
                                ->title('Tidak ada selisih')
                                ->body('Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.')
                                ->info()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Stok disesuaikan')
                            ->body('Selisih ' . ($movement->quantity > 0 ? '+' : '') . $movement->quantity . ' ' . $record->unit . ' dicatat sebagai penyesuaian.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('record_movement')
                    ->label('Catat Stok')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('Jenis')
                            ->options(['in' => 'Masuk', 'out' => 'Keluar'])
                            ->required()
                            ->live(),

                        // Cuma relevan untuk "Masuk" — 1 nama bahan bisa
                        // datang dalam banyak botol/wadah sekaligus (mis.
                        // beli 2 botol @5 liter). Tiap botol/wadah jadi 1
                        // batch TERPISAH (bukan digabung jadi 1 angka
                        // besar), supaya sisa tiap botol bisa dipantau
                        // sendiri-sendiri lewat tab "Sisa Batch (FIFO)".
                        Forms\Components\TextInput::make('container_count')
                            ->label('Jumlah Botol/Wadah')
                            ->numeric()
                            ->integer()
                            ->default(1)
                            ->minValue(1)
                            ->required(fn (Forms\Get $get) => $get('type') === 'in')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'in')
                            ->live()
                            ->helperText('Isi 1 kalau cuma 1 botol/wadah/tong.'),

                        Forms\Components\TextInput::make('quantity')
                            ->label(fn (Forms\Get $get) => $get('type') === 'in' ? 'Jumlah Total (Semua Botol/Wadah)' : 'Jumlah')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix(fn (RawMaterial $record) => $record->unit)
                            ->live()
                            ->helperText(function (Forms\Get $get) {
                                if ($get('type') !== 'in') return null;

                                $count = (int) ($get('container_count') ?: 1);
                                $qty = (float) ($get('quantity') ?: 0);
                                if ($count <= 1 || $qty <= 0) return null;

                                return 'Otomatis dibagi rata: ±' . number_format($qty / $count, 2) . ' per botol/wadah.';
                            }),

                        // Setiap "Masuk" jadi batch terpisah per botol/
                        // wadah (lihat container_count di atas) — dipakai
                        // untuk konsumsi FIFO saat "Keluar" nanti. Tidak
                        // relevan untuk "Keluar" makanya disembunyikan.
                        Forms\Components\DatePicker::make('received_date')
                            ->label('Tanggal Masuk Batch Ini')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->default(now())
                            ->maxDate(now())
                            ->visible(fn (Forms\Get $get) => $get('type') === 'in')
                            ->required(fn (Forms\Get $get) => $get('type') === 'in'),

                        Forms\Components\DatePicker::make('expiry_date')
                            ->label('Tanggal Kedaluwarsa Batch Ini')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->helperText('Opsional — kosongkan kalau batch ini tidak punya masa pakai.')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'in'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('Mis. nomor PO, nama supplier, dipakai untuk booking apa'),
                    ])
                    ->action(function (RawMaterial $record, array $data) {
                        try {
                            $record->recordMovement(
                                $data['type'],
                                (float) $data['quantity'],
                                auth()->id(),
                                $data['note'] ?? null,
                                $data['received_date'] ?? null,
                                $data['expiry_date'] ?? null,
                                (int) ($data['container_count'] ?? 1),
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Tidak bisa mencatat stok')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Stok dicatat')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Baris pertama (header) dilewati. Kolom: Nama Bahan, Kategori,
     * Satuan, Stok Awal, Ambang Stok Menipis, Harga per Satuan, Tanggal
     * Kedaluwarsa, Catatan. Stok awal > 0 otomatis dicatat sebagai
     * movement 'in' (bukan cuma di-set langsung ke current_stock) supaya
     * ada jejak riwayatnya sejak awal.
     */
    private static function importMaterials(string $uploadedPath): void
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
        $codeConflicts = 0;
        $seenCodesInFile = [];

        foreach ($rows as $row) {
            $name = isset($row[0]) ? trim((string) $row[0]) : '';
            $unit = isset($row[3]) ? trim((string) $row[3]) : '';

            if ($name === '' || $unit === '') {
                $invalidCount++;
                continue;
            }

            $code = isset($row[1]) ? trim((string) $row[1]) : '';
            $category = isset($row[2]) ? trim((string) $row[2]) : '';
            $initialStock = isset($row[4]) && $row[4] !== '' ? (float) $row[4] : 0.0;
            $reorderPoint = isset($row[5]) && $row[5] !== '' ? (float) $row[5] : null;
            $unitCost = isset($row[6]) && $row[6] !== '' ? (float) $row[6] : null;
            $receivedDate = static::normalizeImportedDate($row[7] ?? null) ?? now()->toDateString();
            $expiryDate = static::normalizeImportedDate($row[8] ?? null);
            $notes = isset($row[9]) ? trim((string) $row[9]) : '';

            // Kode barang punya UNIQUE constraint — kalau bentrok (sudah
            // ada di database, atau duplikat dalam file itu sendiri),
            // bahan tetap didaftarkan TANPA kode (bukan gagal total),
            // supaya admin tinggal isi manual belakangan.
            $useCode = null;
            if ($code !== '') {
                if (isset($seenCodesInFile[$code]) || RawMaterial::where('code', $code)->exists()) {
                    $codeConflicts++;
                } else {
                    $seenCodesInFile[$code] = true;
                    $useCode = $code;
                }
            }

            $material = RawMaterial::create([
                'name' => $name,
                'code' => $useCode,
                'category' => $category ?: null,
                'received_date' => $receivedDate,
                'unit' => $unit,
                'current_stock' => 0,
                'reorder_point' => $reorderPoint,
                'unit_cost' => $unitCost,
                'expiry_date' => $expiryDate,
                'notes' => $notes ?: null,
                'created_by' => auth()->id(),
            ]);

            if ($initialStock > 0) {
                $material->recordMovement('in', $initialStock, auth()->id(), 'Stok awal dari import Excel.', $receivedDate, $expiryDate);
            }

            $createdCount++;
        }

        $bodyLines = ["{$createdCount} bahan baku berhasil didaftarkan."];
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (Nama Bahan/Satuan kosong).";
        if ($codeConflicts > 0) $bodyLines[] = "{$codeConflicts} kode barang dilewati (sudah dipakai bahan lain / duplikat dalam file) — bahan tetap didaftarkan tanpa kode.";

        Notification::make()
            ->title('Import selesai')
            ->body(implode(' ', $bodyLines))
            ->color($createdCount > 0 ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    /**
     * Format kolom tanggal di template adalah DD/MM/YYYY (sama dengan
     * tampilan UI) — di-parse eksplisit dengan format itu, BUKAN
     * Carbon::parse()/strtotime() yang ambigu untuk tanggal bertitik dua
     * (mis. "05/06/2027" bisa terbaca 5 Juni atau 6 Mei tergantung
     * locale). Excel bisa juga mengembalikan cell bertipe Date sebagai
     * serial number.
     */
    private static function normalizeImportedDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $date = \DateTime::createFromFormat('d/m/Y', trim((string) $raw));

        return $date instanceof \DateTime ? $date->format('Y-m-d') : null;
    }

    public static function getRelations(): array
    {
        return [
            BatchesRelationManager::class,
            MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRawMaterials::route('/'),
            'create' => Pages\CreateRawMaterial::route('/create'),
            'edit'   => Pages\EditRawMaterial::route('/{record}/edit'),
        ];
    }
}
