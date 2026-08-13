<?php

namespace App\Filament\Resources;

use App\Exports\AssetImportTemplateExport;
use App\Filament\Resources\AssetResource\Pages;
use App\Models\Asset;
use App\Models\Store;
use App\Models\User;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Aset Tetap';

    protected static ?string $modelLabel = 'Aset Tetap';

    protected static ?string $pluralModelLabel = 'Aset Tetap';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * store_manager (admin toko) cuma lihat aset milik tokonya sendiri —
     * pola sama persis dengan TechnicianResource/StoreReviewResource.
     * Aset dengan store_id NULL ("Kantor Pusat / belum ditentukan") jadi
     * TIDAK ikut kelihatan untuk non-full-access — itu memang aset
     * pusat, bukan milik toko mana pun.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Aset')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Aset')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->maxLength(255)
                        ->placeholder('Mis. Elektronik, Kendaraan, Mesin, Furnitur'),

                    Forms\Components\TextInput::make('asset_tag')
                        ->label('Kode Aset / QR')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Asset $record) => $record !== null)
                        ->helperText('Kode ini otomatis dibuat sistem dan tidak bisa diubah.'),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'aktif'      => 'Aktif Dipakai',
                            'diperbaiki' => 'Sedang Diperbaiki',
                            'rusak'      => 'Rusak',
                            'dijual'     => 'Dijual',
                            'hilang'     => 'Hilang',
                        ])
                        ->required()
                        ->default('aktif'),

                    Forms\Components\Select::make('assigned_to')
                        ->label('Dipegang Oleh')
                        ->options(fn () => User::pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Belum ditentukan'),

                    Forms\Components\Select::make('store_id')
                        ->label('Lokasi (Toko)')
                        ->options(fn () => Store::pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Kantor Pusat / belum ditentukan')
                        // store_manager cuma boleh assign ke tokonya sendiri —
                        // kalau field-nya cuma disembunyikan (bukan dikunci),
                        // getEloquentQuery() akan langsung "menyembunyikan"
                        // aset itu dari pandangannya begitu disimpan, karena
                        // lolos dari scope tokonya sendiri.
                        ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                        ->dehydrated(fn () => auth()->user()?->isFullAccess() ?? false)
                        ->default(fn () => auth()->user()?->isFullAccess() ? null : auth()->user()?->store_id),

                    Forms\Components\DatePicker::make('purchase_date')
                        ->label('Tanggal Beli'),

                    Forms\Components\TextInput::make('purchase_cost')
                        ->label('Harga Beli')
                        ->numeric()
                        ->prefix('Rp'),

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
                Tables\Columns\TextColumn::make('asset_tag')
                    ->label('Kode')
                    ->badge()
                    ->color('info')
                    ->copyable()
                    ->copyMessage('Kode disalin')
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Aset')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => 'aktif',
                        'warning' => 'diperbaiki',
                        'danger'  => ['rusak', 'hilang'],
                        'gray'    => 'dijual',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'aktif'      => 'Aktif Dipakai',
                        'diperbaiki' => 'Diperbaiki',
                        'rusak'      => 'Rusak',
                        'dijual'     => 'Dijual',
                        'hilang'     => 'Hilang',
                        default      => $state,
                    }),

                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('Dipegang Oleh')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Lokasi')
                    ->placeholder('Kantor Pusat')
                    ->searchable(),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Tanggal Beli')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'aktif'      => 'Aktif Dipakai',
                        'diperbaiki' => 'Sedang Diperbaiki',
                        'rusak'      => 'Rusak',
                        'dijual'     => 'Dijual',
                        'hilang'     => 'Hilang',
                    ]),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => Asset::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->pluck('category', 'category')
                        ->toArray()),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Lokasi')
                    ->options(fn () => Store::pluck('name', 'id')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('download_template')
                    ->label('Download Template')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->action(fn () => Excel::download(
                        new AssetImportTemplateExport(),
                        'Template-Import-Aset.xlsx'
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
                            ->directory('asset-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('Pakai format dari tombol "Download Template". Baris pertama (header) otomatis dilewati. Kolom "Dipegang Oleh" dicocokkan ke nama user (cocok persis), "Lokasi" dicocokkan ke nama toko — kalau tidak ketemu, dikosongkan (aset tetap dibuat).'),
                    ])
                    ->action(fn (array $data) => static::importAssets($data['file']))
                    ->modalSubmitActionLabel('Import'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('download_qr')
                    ->label('Unduh QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->action(fn (Asset $record) => static::downloadQrPdf(new Collection([$record]))),
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
     * QR berisi KODE POLOS asset_tag (mis. "ASSET-A1B2C3D4"), sama pola
     * dengan InventoryItemResource::downloadQrPdf() — app mobile staff
     * scan langsung query GET /api/staff/assets/{code}.
     */
    private static function downloadQrPdf(Collection $items)
    {
        $rows = SupportCollection::make($items)->map(function (Asset $item) {
            return [
                'name' => $item->name,
                'meta' => $item->category,
                'code' => $item->asset_tag,
                'qr'   => QrCodeService::generateDataUri($item->asset_tag, 260),
            ];
        });

        $pdf = Pdf::loadView('pdf.asset_qr_batch', ['items' => $rows])->setPaper('a4', 'portrait');

        $filename = $items->count() === 1
            ? "QR-Aset-{$items->first()->asset_tag}.pdf"
            : 'QR-Aset-Batch-' . now()->format('Ymd-His') . '.pdf';

        return response()->streamDownload(fn () => print($pdf->output()), $filename);
    }

    /**
     * Baris pertama (header) dilewati. Kolom: Nama Aset, Kategori,
     * Status, Dipegang Oleh (nama user, dicocokkan persis — kalau tidak
     * ketemu dikosongkan, bukan bikin baris gagal), Lokasi (nama toko,
     * sama), Tanggal Beli, Harga Beli, Catatan.
     */
    private static function importAssets(string $uploadedPath): void
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

        $statusOptions = ['aktif', 'diperbaiki', 'rusak', 'dijual', 'hilang'];

        $createdCount = 0;
        $invalidCount = 0;
        $unmatchedAssignees = 0;
        $unmatchedStores = 0;
        $userCache = [];
        $storeCache = [];

        foreach ($rows as $row) {
            $name = isset($row[0]) ? trim((string) $row[0]) : '';

            if ($name === '') {
                $invalidCount++;
                continue;
            }

            $category = isset($row[1]) ? trim((string) $row[1]) : '';
            $status = isset($row[2]) ? strtolower(trim((string) $row[2])) : 'aktif';
            $status = in_array($status, $statusOptions, true) ? $status : 'aktif';

            $assigneeName = isset($row[3]) ? trim((string) $row[3]) : '';
            $assignedTo = null;
            if ($assigneeName !== '') {
                if (! array_key_exists($assigneeName, $userCache)) {
                    $userCache[$assigneeName] = User::where('name', $assigneeName)->first()?->id;
                }
                $assignedTo = $userCache[$assigneeName];
                if ($assignedTo === null) $unmatchedAssignees++;
            }

            $storeName = isset($row[4]) ? trim((string) $row[4]) : '';
            $storeId = null;
            if ($storeName !== '') {
                if (! array_key_exists($storeName, $storeCache)) {
                    $storeCache[$storeName] = Store::where('name', $storeName)->first()?->id;
                }
                $storeId = $storeCache[$storeName];
                if ($storeId === null) $unmatchedStores++;
            }

            $purchaseDate = isset($row[5]) && $row[5] !== '' ? static::normalizeImportedDate($row[5]) : null;
            $purchaseCost = isset($row[6]) && $row[6] !== '' ? (float) $row[6] : null;
            $notes = isset($row[7]) ? trim((string) $row[7]) : '';

            Asset::create([
                'asset_tag' => Asset::generateAssetTag(),
                'name' => $name,
                'category' => $category ?: null,
                'status' => $status,
                'assigned_to' => $assignedTo,
                'store_id' => $storeId,
                'purchase_date' => $purchaseDate,
                'purchase_cost' => $purchaseCost,
                'notes' => $notes ?: null,
                'created_by' => auth()->id(),
            ]);
            $createdCount++;
        }

        $bodyLines = ["{$createdCount} aset berhasil didaftarkan."];
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (Nama Aset kosong).";
        if ($unmatchedAssignees > 0) $bodyLines[] = "{$unmatchedAssignees} nama user di kolom \"Dipegang Oleh\" tidak ditemukan (dikosongkan).";
        if ($unmatchedStores > 0) $bodyLines[] = "{$unmatchedStores} nama toko di kolom \"Lokasi\" tidak ditemukan (dikosongkan).";

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

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'edit'   => Pages\EditAsset::route('/{record}/edit'),
        ];
    }
}
