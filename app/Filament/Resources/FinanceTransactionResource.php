<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinanceTransactionResource\Pages;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Store;
use App\Services\FinanceTransactionPostingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transaksi Keuangan — 1 resource gabungan Pemasukan+Pengeluaran (BUKAN
 * 2 resource terpisah) supaya staff yang input transaksi campuran (mis.
 * bayar sewa lalu terima DP booking di hari yang sama) tidak perlu
 * pindah-pindah menu — badge warna hijau/merah pada kolom 'type' sudah
 * cukup membedakan sekilas, konsisten dengan pola PointTransactionResource
 * (earn/spend digabung 1 resource).
 *
 * store_id NOT NULL (beda dari AssetResource yang nullable) — setiap
 * transaksi keuangan WAJIB terikat ke 1 toko, tidak ada konsep "Kantor
 * Pusat" di sini. store_manager cuma bisa input/lihat transaksi tokonya
 * sendiri (pola sama persis dengan MaterialMemoResource).
 */
class FinanceTransactionResource extends Resource
{
    protected static ?string $model = FinanceTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Transaksi",
    // lihat catatan sistem band di ProductSalesReport.php (band per grup,
    // global cross-group, bukan urutan array navigationGroups()).
    protected static ?string $navigationGroup = 'Transaksi';

    protected static ?string $navigationLabel = 'Transaksi Keuangan';

    protected static ?string $modelLabel = 'Transaksi Keuangan';

    protected static ?string $pluralModelLabel = 'Transaksi Keuangan';

