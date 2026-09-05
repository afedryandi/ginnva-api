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

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Kategori Keuangan';

    protected static ?string $modelLabel = 'Kategori Keuangan';

    protected static ?string $pluralModelLabel = 'Kategori Keuangan';

    protected static ?int $navigationSort = 1;

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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Kategori')
                ->required()
                ->unique(ignoreRecord: true)
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
                ->afterStateUpdated(fn (Forms\Set $set) => $set('chart_of_account_id', null)),

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
                    ->whereIn('type', $get('type') === 'in'
                        ? ['pendapatan', 'pendapatan_lain']
                        : ['beban_pokok', 'beban_operasional', 'beban_lain', 'pajak'])
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                ->searchable()
                ->helperText('Wajib diisi supaya transaksi kategori ini bisa otomatis tercatat di Jurnal Umum.'),

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Kategori')
                    ->searchable()
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

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                Tables\Columns\TextColumn::make('account.display_name')
                    ->label('Akun Bagan Akun')
                    ->placeholder('Belum dihubungkan')
                    ->color(fn (FinanceCategory $record) => $record->chart_of_account_id ? null : 'danger')
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
