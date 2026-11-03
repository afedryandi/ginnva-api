<?php

namespace App\Filament\Resources;

use App\Exports\RawMaterialMovementExport;
use App\Filament\Resources\RawMaterialMovementResource\Pages;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\Store;
use App\Models\User;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan/riwayat pergerakan bahan baku LINTAS SEMUA bahan — beda dari
 * MovementsRelationManager di RawMaterialResource yang cuma nampilkan
 * riwayat 1 bahan tertentu. Read-only murni, sama seperti pola
 * InventoryMovementResource.
 */
class RawMaterialMovementResource extends Resource
{
    protected static ?string $model = RawMaterialMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    protected static ?string $navigationGroup = 'Riwayat';

    protected static ?int $navigationSort = 101;

    protected static ?string $navigationLabel = 'Riwayat Bahan Baku';

    protected static ?string $modelLabel = 'Riwayat Bahan Baku';

    protected static ?string $pluralModelLabel = 'Riwayat Bahan Baku';

    /**
     * Buku pergerakan stok memuat siapa mencatat apa dan harga beli per kejadian, jadi tidak terbuka lewat akses bawaan akun baru
     * (menu_access kosong): hanya akses penuh atau staf yang menunya dicentang eksplisit (audit Daftar Bahan Baku, 2026-11-02).
     * Harga butuh izin "Lihat" pada menu ini.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canSeeCost(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Pembatalan menyimpan baris aslinya; hanya menu ini yang menampilkannya (scope 'effective' menyembunyikannya dari laporan).
        // Staf non-akses-penuh hanya melihat baris bertanda tokonya sendiri (audit 2026-11-03).
        return parent::getEloquentQuery()
            ->withoutGlobalScope('effective')
            ->visibleTo(auth()->user())
            ->with(['rawMaterial', 'user', 'store', 'reverser']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordClasses(fn (RawMaterialMovement $record) => $record->reversed_at !== null ? 'opacity-60' : null)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rawMaterial.name')
                    ->label('Bahan Baku')
                    ->searchable()
                    ->placeholder('— (bahan sudah dihapus)')
                    // Drill-down ke halaman edit bahan terkait (audit 2026-09-30).
                    // Tautan hanya bagi yang boleh membuka halaman Lihat bahan (tanpa itu berujung 403).
                    ->url(fn (RawMaterialMovement $record) => $record->rawMaterial && RawMaterialResource::canView($record->rawMaterial)
                        ? RawMaterialResource::getUrl('view', ['record' => $record->rawMaterial->id])
                        : null),

                Tables\Columns\TextColumn::make('rawMaterial.category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Jenis')
                    ->colors([
                        'success' => 'in',
                        'danger'  => 'out',
                        'warning' => 'adjustment',
                        'gray'    => 'correction',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in' => 'Masuk',
                        'out' => 'Keluar',
                        'adjustment' => 'Penyesuaian (Opname)',
                        'correction' => 'Koreksi',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->formatStateUsing(fn ($state, RawMaterialMovement $record) => ($state > 0 && $record->type === 'adjustment' ? '+' : '') . number_format((float) $state, 2) . ' ' . ($record->rawMaterial?->unit ?? '')),

                // Harga batch yang tersalin saat "Catat Masuk" — supaya
                // tidak perlu buka tab Batch terpisah dan cocokkan tanggal
                // manual untuk tahu harga beli suatu kejadian masuk.
                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Harga/Satuan')
                    ->money('IDR')
                    ->placeholder('—')
                    ->visible(fn () => static::canSeeCost())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reversed_state')
                    ->label('Status')
                    ->state(fn (RawMaterialMovement $record) => $record->reversed_at !== null ? 'Dibatalkan' : null)
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn (RawMaterialMovement $record) => $record->reversed_at !== null
                        ? 'Dibatalkan' . ($record->reverser ? " oleh {$record->reverser->name}" : '') . ' pada ' . $record->reversed_at->format('d M Y, H:i') . ($record->reversed_reason ? ". Alasan: {$record->reversed_reason}" : '')
                        : null),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('Sumber')
                    ->state(fn (RawMaterialMovement $record) => $record->sourceText())
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Catatan')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (RawMaterialMovement $record) => filled($record->note) && mb_strlen($record->note) > 40 ? $record->note : null)
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(['in' => 'Masuk', 'out' => 'Keluar', 'adjustment' => 'Penyesuaian (Opname)', 'correction' => 'Koreksi']),

                Tables\Filters\SelectFilter::make('raw_material_id')
                    ->label('Bahan Baku')
                    ->relationship('rawMaterial', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn () => RawMaterial::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->toArray())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn ($q, $value) => $q->whereHas('rawMaterial', fn ($m) => $m->where('category', $value))
                    )),

                Tables\Filters\SelectFilter::make('period')
                    ->label('Periode')
                    ->options(['today' => 'Hari ini', 'week' => '7 hari terakhir', 'month' => 'Bulan ini', 'last_month' => 'Bulan lalu'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'today' => $query->whereDate('created_at', today()),
                        'week' => $query->where('created_at', '>=', today()->subDays(6)->startOfDay()),
                        'month' => $query->where('created_at', '>=', now()->startOfMonth()),
                        'last_month' => $query->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()]),
                        default => $query,
                    }),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),

                Tables\Filters\TernaryFilter::make('reversed')
                    ->label('Dibatalkan')
                    ->placeholder('Semua')
                    ->trueLabel('Hanya yang dibatalkan')
                    ->falseLabel('Sembunyikan yang dibatalkan')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('reversed_at'),
                        false: fn (Builder $query) => $query->whereNull('reversed_at'),
                        blank: fn (Builder $query) => $query,
                    ),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Dicatat Oleh')
                    ->options(fn () => User::whereIn(
                        'id',
                        RawMaterialMovement::whereNotNull('user_id')->distinct()->pluck('user_id')
                    )->pluck('name', 'id')),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
                    ),
            ])
            ->headerActions([
                // Ikut filter yang sedang aktif di layar — sama pola
                // dengan InventoryMovementResource (PPF/WF).
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function ($livewire) {
                        // Log ekspor (audit 2026-09-30), konsisten dengan laporan lain.
                        try {
                            activity('report_export')
                                ->causedBy(auth()->user())
                                ->withProperties(['report' => 'raw_material_movement', 'format' => 'xlsx'])
                                ->log('Export Riwayat Bahan Baku (xlsx)');
                        } catch (\Throwable $e) {
                            report($e);
                        }

                        return Excel::download(
                            new RawMaterialMovementExport($livewire->getFilteredTableQuery(), static::canSeeCost()),
                            'riwayat-bahan-baku-' . now()->format('Ymd') . '.xlsx'
                        );
                    }),

                // Bandingkan current_stock tiap bahan dengan (1) penjumlahan seluruh riwayat movement-nya dari 0 dan (2) total sisa
                // batch FIFO-nya. Selisih (1) menandakan manipulasi DB manual atau bug; selisih (2) menandakan batch tidak sinkron
                // dengan stok (daftar kedaluwarsa dan nilai persediaan jadi keliru). Bahan lama yang stoknya dicatat sebelum fitur
                // batch ada bisa muncul di (2). Pergerakan yang dibatalkan tidak dihitung (scope 'effective').
                Tables\Actions\Action::make('reconcile')
                    ->label('Cek Rekonsiliasi')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->action(function () {
                        $mismatches = [];
                        $checked = 0;

                        RawMaterial::query()->chunkById(100, function ($materials) use (&$mismatches, &$checked) {
                            foreach ($materials as $material) {
                                $checked++;
                                $computed = (float) $material->movements()
                                    ->selectRaw("SUM(CASE WHEN type = 'out' THEN -quantity ELSE quantity END) as total")
                                    ->value('total');

                                $diff = round((float) $material->current_stock - $computed, 2);

                                if (abs($diff) > 0.01) {
                                    $mismatches[] = "{$material->name}: stok {$material->current_stock}, riwayat {$computed} (selisih {$diff})";
                                }

                                $batchTotal = (float) $material->batches()->sum('quantity');
                                $batchDiff = round((float) $material->current_stock - $batchTotal, 2);

                                if (abs($batchDiff) > 0.01) {
                                    $mismatches[] = "{$material->name}: stok {$material->current_stock}, total batch {$batchTotal} (selisih {$batchDiff})";
                                }
                            }
                        });

                        try {
                            activity('report_export')
                                ->causedBy(auth()->user())
                                ->withProperties(['report' => 'raw_material_reconcile', 'checked' => $checked, 'mismatches' => count($mismatches)])
                                ->log('Cek Rekonsiliasi Bahan Baku');
                        } catch (\Throwable $e) {
                            report($e);
                        }

                        if (empty($mismatches)) {
                            Notification::make()
                                ->title('Rekonsiliasi bersih')
                                ->body('Stok semua bahan baku cocok dengan penjumlahan riwayat dan total batch-nya.')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(count($mismatches) . ' selisih ditemukan')
                            ->body(implode("\n", array_slice($mismatches, 0, 15)) . (count($mismatches) > 15 ? "\n… dan " . (count($mismatches) - 15) . ' lainnya.' : ''))
                            ->danger()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::reverseAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'in' => 'Masuk',
            'out' => 'Keluar',
            'adjustment' => 'Penyesuaian (Opname)',
            'correction' => 'Koreksi',
            default => $type,
        };
    }

    /**
     * Aksi Batalkan: hanya akses penuh, hanya kejadian terakhir bahan itu yang bukan bersumber Memo/penerimaan/Stok Terbuang.
     * Alasan wajib; baris asli disimpan (ditandai dibatalkan). Untuk Keluar dan opname turun, jumlahnya dipulihkan sebagai batch
     * "tidak diketahui asalnya" supaya total batch tetap sama dengan stok.
     */
    public static function reverseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reverse')
            ->label('Batalkan')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (RawMaterialMovement $record) => (auth()->user()?->isFullAccess() ?? false) && $record->isReversible())
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Alasan pembatalan')
                    ->required()
                    ->maxLength(500)
                    ->rows(2)
                    ->helperText('Mis. salah memilih bahan atau salah memasukkan jumlah.'),
            ])
            ->modalDescription(fn (RawMaterialMovement $record) => ($record->type === 'in'
                ? 'Batch yang dibuat pencatatan ini akan dihapus dan stok dikembalikan ke sebelumnya.'
                : 'Stok dikembalikan ke sebelumnya dan jumlahnya dipulihkan sebagai batch "tidak diketahui asalnya" (tanpa kedaluwarsa), karena sistem tidak menyimpan batch mana yang terpakai.')
                . ' Baris ini tetap tersimpan sebagai "Dibatalkan" dan baris "Koreksi" ditambahkan sebagai jejaknya. Cuma bisa untuk kejadian paling terakhir bahan ini. '
                . 'Pastikan pencatatan ini bukan bagian dari Memo, penerimaan Permohonan Pembelian, atau Stok Terbuang yang dicatat sebelum penanda sumber ada (data lama): batalkan yang bersumber dokumen lewat dokumennya.')
            ->action(function (RawMaterialMovement $record, array $data) {
                try {
                    $record->rawMaterial->reverseLastMovement($record, auth()->id(), $data['reason']);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title('Tidak bisa membatalkan')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Pencatatan dibatalkan')->success()->send();
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Pergerakan')->columns(3)->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y, H:i'),
                Infolists\Components\TextEntry::make('type')->label('Jenis')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'in' => 'success',
                        'out' => 'danger',
                        'adjustment' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => static::typeLabel($state)),
                Infolists\Components\TextEntry::make('quantity')->label('Jumlah')
                    ->formatStateUsing(fn ($state, RawMaterialMovement $record) => ($state > 0 && $record->type === 'adjustment' ? '+' : '') . number_format((float) $state, 2) . ' ' . ($record->rawMaterial?->unit ?? '')),
                Infolists\Components\TextEntry::make('unit_cost')->label('Harga/Satuan')->money('IDR')->placeholder('—')
                    ->visible(fn () => static::canSeeCost()),
                Infolists\Components\TextEntry::make('user.name')->label('Dicatat Oleh')->placeholder('—'),
                Infolists\Components\TextEntry::make('store.name')->label('Toko')->placeholder('—'),
                Infolists\Components\TextEntry::make('source_type')->label('Sumber')
                    ->state(fn (RawMaterialMovement $record) => $record->sourceText())->placeholder('—'),
                Infolists\Components\TextEntry::make('note')->label('Catatan')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Bahan')->columns(3)->schema([
                Infolists\Components\TextEntry::make('rawMaterial.name')->label('Bahan Baku')->placeholder('— (bahan sudah dihapus)'),
                Infolists\Components\TextEntry::make('rawMaterial.category')->label('Kategori')->placeholder('—'),
                Infolists\Components\TextEntry::make('rawMaterial.current_stock')->label('Stok Sekarang')
                    ->formatStateUsing(fn ($state, RawMaterialMovement $record) => number_format((float) $state, 2) . ' ' . ($record->rawMaterial?->unit ?? '')),
            ]),
            Infolists\Components\Section::make('Pembatalan')->columns(3)
                ->visible(fn (RawMaterialMovement $record) => $record->reversed_at !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('reversed_at')->label('Dibatalkan Pada')->dateTime('d M Y, H:i'),
                    Infolists\Components\TextEntry::make('reverser.name')->label('Dibatalkan Oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('reversed_reason')->label('Alasan')->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRawMaterialMovements::route('/'),
            'view' => Pages\ViewRawMaterialMovement::route('/{record}'),
        ];
    }
}