    protected static ?int $navigationSort = 0;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'create', true);
    }

    /**
     * Field finansial: HANYA full-access yang boleh mengubahnya setelah
     * transaksi tersimpan (audit Transaksi Keuangan 2026-09-28) -- staff
     * hanya boleh mengoreksi keterangan & nota. Dipaksa juga di server
     * (EditFinanceTransaction::mutateFormDataBeforeSave).
     */
    public const FINANCIAL_FIELDS = ['type', 'finance_category_id', 'store_id', 'amount', 'transaction_date'];

    /**
     * $record sengaja tidak di-type-hint ke FinanceTransaction: setelah staf mengajukan pengeluaran,
     * halaman Create menjadikan FinanceTransactionApprovalRequest sebagai record form (lihat
     * CreateFinanceTransaction::handleRecordCreation) dan Filament mengevaluasi ulang closure form
     * dengan model itu -- type-hint sempit membuat TypeError (500) setelah pengajuan sebenarnya tersimpan.
     */
    private static function financialLocked(mixed $record): bool
    {
        return $record instanceof FinanceTransaction && ! (auth()->user()?->isFullAccess() ?? false);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        if (! ($user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'update', true))) {
            return false;
        }

        // Non-full-access hanya boleh menyentuh transaksi tokonya sendiri.
        return $user->isFullAccess() || (int) $record->store_id === (int) $user->store_id;
    }

    /**
     * Hapus transaksi keuangan TERBATAS full-access — beda dari edit
     * (staff toko boleh koreksi salah ketik sendiri), menghapus riwayat
     * keuangan sepenuhnya sebaiknya lewat approval yang lebih tinggi,
     * sama filosofi dengan AssetResource::canDelete().
     */
    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function getEloquentQuery(): Builder
    {
        // with() -- kolom category/store/creator/journalEntry di table()
        // sebelumnya N+1 per baris (audit Transaksi Keuangan 2026-09-28).
        $query = parent::getEloquentQuery()->with(['category', 'store', 'creator', 'journalEntry']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            Forms\Components\Section::make('Transaksi')
                ->columns(2)
                ->schema([
                    Forms\Components\Radio::make('type')
                        ->label('Tipe')
                        ->options([
                            'in' => 'Pemasukan',
                            'out' => 'Pengeluaran',
                        ])
                        ->inline()
                        ->required()
                        ->live()
                        // Ganti tipe mengosongkan kategori — daftar pilihan
                        // di bawah difilter per tipe (lihat Select
                        // 'finance_category_id'), kategori lama bisa jadi
                        // tidak valid lagi untuk tipe yang baru dipilih.
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('finance_category_id', null))
                        ->default('out')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('finance_category_id')
                        ->label('Kategori')
                        // Kategori NONAKTIF yang sudah melekat di transaksi ini tetap
                        // jadi opsi (berlabel) -- sebelumnya label kosong & transaksi
                        // lama tidak bisa disimpan tanpa memilih ulang (audit
                        // Kategori Keuangan 2026-09-28).
                        ->options(fn (Forms\Get $get) => FinanceCategory::where('type', $get('type') ?? 'out')
                            ->where('is_group', false)
                            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $get('finance_category_id')))
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn (FinanceCategory $c) => [$c->id => $c->name . ($c->is_active ? '' : ' (nonaktif)')]))
                        ->searchable()
                        ->required()
                        ->helperText(fn (Forms\Get $get) => FinanceCategory::where('is_active', true)->where('type', $get('type') ?? 'out')->exists()
                            ? null
                            : 'Belum ada kategori untuk tipe ini — buat dulu lewat menu Kategori Keuangan.'),

                    Forms\Components\TextInput::make('amount')
                        ->label('Nominal')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->maxValue(99999999999.99)
                        ->disabled(fn ($record) => static::financialLocked($record))
                        ->dehydrated()
                        // Nominal terformat (Rp1.500.000) ditampilkan langsung saat
                        // mengetik -- lebih aman daripada mask input yang bisa salah
                        // menafsirkan desimal (audit Transaksi Keuangan 2026-09-28).
                        ->live(onBlur: true)
                        ->helperText(function ($record, Forms\Get $get) {
                            if (static::financialLocked($record)) {
                                return 'Terkunci: perubahan nominal, tanggal, kategori, dan toko setelah tersimpan hanya oleh direksi/full-access.';
                            }

                            $amount = $get('amount');

                            return is_numeric($amount) && (float) $amount > 0
                                ? 'Terbaca: Rp' . number_format((float) $amount, 0, ',', '.')
                                : null;
                        })
                        ->prefix('Rp'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => $isFullAccess ? null : auth()->user()?->store_id)
                        ->disabled(fn ($record) => ! $isFullAccess || static::financialLocked($record))
                        ->dehydrated(),

                    Forms\Components\DatePicker::make('transaction_date')
                        ->label('Tanggal Transaksi')
                        ->native(false)
                        ->required()
                        // Tidak boleh di masa depan / terlalu lampau (audit 2026-09-28).
                        ->maxDate(today())
                        ->minDate(fn ($record) => $record instanceof FinanceTransaction
                            ? null
                            : now()->subYears(3))
                        ->disabled(fn ($record) => static::financialLocked($record))
                        ->dehydrated()
                        ->default(now()),

                    Forms\Components\FileUpload::make('receipt')
                        ->label('Bukti/Nota (opsional)')
                        ->disk(config('filament.default_filesystem_disk', 'public'))
                        ->directory('finance-receipts')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(5120)
                        ->helperText('Foto atau PDF, maks. 5 MB.'),

                    // Alasan WAJIB kalau full-access mengubah transaksi yang sudah
                    // diposting (audit Transaksi Keuangan 2026-09-28) -- dicatat di
                    // catatan jurnal pembalik dan activity log; tidak disimpan
                    // sebagai kolom.
                    Forms\Components\Textarea::make('change_reason')
                        ->label('Alasan Perubahan')
                        ->required()
                        ->rows(2)
                        ->maxLength(500)
                        ->visible(fn ($record) => $record instanceof FinanceTransaction && ($record->journal_entry_id !== null) && (auth()->user()?->isFullAccess() ?? false))
                        ->dehydrated()
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Keterangan')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transaction_number')
                    ->label('No. Bukti')
                    ->searchable()
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->copyable(),

                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Tipe')
                    ->colors([
                        'success' => 'in',
                        'danger' => 'out',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'in' => 'Pemasukan',
                        'out' => 'Pengeluaran',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Kategori')
                    ->placeholder('(Kategori dihapus)')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR', locale: 'id')
                    ->weight('bold')
                    ->color(fn (FinanceTransaction $record) => $record->type === 'in' ? 'success' : 'danger')
                    ->formatStateUsing(fn (FinanceTransaction $record, $state) => ($record->type === 'in' ? '+ ' : '- ') . number_format($state, 0, ',', '.'))
                    ->sortable()
                    // Saldo bersih (pemasukan - pengeluaran) dari hasil yang
                    // sedang difilter (audit Transaksi Keuangan 2026-09-28).
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Saldo bersih')
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (float) $query
                                ->selectRaw("COALESCE(SUM(CASE WHEN finance_transactions.type = 'in' THEN finance_transactions.amount ELSE -finance_transactions.amount END), 0) as net")
                                ->value('net'))
                            ->money('IDR', locale: 'id')
                    ),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('journalEntry.entry_number')
                    ->label('No. Jurnal')
                    ->placeholder('Belum terposting')
                    ->color(fn (FinanceTransaction $record) => $record->journal_entry_id ? null : 'danger')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(['in' => 'Pemasukan', 'out' => 'Pengeluaran']),

                Tables\Filters\SelectFilter::make('finance_category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name'),

                Tables\Filters\Filter::make('belum_terposting')
                    ->label('Belum terposting ke jurnal')
                    ->query(fn (Builder $query) => $query->whereNull('journal_entry_id')),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id')),

                Tables\Filters\Filter::make('transaction_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal')->native(false),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('transaction_date', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Jurnal Umum yang sudah posted TIDAK IKUT terhapus
                // (integritas riwayat pembukuan) — dibalik dulu lewat
                // FinanceTransactionPostingService::reverseExisting()
                // sebelum baris transaksinya sendiri dihapus. Sama pola
                // dengan EditFinanceTransaction's header DeleteAction.
                Tables\Actions\DeleteAction::make()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan Penghapusan')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (FinanceTransaction $record, array $data) {
                        try {
                            $posting = app(FinanceTransactionPostingService::class);

                            DB::transaction(function () use ($record, $posting, $data) {
                                $posting->assertPeriodsOpenForChange($record);
                                $posting->reverseExisting($record, auth()->id(), $data['reason']);
                                $posting->logChangeReason($record, 'dihapus', $data['reason']);
                                $record->delete();
                            });

                            Notification::make()->title('Transaksi dihapus')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal menghapus transaksi')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan Penghapusan')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (\Illuminate\Support\Collection $records, array $data) {
                        try {
                            $posting = app(FinanceTransactionPostingService::class);

                            DB::transaction(function () use ($records, $posting, $data) {
                                foreach ($records as $record) {
                                    // Tutup Periode dicek PER transaksi; satu saja di
                                    // periode tertutup membatalkan seluruh penghapusan.
                                    $posting->assertPeriodsOpenForChange($record);
                                    $posting->reverseExisting($record, auth()->id(), $data['reason']);
                                    $posting->logChangeReason($record, 'dihapus (massal)', $data['reason']);
                                    $record->delete();
                                }
                            });

                            Notification::make()->title('Transaksi terpilih dihapus')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal menghapus sebagian/semua transaksi')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('transaction_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinanceTransactions::route('/'),
            'create' => Pages\CreateFinanceTransaction::route('/create'),
            'edit' => Pages\EditFinanceTransaction::route('/{record}/edit'),
        ];
    }
}
