<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinanceTransactionResource\Pages;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Transaksi Keuangan';

    protected static ?string $modelLabel = 'Transaksi Keuangan';

    protected static ?string $pluralModelLabel = 'Transaksi Keuangan';

    protected static ?int $navigationSort = 2;

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
            && $user->hasMenuAccess(static::class);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * Hapus transaksi keuangan TERBATAS full-access — beda dari edit
     * (staff toko boleh koreksi salah ketik sendiri), menghapus riwayat
     * keuangan sepenuhnya sebaiknya lewat approval yang lebih tinggi,
     * sama filosofi dengan AssetResource::canDelete().
     */
    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

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
                        ->options(fn (Forms\Get $get) => FinanceCategory::where('is_active', true)
                            ->where('type', $get('type') ?? 'out')
                            ->orderBy('sort_order')
                            ->pluck('name', 'id'))
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
                        ->prefix('Rp'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => $isFullAccess ? null : auth()->user()?->store_id)
                        ->disabled(! $isFullAccess)
                        ->dehydrated(),

                    Forms\Components\DatePicker::make('transaction_date')
                        ->label('Tanggal Transaksi')
                        ->native(false)
                        ->required()
                        ->default(now()),

                    Forms\Components\FileUpload::make('receipt')
                        ->label('Bukti/Nota (opsional)')
                        ->directory('finance-receipts')
                        ->image()
                        ->maxSize(5120)
                        ->helperText('Maks. 5 MB.'),

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
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(['in' => 'Pemasukan', 'out' => 'Pengeluaran']),

                Tables\Filters\SelectFilter::make('finance_category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name'),

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
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
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
