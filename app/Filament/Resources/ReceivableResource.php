<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReceivableResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\Receivable;
use App\Models\Store;
use App\Services\ReceivableService;
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
 * Piutang Usaha (Accounts Receivable) — cermin PayableResource, arahnya
 * kebalik: uang yang MASIH HARUS DITERIMA dari customer. Sebagian
 * besar baris di sini lahir OTOMATIS dari Booking yang "Nominal
 * Diterima"-nya lebih kecil dari "Nominal Transaksi" (lihat
 * BookingPostingService & aksi "Proses Referral" di BookingResource).
 *
 * TIDAK BISA diedit/dihapus — sama alasan dengan PayableResource,
 * nominal mengikuti jurnal yang sudah posted & terkunci.
 */
class ReceivableResource extends Resource
{
    protected static ?string $model = Receivable::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Piutang Usaha';

    protected static ?string $modelLabel = 'Piutang Usaha';

    protected static ?string $pluralModelLabel = 'Piutang Usaha';

    protected static ?int $navigationSort = 6;

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
                Tables\Columns\TextColumn::make('receivable_number')
                    ->label('No. Piutang')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Company-wide')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total Piutang')
                    ->money('IDR', locale: 'id'),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Sudah Diterima')
                    ->money('IDR', locale: 'id')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Sisa')
                    ->state(fn (Receivable $record) => $record->remainingAmount())
                    ->money('IDR', locale: 'id')
                    ->weight('bold')
                    ->color(fn (Receivable $record) => $record->remainingAmount() > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->color(fn (Receivable $record) => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (Receivable $record) => $record->isOverdue() ? 'Terlambat' : null),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'danger' => 'unpaid',
                        'warning' => 'partial',
                        'success' => 'paid',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'unpaid' => 'Belum Diterima',
                        'partial' => 'Diterima Sebagian',
                        'paid' => 'Lunas',
                        default => $state,
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['unpaid' => 'Belum Diterima', 'partial' => 'Diterima Sebagian', 'paid' => 'Lunas']),

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
                    ->label('Catat Piutang Manual')
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                    ->form([
                        Forms\Components\TextInput::make('customer_name')
                            ->label('Nama Customer')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Piutang')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('Rp'),

                        Forms\Components\Select::make('credit_account_id')
                            ->label('Akun yang Dikredit')
                            ->helperText('Akun Pendapatan yang sesuai — mis. Pendapatan Lain-lain kalau bukan dari Booking.')
                            ->options(fn () => ChartOfAccount::where('is_postable', true)
                                ->where('is_active', true)
                                ->whereIn('type', ['pendapatan', 'pendapatan_lain'])
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
                            app(ReceivableService::class)->createWithJournal([
                                'customer_name' => $data['customer_name'],
                                'store_id' => $data['store_id'] ?? null,
                                'amount' => $data['amount'],
                                'due_date' => $data['due_date'] ?? null,
                                'notes' => $data['notes'] ?? null,
                                'created_by' => auth()->id(),
                            ], $data['credit_account_id']);

                            Notification::make()->title('Piutang dicatat')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal mencatat piutang')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('receive_payment')
                    ->label('Catat Pelunasan')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Receivable $record) => (auth()->user()?->isFullAccess() ?? false) && $record->status !== 'paid')
                    ->form(fn (Receivable $record) => [
                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Diterima')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue($record->remainingAmount())
                            ->default($record->remainingAmount())
                            ->prefix('Rp')
                            ->helperText('Sisa piutang: Rp ' . number_format($record->remainingAmount(), 0, ',', '.')),

                        Forms\Components\DatePicker::make('payment_date')
                            ->label('Tanggal Diterima')
                            ->native(false)
                            ->required()
                            ->default(now()),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan (opsional)')
                            ->rows(2),
                    ])
                    ->action(function (Receivable $record, array $data) {
                        try {
                            app(ReceivableService::class)->recordPayment(
                                $record,
                                (float) $data['amount'],
                                Carbon::parse($data['payment_date']),
                                auth()->id(),
                                $data['notes'] ?? null
                            );

                            Notification::make()->title('Pelunasan dicatat')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal mencatat pelunasan')
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
            'index' => Pages\ListReceivables::route('/'),
        ];
    }
}
