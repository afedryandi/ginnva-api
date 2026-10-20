<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentAccountResource\Pages;
use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\FinancialStatementService;
use App\Services\PettyCashService;
use Filament\Notifications\Notification;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Rekening Kas & Bank (Keuangan Fase 1, 2026-11-05): daftar kas toko dan rekening bank perusahaan, tiap rekening terhubung ke satu akun kas di
 * Bagan Akun dan menentukan ke mana uang masuk menurut metode bayar dan toko. Rekening tidak dihapus (cukup dinonaktifkan) karena
 * transaksi lama merujuknya. Terbatas full-access; yang lain lewat Hak Akses Detail.
 */
class PaymentAccountResource extends Resource
{
    protected static ?string $model = PaymentAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Rekening Kas & Bank';

    protected static ?string $modelLabel = 'Rekening';

    protected static ?string $pluralModelLabel = 'Rekening Kas & Bank';

    protected static ?int $navigationSort = 205;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea() && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'view', false));
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'create', false));
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    /** Transaksi lama merujuk rekening ini; hapus akan memutus rujukan itu. Nonaktifkan saja. */
    public static function canDelete($record): bool
    {
        return false;
    }

    /** Kode akun kas berikutnya yang kosong (1103-1199), atau null bila habis. */
    public static function nextCashCode(): ?string
    {
        $used = ChartOfAccount::where('code', 'like', '11__')->pluck('code')->all();

        for ($code = 1103; $code <= 1199; $code++) {
            if (! in_array((string) $code, $used, true)) {
                return (string) $code;
            }
        }

        return null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Rekening')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(120)->placeholder('mis. Kas Toko PIK 2 atau BCA Operasional'),

                Forms\Components\Select::make('kind')->label('Jenis')->options(PaymentAccount::KINDS)->required()->native(false)->live(),

                Forms\Components\Select::make('chart_of_account_id')
                    ->label('Akun di Bagan Akun')
                    ->options(fn () => ChartOfAccount::where('is_cash', true)->where('is_postable', true)->where('is_active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                    ->required()
                    ->searchable()
                    ->unique(table: 'payment_accounts', column: 'chart_of_account_id', ignoreRecord: true)
                    ->helperText('Satu akun untuk satu rekening supaya saldonya terpisah. Belum ada? Pilih "Buat akun baru" di bawah kolom ini.')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->label('Nama akun')->required()->maxLength(120),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $code = static::nextCashCode();

                        if (! $code) {
                            throw new \RuntimeException('Kode akun kas 1103-1199 sudah habis terpakai.');
                        }

                        return ChartOfAccount::create([
                            'code' => $code,
                            'name' => $data['name'],
                            'type' => 'aset',
                            'normal_balance' => ChartOfAccount::normalBalanceFor('aset'),
                            'parent_id' => ChartOfAccount::where('code', '1100')->value('id'),
                            'is_postable' => true,
                            'is_active' => true,
                            'is_cash' => true,
                            'is_contra' => false,
                        ])->id;
                    }),

                Forms\Components\Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Semua toko (pusat)')
                    ->searchable()
                    ->helperText('Kosongkan untuk rekening perusahaan yang dipakai semua toko (mis. rekening bank).'),

                Forms\Components\CheckboxList::make('methods')
                    ->label('Menerima metode bayar')
                    ->options(Booking::PAYMENT_METHOD_LABELS)
                    ->columns(2)
                    ->helperText('Pembayaran booking dengan metode ini masuk ke rekening ini. Rekening toko didahulukan atas rekening pusat.')
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_default')
                    ->label('Rekening bawaan')
                    ->helperText('Dipakai bila metode bayar tidak cocok atau tidak diisi. Satu rekening bawaan per toko (dan satu untuk pusat).'),

                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            ]),

            Forms\Components\Section::make('Kas kecil (dana tetap)')
                ->columns(3)
                ->visible(fn (Forms\Get $get) => $get('kind') === 'kas_kecil')
                ->description('Pengeluaran kecil dari kas ini dicatat lewat Transaksi Keuangan dengan memilih rekeningnya. Sampai batas per bukti, karyawan toko yang sama atau pemegang kas tidak perlu menunggu persetujuan direksi.')
                ->schema([
                    Forms\Components\TextInput::make('float_amount')->label('Plafon dana')->numeric()->minValue(0)->prefix('Rp')->helperText('Saldo yang dijaga; isi ulang sebesar plafon dikurangi saldo.'),
                    Forms\Components\TextInput::make('voucher_limit')->label('Batas per bukti')->numeric()->minValue(0)->prefix('Rp')->helperText('Pengeluaran di atas ini tetap lewat persetujuan. Kosong = semua lewat persetujuan.'),
                    Forms\Components\Select::make('custodian_id')->label('Pemegang kas')->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Tidak ditentukan'),
                ]),

            Forms\Components\Section::make('Data bank (opsional)')
                ->columns(3)
                ->visible(fn (Forms\Get $get) => $get('kind') === 'bank')
                ->schema([
                    Forms\Components\TextInput::make('bank_name')->label('Bank')->maxLength(80),
                    Forms\Components\TextInput::make('account_number')->label('Nomor rekening')->maxLength(40)->helperText('Disimpan terenkripsi.'),
                    Forms\Components\TextInput::make('account_holder')->label('Atas nama')->maxLength(120),
                    Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('kind')->label('Jenis')->badge()->formatStateUsing(fn (string $state) => PaymentAccount::KINDS[$state] ?? $state),
                Tables\Columns\TextColumn::make('chartAccount.display_name')->label('Akun'),
                Tables\Columns\TextColumn::make('store.name')->label('Toko')->placeholder('Pusat'),
                Tables\Columns\TextColumn::make('methods')->label('Metode')->badge()
                    ->formatStateUsing(fn ($state) => Booking::PAYMENT_METHOD_LABELS[$state] ?? $state)->placeholder('—'),
                Tables\Columns\IconColumn::make('is_default')->label('Bawaan')->boolean(),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->state(fn (PaymentAccount $record) => $record->chartAccount ? app(FinancialStatementService::class)->balanceAsOf($record->chartAccount, now()) : 0)
                    ->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('float_amount')->label('Plafon')->money('IDR', locale: 'id')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\Action::make('replenish')
                    ->label('Isi ulang')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('warning')
                    ->visible(fn (PaymentAccount $record) => $record->kind === 'kas_kecil' && $record->float_amount !== null && static::canEdit($record))
                    ->modalDescription(fn (PaymentAccount $record) => 'Saldo sekarang Rp ' . number_format(app(PettyCashService::class)->balance($record), 0, ',', '.') . ', plafon Rp ' . number_format((float) $record->float_amount, 0, ',', '.') . '.')
                    ->form(fn (PaymentAccount $record) => [
                        Forms\Components\Select::make('source_id')->label('Diambil dari')
                            ->options(fn () => ChartOfAccount::where('is_cash', true)->where('is_postable', true)->where('is_active', true)->where('id', '!=', $record->chart_of_account_id)->orderBy('code')->get()->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->required()->searchable(),
                        Forms\Components\TextInput::make('amount')->label('Nominal')->numeric()->required()->minValue(0.01)->prefix('Rp')
                            ->default(app(PettyCashService::class)->replenishAmount($record) ?: null),
                    ])
                    ->action(function (PaymentAccount $record, array $data) {
                        try {
                            $transfer = app(PettyCashService::class)->replenish($record, (int) $data['source_id'], (float) $data['amount'], auth()->id());
                            Notification::make()->title('Kas kecil diisi ulang')->body("Pemindahan {$transfer->transfer_number} dibuat.")->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Tidak bisa diisi ulang')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['chartAccount', 'store']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentAccounts::route('/'),
            'create' => Pages\CreatePaymentAccount::route('/create'),
            'edit' => Pages\EditPaymentAccount::route('/{record}/edit'),
        ];
    }
}
