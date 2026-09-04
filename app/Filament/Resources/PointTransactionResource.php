<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PointTransactionResource\Pages;
use App\Models\PointTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Ledger poin customer (earn/spend dari booking, bonus "ajak teman"
 * antar-customer, reward redemption, registrasi garansi, dst). Baris
 * yang SUDAH tersimpan tidak bisa diedit/dihapus SAMA SEKALI (sama
 * filosofi dengan PartnerPointTransactionResource — kalau salah input,
 * catat transaksi KOREKSI baru, bukan mengubah histori, supaya ledger
 * tetap bisa dipercaya sebagai bukti).
 *
 * SEBELUMNYA create() juga false total (murni read-only) — tidak ada
 * jalur sama sekali untuk kasus customer service (mis. "maaf atas
 * ketidaknyamanan, bonus 50 poin") atau koreksi data poin yang salah,
 * admin harus utak-atik database langsung. Sekarang full-access bisa
 * catat entri manual (reference_type='manual'), sama pola dengan
 * PartnerPointTransactionResource. Ditemukan & dibangun saat audit
 * modul Marketing > Riwayat Poin Customer.
 */
class PointTransactionResource extends Resource
{
    protected static ?string $model = PointTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 65;

    protected static ?string $navigationLabel = 'Riwayat Poin Customer';

    protected static ?string $modelLabel = 'Transaksi Poin';

    protected static ?string $pluralModelLabel = 'Riwayat Poin Customer';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * SEBELUMNYA tidak ada override di sini dan tidak ada
     * PointTransactionPolicy terdaftar — canView() bawaan Resource
     * (Gate::allows('view', $record)) selalu FALSE untuk siapa pun tanpa
     * policy (default-deny Laravel). Akibatnya tombol "View" (ikon mata)
     * di tabel tidak pernah muncul, dan halaman detail 403 kalau diakses
     * langsung — sama bug class dengan yang ditemukan di
     * PartnerPointTransactionResource (audit modul Marketing > Riwayat
     * Poin Partner). Dibiarkan seluas canViewAny(), bukan isFullAccess(),
     * karena melihat detail baris yang sudah tampil di tabel bukan
     * tindakan lebih sensitif dari melihat tabelnya sendiri.
     */
    public static function canView($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('customer_id')
                ->label('Customer')
                ->relationship('customer', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => trim(($record->name ?? 'Tanpa Nama') . ' — ' . ($record->phone_number ?? $record->email ?? '')))
                ->searchable(['name', 'phone_number', 'email'])
                ->preload()
                ->required(),

            Forms\Components\Select::make('type')
                ->label('Tipe')
                ->options([
                    'earn'  => 'Dapat Poin (+)',
                    'spend' => 'Pakai Poin (-)',
                ])
                ->required()
                ->live(),

            Forms\Components\TextInput::make('points')
                ->label('Jumlah Poin')
                ->numeric()
                ->minValue(1)
                ->required(),

            Forms\Components\Textarea::make('description')
                ->label('Keterangan')
                ->placeholder('Wajib diisi — jelaskan alasan poin ini diberikan/dikurangi, supaya bisa ditelusuri nanti.')
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Tipe')
                    ->colors([
                        'success' => 'earn',
                        'danger'  => 'spend',
                    ])
                    ->formatStateUsing(fn (string $state): string => $state === 'earn' ? 'Dapat Poin' : 'Pakai Poin'),

                Tables\Columns\TextColumn::make('points')
                    ->label('Poin')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Sumber')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'booking'                    => 'Booking',
                        'customer_referral'          => 'Ajak Teman',
                        'warranty'                   => 'Registrasi Garansi',
                        'reward_redemption'          => 'Tukar Reward',
                        'reward_redemption_refund'   => 'Refund Reward',
                        'reward_redemption_reversal' => 'Reward Dibatalkan Ulang',
                        'manual'                     => 'Entri Manual Admin',
                        default                      => $state ?? '—',
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipe')
                    ->options([
                        'earn'  => 'Dapat Poin',
                        'spend' => 'Pakai Poin',
                    ]),
                Tables\Filters\SelectFilter::make('reference_type')
                    ->label('Sumber')
                    ->options([
                        'booking'                    => 'Booking',
                        'customer_referral'          => 'Ajak Teman',
                        'warranty'                   => 'Registrasi Garansi',
                        'reward_redemption'          => 'Tukar Reward',
                        'reward_redemption_refund'   => 'Refund Reward',
                        'reward_redemption_reversal' => 'Reward Dibatalkan Ulang',
                        'manual'                     => 'Entri Manual Admin',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPointTransactions::route('/'),
            'create' => Pages\CreatePointTransaction::route('/create'),
            'view'   => Pages\ViewPointTransaction::route('/{record}'),
        ];
    }
}
