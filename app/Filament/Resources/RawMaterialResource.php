<?php

namespace App\Filament\Resources;

use App\Exports\RawMaterialImportTemplateExport;
use App\Filament\Resources\RawMaterialResource\Pages;
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

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. Adhesive, Backing Paper, Packaging'),

                    Forms\Components\TextInput::make('unit')
                        ->label('Satuan')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('Mis. liter, meter, kg, pcs, roll'),

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

                    Forms\Components\TextInput::make('current_stock')
                        ->label('Stok Saat Ini')
                        ->numeric()
                        ->default(0)
                        ->disabled(fn (?RawMaterial $record) => $record !== null)
                        ->dehydrated(fn (?RawMaterial $record) => $record === null)
                        ->helperText(fn (?RawMaterial $record) => $record === null
                            ? 'Stok awal saat pertama kali didaftarkan.'
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
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Bahan')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stok')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->formatStateUsing(fn (RawMaterial $record) => number_format((float) $record->current_stock, 2) . ' ' . $record->unit)
                    ->badge()
                    ->color(fn (RawMaterial $record) => $record->isLowStock() ? 'danger' : 'success'),

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

                        Forms\Components\TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix(fn (RawMaterial $record) => $record->unit),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan')
                            ->placeholder('Mis. nomor PO, nama supplier, dipakai untuk booking apa'),
                    ])
                    ->action(function (RawMaterial $record, array $data) {
                        try {
                            $record->recordMovement($data['type'], (float) $data['quantity'], auth()->id(), $data['note'] ?? null);
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

        foreach ($rows as $row) {
            $name = isset($row[0]) ? trim((string) $row[0]) : '';
            $unit = isset($row[2]) ? trim((string) $row[2]) : '';

            if ($name === '' || $unit === '') {
                $invalidCount++;
                continue;
            }

            $category = isset($row[1]) ? trim((string) $row[1]) : '';
            $initialStock = isset($row[3]) && $row[3] !== '' ? (float) $row[3] : 0.0;
            $reorderPoint = isset($row[4]) && $row[4] !== '' ? (float) $row[4] : null;
            $unitCost = isset($row[5]) && $row[5] !== '' ? (float) $row[5] : null;
            $expiryDate = isset($row[6]) && $row[6] !== '' ? static::normalizeImportedDate($row[6]) : null;
            $notes = isset($row[7]) ? trim((string) $row[7]) : '';

            $material = RawMaterial::create([
                'name' => $name,
                'category' => $category ?: null,
                'unit' => $unit,
                'current_stock' => 0,
                'reorder_point' => $reorderPoint,
                'unit_cost' => $unitCost,
                'expiry_date' => $expiryDate,
                'notes' => $notes ?: null,
                'created_by' => auth()->id(),
            ]);

            if ($initialStock > 0) {
                $material->recordMovement('in', $initialStock, auth()->id(), 'Stok awal dari import Excel.');
            }

            $createdCount++;
        }

        $bodyLines = ["{$createdCount} bahan baku berhasil didaftarkan."];
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (Nama Bahan/Satuan kosong).";

        Notification::make()
            ->title('Import selesai')
            ->body(implode(' ', $bodyLines))
            ->color($createdCount > 0 ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    private static function normalizeImportedDate(mixed $raw): ?string
    {
        if (is_numeric($raw)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return \Carbon\Carbon::parse((string) $raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
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
            'index'  => Pages\ListRawMaterials::route('/'),
            'create' => Pages\CreateRawMaterial::route('/create'),
            'edit'   => Pages\EditRawMaterial::route('/{record}/edit'),
        ];
    }
}
