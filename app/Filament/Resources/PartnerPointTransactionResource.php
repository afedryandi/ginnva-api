<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerPointTransactionResource\Pages;
use App\Models\PartnerPointTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Ledger poin Partner — sebelum app rilis (belum ada booking sama sekali
 * lewat alur normal), staff perlu bisa CATAT poin secara manual (mis.
 * partner sudah aktif referensikan kenalan sebelum sistem booking jalan).
 * SENGAJA bisa create tapi TIDAK bisa edit/delete — begitu tersimpan,
 * entri ledger tidak boleh diubah/dihapus (kalau salah input, catat
 * transaksi KOREKSI baru, bukan mengubah histori) supaya ledger-nya
 * tetap bisa dipercaya sebagai bukti. Setiap create otomatis
 * menambah/mengurangi Partner::points_balance biar tetap sinkron dengan
 * ledger (lihat CreatePartnerPointTransaction::afterCreate()).
 */
class PartnerPointTransactionResource extends Resource
{
    protected static ?string $model = PartnerPointTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 75;

    protected static ?string $navigationLabel = 'Riwayat Poin Partner';

    protected static ?string $modelLabel = 'Transaksi Poin Partner';

    protected static ?string $pluralModelLabel = 'Riwayat Poin Partner';

    /**
     * Manipulasi saldo poin nyata — dibatasi ke full access saja, beda
     * dari resource read-only lain yang boleh dilihat siapa saja dengan
     * menu access.
     */
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
     * PartnerPointTransactionPolicy terdaftar — canView() bawaan Resource
     * (Gate::allows('view', $record)) selalu FALSE untuk siapa pun tanpa
     * policy (default-deny Laravel). Akibatnya tombol "View" (ikon mata)
     * di tabel tidak pernah muncul, dan halaman detail 403 kalau diakses
     * langsung — tidak ada yang bisa buka detail 1 transaksi poin partner
     * sama sekali. Dibiarkan seluas canViewAny() (bukan isFullAccess())
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
            Forms\Components\Select::make('partner_id')
                ->label('Partner')
                ->relationship('partner', 'business_name')
                ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->business_name} ({$record->referral_code})")
                ->searchable(['business_name', 'referral_code'])
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
                Tables\Columns\TextColumn::make('partner.business_name')
                    ->label('Partner')
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
                        'reward_redemption'          => 'Tukar Reward',
                        'reward_redemption_refund'   => 'Refund Reward',
                        'reward_redemption_reversal' => 'Reward Dibatalkan Ulang',
                        'manual'                     => 'Input Manual',
                        default                      => $state ?? '—',
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
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
                        'reward_redemption'          => 'Tukar Reward',
                        'reward_redemption_refund'   => 'Refund Reward',
                        'reward_redemption_reversal' => 'Reward Dibatalkan Ulang',
                        'manual'                     => 'Input Manual',
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
            'index'  => Pages\ListPartnerPointTransactions::route('/'),
            'create' => Pages\CreatePartnerPointTransaction::route('/create'),
            'view'   => Pages\ViewPartnerPointTransaction::route('/{record}'),
        ];
    }
}
