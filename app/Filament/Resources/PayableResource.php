<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayableResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\Payable;
use App\Models\Store;
use App\Services\PayableService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Hutang Usaha (Accounts Payable) — daftar tagihan supplier dengan
 * pelacakan jatuh tempo & pelunasan bertahap. Sebagian besar baris di
 * sini lahir OTOMATIS dari Permohonan Pembelian yang ditandai
 * "Terpenuhi" (lihat PurchaseRequestResource) — resource ini murni
 * untuk MELIHAT & MEMBAYAR, bukan mengedit nominal tagihan yang sudah
 * tercatat (jurnal aslinya sudah posted & terkunci).
 *
 * "Catat Tagihan Manual" (header action) untuk tagihan yang TIDAK
 * lewat Permohonan Pembelian (mis. sewa/jasa dari pihak ketiga) —
 * TERBATAS full-access karena langsung memposting jurnal baru
 * (butuh pilih akun Beban/Aset yang didebit).
 */
class PayableResource extends Resource
{
    protected static ?string $model = Payable::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Hutang Usaha';

    protected static ?string $modelLabel = 'Hutang Usaha';

    protected static ?string $pluralModelLabel = 'Hutang Usaha';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * "Catat Tagihan Manual" langsung memposting jurnal baru (bukan
     * cuma mencatat detail dari jurnal yang sudah ada seperti alur
     * Permohonan Pembelian) — TERBATAS full-access, sama filosofi
     * dengan ChartOfAccountResource/JournalEntryResource.
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * TIDAK ADA edit sama sekali — nominal tagihan mengikuti jurnal
     * yang sudah posted & terkunci, mengedit Payable tanpa mengedit
     * jurnalnya akan bikin dua sisi tidak sinkron. Koreksi dilakukan
     * lewat Jurnal Umum (jurnal pembalik) kalau memang perlu.
     */
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
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('payable_number')
                    ->label('No. Tagihan')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Company-wide')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total Tagihan')
                    ->money('IDR', locale: 'id'),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Sudah Dibayar')
                    ->money('IDR', locale: 'id')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Sisa')
                    ->state(fn (Payable $record) => $record->remainingAmount())
                    ->money('IDR', locale: 'id')
                    ->weight('bold')
                    ->color(fn (Payable $record) => $record->remainingAmount() > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->color(fn (Payable $record) => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (Payable $record) => $record->isOverdue() ? 'Terlambat' : null),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'danger' => 'unpaid',
                        'warning' => 'partial',
                        'success' => 'paid',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'unpaid' => 'Belum Dibayar',
                        'partial' => 'Dibayar Sebagian',
                        'paid' => 'Lunas',
                        default => $state,
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['unpaid' => 'Belum Dibayar', 'partial' => 'Dibayar Sebagian', 'paid' => 'Lunas']),

                Tables\Filters\Filter::make('overdue')
                    ->label('Jatuh Tempo Terlewat')
                    ->query(fn (Builder $query) => $query->where('status', '!=', 'paid')
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '<', now())),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
            ])
            ->headerActions([
                Tables\Actions\Action::make('create_manual')
                    ->label('Catat Tagihan Manual')
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->form([
                        Forms\Components\TextInput::make('supplier_name')
                            ->label('Nama Supplier')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Tagihan')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('Rp'),

                        Forms\Components\Select::make('debit_account_id')
                            ->label('Akun yang Didebit')
                            ->helperText('Akun Beban/Aset yang sesuai — mis. Beban Sewa Toko kalau tagihan ini sewa.')
                            ->options(fn () => ChartOfAccount::where('is_postable', true)
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->searchable()
                            ->required(),

                        Forms\Components\Select::make('store_id')
                            ->label('Toko (opsional)')
                            ->options(fn () => Store::pluck('name', 'id'))
                            ->placeholder('Company-wide')
                            ->searchable(),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Jatuh Tempo (opsional)')
                            ->native(false),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(PayableService::class)->createWithJournal([
                                'supplier_name' => $data['supplier_name'],
                                'store_id' => $data['store_id'] ?? null,
                                'amount' => $data['amount'],
                                'due_date' => $data['due_date'] ?? null,
                                'notes' => $data['notes'] ?? null,
                                'created_by' => auth()->id(),
                            ], $data['debit_account_id']);

                            Notification::make()->title('Tagihan dicatat')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal mencatat tagihan')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('pay')
                    ->label('Catat Pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Payable $record) => (auth()->user()?->isFullAccess() ?? false) && $record->status !== 'paid')
                    ->form(fn (Payable $record) => [
                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Dibayar')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue($record->remainingAmount())
                            ->default($record->remainingAmount())
                            ->prefix('Rp')
                            ->helperText('Sisa tagihan: Rp ' . number_format($record->remainingAmount(), 0, ',', '.')),

                        Forms\Components\DatePicker::make('payment_date')
                            ->label('Tanggal Bayar')
                            ->native(false)
                            ->required()
                            ->default(now()),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan (opsional)')
                            ->rows(2),
                    ])
                    ->action(function (Payable $record, array $data) {
                        try {
                            app(PayableService::class)->recordPayment(
                                $record,
                                (float) $data['amount'],
                                Carbon::parse($data['payment_date']),
                                auth()->id(),
                                $data['notes'] ?? null
                            );

                            Notification::make()->title('Pembayaran dicatat')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal mencatat pembayaran')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('due_date');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'danger';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayables::route('/'),
        ];
    }
}
