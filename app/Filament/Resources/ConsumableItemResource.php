<?php

namespace App\Filament\Resources;

use App\Exports\ConsumableItemImportTemplateExport;
use App\Filament\Resources\ConsumableItemResource\Pages;
use App\Filament\Resources\ConsumableItemResource\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\ConsumableItemResource\RelationManagers\MovementsRelationManager;
use App\Models\ConsumableItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Barang Habis Pakai (lakban, lap, cutter, isi cutter, dll) — perlengkapan
 * operasional yang terpakai habis, BEDA dari Aset Tetap (individual,
 * biasanya ber-QR, tidak "habis") dan Bahan Baku (khusus material
 * produksi PPF/WF). Strukturnya SENGAJA mirror RawMaterialResource
 * (stok kuantitas, Catat Stok, Sesuaikan Stok/opname) karena kelakuannya
 * memang identik.
 */
class ConsumableItemResource extends Resource
{
    protected static ?string $model = ConsumableItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Barang Habis Pakai';

    protected static ?string $modelLabel = 'Barang Habis Pakai';

    protected static ?string $pluralModelLabel = 'Barang Habis Pakai';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * SEBELUMNYA tidak ada override sama sekali di sini dan tidak ada
     * ConsumableItemPolicy terdaftar — canCreate()/canEdit()/canDelete()/
     * canDeleteAny() bawaan Resource selalu FALSE untuk siapa pun tanpa
     * policy (default-deny Laravel). Sama bug class dengan
     * RawMaterialResource/InventoryItemResource/AssetResource (audit
     * sebelumnya) — struktur resource ini memang sengaja mirror
     * RawMaterialResource, jadi fix-nya pun sama persis: canCreate()/
     * canEdit() seluas canViewAny() (EditAction tidak punya ->visible()
     * tambahan di kode aslinya), canDelete()/canDeleteAny() dibatasi
     * isFullAccess() (menyamai guard ->visible() yang SUDAH ada eksplisit
     * di DeleteAction/DeleteBulkAction).
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Barang')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Barang')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('code')
                        ->label('Kode Barang')
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->placeholder('Kode internal yang sudah ada, mis. dari sistem lama')
                        ->helperText('Opsional — isi kalau barang ini sudah punya kode barang sendiri.'),

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. Lakban, Alat Potong, Kebersihan'),

                    Forms\Components\TextInput::make('unit')
                        ->label('Satuan')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('Mis. pcs, roll, box, lusin'),

                    Forms\Components\DatePicker::make('received_date')
                        ->label('Tanggal Masuk')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->helperText(fn (?ConsumableItem $record) => $record !== null && $record->received_date === null
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

                    // Label switch (Stok Awal <-> Stok Saat Ini) disamakan
                    // dengan RawMaterialResource — sebelumnya di sini
                    // selalu "Stok Saat Ini" walau lagi di form Create.
                    Forms\Components\TextInput::make('current_stock')
                        ->label(fn (?ConsumableItem $record) => $record === null ? 'Stok Awal' : 'Stok Saat Ini')
                        ->numeric()
                        ->default(0)
                        ->disabled(fn (?ConsumableItem $record) => $record !== null)
                        ->dehydrated(fn (?ConsumableItem $record) => $record === null)
                        ->helperText(fn (?ConsumableItem $record) => $record === null
                            ? 'Stok awal saat pertama kali didaftarkan — otomatis tercatat sebagai riwayat "Masuk".'
                            : 'Stok cuma bisa diubah lewat tombol "Catat Stok" di daftar — supaya selalu ada riwayatnya, tidak diedit langsung di sini.'),

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
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Barang')
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
                    ->label('Stok')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->formatStateUsing(fn (ConsumableItem $record) => number_format((float) $record->current_stock, 2) . ' ' . $record->unit)
                    ->badge()
                    ->color(fn (ConsumableItem $record) => $record->isLowStock() ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('reorder_point')
                    ->label('Ambang Menipis')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, ConsumableItem $record) => $state !== null ? number_format((float) $state, 2) . ' ' . $record->unit : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => ConsumableItem::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray()),

                Tables\Filters\Filter::make('low_stock')
                    ->label('Stok Menipis')
                    ->query(fn ($query) => $query->whereNotNull('reorder_point')
                        ->whereColumn('current_stock', '<=', 'reorder_point')),

                Tables\Filters\Filter::make('dead_stock')
                    ->label('Tidak Bergerak (' . ConsumableItem::DEAD_STOCK_DAYS . '+ hari)')
                    ->query(fn ($query) => $query->where('current_stock', '>', 0)
                        ->where('updated_at', '<', now()->subDays(ConsumableItem::DEAD_STOCK_DAYS))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Download Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->action(fn () => Excel::download(
                        new ConsumableItemImportTemplateExport(),
                        'Template-Import-Barang-Habis-Pakai.xlsx'
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
                            ->directory('consumable-item-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai format dari tombol "Download Template". Baris pertama (header) otomatis dilewati. Stok Awal otomatis dicatat sebagai riwayat "Masuk" supaya tetap ada jejaknya.'),
                    ])
                    ->action(fn (array $data) => static::importItems($data['file']))
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
                            ->suffix(fn (ConsumableItem $record) => $record->unit)
                            ->helperText(fn (ConsumableItem $record) => 'Stok di sistem saat ini: ' . number_format((float) $record->current_stock, 2) . ' ' . $record->unit . '. Isi jumlah hasil hitung fisik yang SEBENARNYA — selisihnya dihitung otomatis.'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('Mis. hasil stock opname bulanan, alasan selisih'),
                    ])
                    ->action(function (ConsumableItem $record, array $data) {
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

                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix(fn (ConsumableItem $record) => $record->unit),

                        // Disalin ke riwayat movement (bukan cuma menimpa
                        // unit_cost di baris utama) supaya valuasi historis
                        // bisa direkonstruksi kalau harga beli berubah antar
                        // pembelian — lihat ConsumableItem::recordMovement().
                        Forms\Components\TextInput::make('unit_cost')
                            ->label('Harga per Satuan')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->default(fn (ConsumableItem $record) => $record->unit_cost)
                            ->helperText('Opsional — kosongkan untuk pakai harga terakhir tersimpan.')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'in'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('Mis. dipakai untuk booking apa, alasan keluar'),
                    ])
                    ->action(function (ConsumableItem $record, array $data) {
                        try {
                            $record->recordMovement(
                                $data['type'],
                                (float) $data['quantity'],
                                auth()->id(),
                                $data['note'] ?? null,
                                isset($data['unit_cost']) && $data['unit_cost'] !== '' ? (float) $data['unit_cost'] : null,
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
     * Baris pertama (header) dilewati. Kolom: Nama Barang, Kode Barang,
     * Kategori, Satuan, Stok Awal, Ambang Stok Menipis, Harga per Satuan,
     * Catatan.
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
            $notes = isset($row[8]) ? trim((string) $row[8]) : '';

            $useCode = null;
            if ($code !== '') {
                if (isset($seenCodesInFile[$code]) || ConsumableItem::where('code', $code)->exists()) {
                    $codeConflicts++;
                } else {
                    $seenCodesInFile[$code] = true;
                    $useCode = $code;
                }
            }

            $item = ConsumableItem::create([
                'name' => $name,
                'code' => $useCode,
                'category' => $category ?: null,
                'received_date' => $receivedDate,
                'unit' => $unit,
                'current_stock' => 0,
                'reorder_point' => $reorderPoint,
                'unit_cost' => $unitCost,
                'notes' => $notes ?: null,
                'created_by' => auth()->id(),
            ]);

            if ($initialStock > 0) {
                $item->recordMovement('in', $initialStock, auth()->id(), 'Stok awal dari import Excel.');
            }

            $createdCount++;
        }

        $bodyLines = ["{$createdCount} barang berhasil didaftarkan."];
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (Nama Barang/Satuan kosong).";
        if ($codeConflicts > 0) $bodyLines[] = "{$codeConflicts} kode barang dilewati (sudah dipakai barang lain / duplikat dalam file) — barang tetap didaftarkan tanpa kode.";

        Notification::make()
            ->title('Import selesai')
            ->body(implode(' ', $bodyLines))
            ->color($createdCount > 0 ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    /**
     * Format kolom tanggal di template adalah DD/MM/YYYY — lihat catatan
     * lengkap di RawMaterialResource::normalizeImportedDate().
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
            MovementsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListConsumableItems::route('/'),
            'create' => Pages\CreateConsumableItem::route('/create'),
            'edit'   => Pages\EditConsumableItem::route('/{record}/edit'),
        ];
    }
}