<?php

namespace App\Filament\Resources;

use App\Exports\AssetImportTemplateExport;
use App\Filament\Resources\AssetResource\Pages;
use App\Filament\Resources\AssetResource\RelationManagers\TransfersRelationManager;
use App\Models\Asset;
use App\Models\AssetTransfer;
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

        if ($user) {
            $query->visibleTo($user);
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
                        // SEBELUMNYA User::pluck('name', 'id') polos —
                        // menampilkan SEMUA akun (termasuk installer/partner
                        // yang bukan staff internal) sebagai kandidat
                        // pemegang aset. Dibatasi ke akun yang benar-benar
                        // bisa masuk area staff (canAccessStaffArea()).
                        ->options(fn () => User::all()
                            ->filter(fn (User $u) => $u->canAccessStaffArea())
                            ->pluck('name', 'id'))
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

                    Forms\Components\DatePicker::make('received_date')
                        ->label('Tanggal Masuk')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->helperText(fn (?Asset $record) => $record !== null && $record->received_date === null
                            ? 'Data lama belum punya tanggal ini — isi tanggal perkiraan (boleh hari ini kalau tidak tahu pastinya).'
                            : null),

                    Forms\Components\DatePicker::make('purchase_date')
                        ->label('Tanggal Beli')
                        ->native(false)
                        ->displayFormat('d M Y'),

                    Forms\Components\TextInput::make('purchase_cost')
                        ->label('Harga Beli')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp'),

                    Forms\Components\DatePicker::make('next_maintenance_date')
                        ->label('Jadwal Maintenance Berikutnya')
                        ->native(false)
                        ->displayFormat('d M Y')
                        ->helperText('Opsional — isi kalau aset ini butuh servis/kalibrasi berkala. Admin akan diberi tahu otomatis begitu tanggalnya jatuh tempo.'),

                    Forms\Components\TextInput::make('useful_life_years')
                        ->label('Umur Ekonomis (tahun)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Opsional — untuk hitung estimasi nilai buku saat ini (depresiasi garis lurus). Kosongkan kalau tidak perlu dilacak.'),

                    Forms\Components\TextInput::make('salvage_value')
                        ->label('Nilai Residu')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->helperText('Opsional — perkiraan nilai sisa di akhir umur ekonomis (boleh 0).'),

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

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Tanggal Masuk')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Tanggal Beli')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('book_value')
                    ->label('Nilai Buku Saat Ini')
                    ->state(fn (Asset $record) => $record->currentBookValue())
                    ->formatStateUsing(fn (?float $state) => $state !== null ? 'Rp ' . number_format($state, 0, ',', '.') : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('next_maintenance_date')
                    ->label('Maintenance Berikutnya')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Asset $record) => $record->next_maintenance_date?->isPast() ? 'danger' : 'gray')
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

                // Jalan RESMI pindah tangan aset — beda dari sekadar edit
                // field "Dipegang Oleh"/"Lokasi" di form (yang tetap ada,
                // untuk koreksi data biasa): aksi ini WAJIB isi kondisi
                // fisik + alasan saat serah terima, dan tercatat sebagai 1
                // baris utuh di asset_transfers (lihat tab "Riwayat
                // Kepemilikan"), bukan cuma diff before/after generik di
                // activity log.
                Tables\Actions\Action::make('transfer')
                    ->label('Serah Terima')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->form([
                        Forms\Components\Select::make('to_user_id')
                            ->label('Diserahkan Ke')
                            ->options(fn () => User::all()
                                ->filter(fn (User $u) => $u->canAccessStaffArea())
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder('Tidak berubah / dilepas (tidak ada pemegang)'),

                        Forms\Components\Select::make('to_store_id')
                            ->label('Pindah Ke Toko')
                            ->options(fn () => Store::pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                            ->placeholder('Tidak berubah'),

                        Forms\Components\Select::make('condition_at_transfer')
                            ->label('Kondisi Fisik Saat Ini')
                            ->options([
                                'baik' => 'Baik',
                                'perlu_perhatian' => 'Perlu Perhatian',
                                'rusak' => 'Rusak',
                            ])
                            ->required(),

                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan / Keterangan Serah Terima')
                            ->required()
                            ->placeholder('Mis. rotasi tugas, pindah cabang, penggantian penanggung jawab'),
                    ])
                    ->action(function (Asset $record, array $data) {
                        $user = auth()->user();

                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data, $user) {
                            $locked = Asset::where('id', $record->id)->lockForUpdate()->firstOrFail();

                            $toStoreId = ($user->isFullAccess() && $data['to_store_id']) ? $data['to_store_id'] : $locked->store_id;

                            AssetTransfer::create([
                                'asset_id' => $locked->id,
                                'from_user_id' => $locked->assigned_to,
                                'to_user_id' => $data['to_user_id'] ?? null,
                                'from_store_id' => $locked->store_id,
                                'to_store_id' => $toStoreId,
                                'condition_at_transfer' => $data['condition_at_transfer'],
                                'reason' => $data['reason'],
                                'performed_by' => $user->id,
                            ]);

                            $locked->update([
                                'assigned_to' => $data['to_user_id'] ?? null,
                                'store_id' => $toStoreId,
                            ]);
                        });

                        Notification::make()->title('Serah terima dicatat')->success()->send();
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
        // SEBELUMNYA cuma hitung jumlah gagal cocok — admin tidak tahu
        // NAMA mana yang gagal, harus buka tiap baris hasil import satu-
        // satu untuk menebak. Sekarang nama yang gagal ditampung supaya
        // bisa disebutkan langsung di notifikasi akhir.
        $unmatchedAssigneeNames = [];
        $unmatchedStoreNames = [];
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
                    // Sama pembatasan dengan Select 'assigned_to' di form —
                    // cuma cocokkan ke akun yang benar-benar bisa masuk area
                    // staff, bukan sembarang user (mis. installer/partner).
                    $userCache[$assigneeName] = User::where('name', $assigneeName)
                        ->get()
                        ->first(fn (User $u) => $u->canAccessStaffArea())
                        ?->id;
                }
                $assignedTo = $userCache[$assigneeName];
                if ($assignedTo === null) $unmatchedAssigneeNames[$assigneeName] = true;
            }

            $storeName = isset($row[4]) ? trim((string) $row[4]) : '';
            $storeId = null;
            if ($storeName !== '') {
                if (! array_key_exists($storeName, $storeCache)) {
                    $storeCache[$storeName] = Store::where('name', $storeName)->first()?->id;
                }
                $storeId = $storeCache[$storeName];
                if ($storeId === null) $unmatchedStoreNames[$storeName] = true;
            }

            $receivedDate = static::normalizeImportedDate($row[5] ?? null) ?? now()->toDateString();
            $purchaseDate = static::normalizeImportedDate($row[6] ?? null);
            // max(0, ...) — jalur form sudah dibatasi minValue(0), import
            // ini bypass form jadi perlu dibatasi terpisah supaya tidak
            // ada harga beli negatif menyelinap lewat file Excel.
            $purchaseCost = isset($row[7]) && $row[7] !== '' ? max(0, (float) $row[7]) : null;
            $notes = isset($row[8]) ? trim((string) $row[8]) : '';

            Asset::create([
                'asset_tag' => Asset::generateAssetTag(),
                'name' => $name,
                'category' => $category ?: null,
                'status' => $status,
                'assigned_to' => $assignedTo,
                'store_id' => $storeId,
                'received_date' => $receivedDate,
                'purchase_date' => $purchaseDate,
                'purchase_cost' => $purchaseCost,
                'notes' => $notes ?: null,
                'created_by' => auth()->id(),
            ]);
            $createdCount++;
        }

        $bodyLines = ["{$createdCount} aset berhasil didaftarkan."];
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (Nama Aset kosong).";
        if (! empty($unmatchedAssigneeNames)) {
            $bodyLines[] = 'Nama user tidak ditemukan (dikosongkan): ' . implode(', ', array_keys($unmatchedAssigneeNames)) . '.';
        }
        if (! empty($unmatchedStoreNames)) {
            $bodyLines[] = 'Nama toko tidak ditemukan (dikosongkan): ' . implode(', ', array_keys($unmatchedStoreNames)) . '.';
        }

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
            TransfersRelationManager::class,
        ];
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
