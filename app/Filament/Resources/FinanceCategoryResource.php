<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinanceCategoryResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master data kategori Pemasukan/Pengeluaran — sama filosofi dengan
 * MaterialCategoryResource (bisa dikelola admin sendiri tanpa perlu
 * deploy ulang), TAPI punya 'type' (in/out) supaya kategori pemasukan
 * & pengeluaran tidak tercampur saat dipilih di form Transaksi Keuangan.
 */
class FinanceCategoryResource extends Resource
{
    protected static ?string $model = FinanceCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 500-599 "Pengaturan".
    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Kategori Keuangan';

    protected static ?string $modelLabel = 'Kategori Keuangan';

    protected static ?string $pluralModelLabel = 'Kategori Keuangan';

    protected static ?int $navigationSort = 500;

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

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'update', true);
    }

    /**
     * SEBELUMNYA cuma canViewAny()/canCreate()/canEdit() yang ada —
     * canDelete() bawaan Resource selalu FALSE tanpa Policy, jadi
     * DeleteAction (yang sudah punya guard data-integrity
     * ->visible(!hasTransactions()) sendiri di table()) tidak pernah
     * muncul walau syarat guard itu terpenuhi. Ditemukan lewat sapu
     * bersih 2026-09-14 (audit framework, "Otorisasi default-deny").
     */
    public static function canDelete($record): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false)
            && ! $record->hasTransactions();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Kode Kategori (opsional)')
                ->maxLength(20)
                ->unique(ignoreRecord: true)
                ->placeholder('Mis. OPS-01'),

            Forms\Components\TextInput::make('name')
                ->label('Nama Kategori')
                ->required()
                // Unik per (tipe, nama) -- constraint DB ada di migrasi
                // add_unique_type_name_to_finance_categories_table.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (\Illuminate\Validation\Rules\Unique $rule, Forms\Get $get) => $rule->where('type', $get('type'))
                )
                ->maxLength(255)
                ->placeholder('Mis. Sewa Toko, Listrik & Air, Booking/Penjualan'),

            Forms\Components\Select::make('type')
                ->label('Tipe')
                ->options([
                    'in' => 'Pemasukan',
                    'out' => 'Pengeluaran',
                ])
                ->required()
                ->live()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('chart_of_account_id', null))
                // Terkunci setelah kategori dipakai transaksi (audit
                // Kategori Keuangan 2026-09-28) -- mengubah tipe membalik
                // transaksi lama saat diedit & memindah laporan per-kategori.
                ->disabled(fn (?FinanceCategory $record) => $record?->hasTransactions() ?? false)
                ->dehydrated()
                ->helperText(fn (?FinanceCategory $record) => ($record?->hasTransactions() ?? false)
                    ? 'Terkunci: kategori ini sudah dipakai transaksi. Buat kategori baru dan nonaktifkan yang lama kalau perlu tipe berbeda.'
                    : null),

            // Hierarki 1 tingkat (audit Kategori Keuangan 2026-09-28): kategori
            // GRUP membungkus kategori anak untuk pengelompokan tampilan;
            // grup tidak menerima transaksi dan tidak punya akun.
            Forms\Components\Toggle::make('is_group')
                ->label('Kategori Grup (pembungkus)')
                ->live()
                ->default(false)
                ->disabled(fn (?FinanceCategory $record) => $record !== null && ($record->hasTransactions() || $record->children()->exists()))
                ->dehydrated()
                ->helperText('Grup hanya untuk mengelompokkan kategori lain (mis. "Beban Toko"): tidak bisa dipilih di transaksi dan tidak punya akun.'),

            Forms\Components\Select::make('parent_id')
                ->label('Induk Grup (opsional)')
                ->options(fn (Forms\Get $get, ?FinanceCategory $record) => FinanceCategory::where('is_group', true)
                    ->where('type', $get('type'))
                    ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                    ->orderBy('sort_order')
                    ->pluck('name', 'id'))
                ->visible(fn (Forms\Get $get) => ! $get('is_group'))
                ->searchable()
                ->placeholder('— Tidak ada (tingkat atas) —'),

            Forms\Components\Textarea::make('description')
                ->label('Deskripsi (opsional)')
                ->rows(2)
                ->columnSpanFull(),

            // Menghubungkan kategori ini ke akun Bagan Akun — dipakai
            // FinanceTransactionPostingService supaya transaksi dengan
            // kategori ini otomatis diposting ke Jurnal Umum. Nullable
            // dengan sengaja (kategori LAMA belum tentu terhubung) —
            // transaksi baru dengan kategori yang belum dihubungkan
            // akan ditolak dengan pesan jelas, bukan gagal diam-diam.
            Forms\Components\Select::make('chart_of_account_id')
                ->label('Akun Bagan Akun')
                ->options(fn (Forms\Get $get) => ChartOfAccount::where('is_postable', true)
                    ->where('is_active', true)
                    ->whereIn('type', FinanceCategory::ACCOUNT_TYPES[$get('type') === 'in' ? 'in' : 'out'])
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                ->searchable()
                ->visible(fn (Forms\Get $get) => ! $get('is_group'))
                ->required(fn (?FinanceCategory $record, Forms\Get $get) => $record === null && ! $get('is_group'))
                // Validasi SERVER (bukan cuma opsi dropdown): akun harus
                // aktif, postable, dan klasifikasinya sesuai tipe kategori.
                ->rules([
                    fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        if ($error = FinanceCategory::validateAccount($value ? (int) $value : null, $get('type'))) {
                            $fail($error);
                        }
                    },
                ])
                ->disabled(fn (?FinanceCategory $record) => $record?->hasTransactions() ?? false)
                ->dehydrated()
                ->helperText(fn (?FinanceCategory $record) => ($record?->hasTransactions() ?? false)
                    ? 'Terkunci: kategori ini sudah dipakai transaksi (jurnal lama menunjuk akun ini).'
                    : 'Wajib untuk kategori baru, supaya transaksi kategori ini otomatis tercatat di Jurnal Umum.'),

            // Peringatan untuk kategori LAMA yang belum terhubung akun
            // (gap audit Kategori Keuangan 2026-09-28): transaksi kategori
            // ini akan ditolak saat diposting ke Jurnal Umum.
            Forms\Components\Placeholder::make('no_account_warning')
                ->label('')
                ->visible(fn (?FinanceCategory $record) => $record !== null && ! $record->is_group && ! $record->chart_of_account_id)
                ->content('Peringatan: kategori ini belum terhubung ke akun Bagan Akun. Transaksi dengan kategori ini akan ditolak sampai akun dipilih.')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('sort_order')
                ->label('Urutan Tampil')
                ->numeric()
                ->default(fn () => (FinanceCategory::max('sort_order') ?? 0) + 1)
                ->helperText('Angka lebih kecil tampil lebih dulu.')
                ->required(),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Kategori nonaktif tidak muncul lagi sebagai pilihan transaksi baru, tapi riwayat lama tetap tersimpan apa adanya.')
                ->default(true),
        ]);
    }

    /**
     * Eager-load — kolom "account.display_name" di table() di bawah
     * sebelumnya N+1 per baris (audit framework 2026-09-14, "N+1 query
     * & eager loading").
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['account', 'parent']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Kategori')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (FinanceCategory $record, string $state) => ($record->parent_id ? '— ' : '') . $state)
                    ->weight(fn (FinanceCategory $record) => $record->is_group ? 'bold' : 'normal')
                    ->description(fn (FinanceCategory $record) => $record->is_group ? 'Grup' : $record->parent?->name),

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

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                Tables\Columns\TextColumn::make('account.display_name')
                    ->label('Akun Bagan Akun')
                    ->placeholder('Belum dihubungkan')
                    ->placeholder(fn (FinanceCategory $record) => $record->is_group ? '(grup, tanpa akun)' : 'Belum dihubungkan')
                    ->color(fn (FinanceCategory $record) => ($record->chart_of_account_id || $record->is_group) ? null : 'danger')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('transactions_count')
                    ->label('Jumlah Transaksi')
                    ->counts('transactions')
                    ->badge(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(['in' => 'Pemasukan', 'out' => 'Pengeluaran']),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktif'),

                Tables\Filters\Filter::make('tanpa_akun')
                    ->label('Belum terhubung akun')
                    ->query(fn (Builder $query) => $query->whereNull('chart_of_account_id')->where('is_group', false)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // finance_transactions.finance_category_id pakai
                // restrictOnDelete() di DB — dicek juga di sini supaya
                // errornya jadi notifikasi Filament yang jelas, bukan SQL
                // constraint mentah kalau staff coba hapus lewat UI.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (FinanceCategory $record) => ! $record->hasTransactions())
                    ->action(function (FinanceCategory $record) {
                        if ($record->hasTransactions()) {
                            Notification::make()
                                ->title('Tidak bisa menghapus kategori ini')
                                ->body('Kategori ini masih dipakai di transaksi keuangan. Nonaktifkan saja kalau tidak dipakai lagi.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()->title('Kategori dihapus')->success()->send();
                    }),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinanceCategories::route('/'),
            'create' => Pages\CreateFinanceCategory::route('/create'),
            'edit' => Pages\EditFinanceCategory::route('/{record}/edit'),
        ];
    }
}
