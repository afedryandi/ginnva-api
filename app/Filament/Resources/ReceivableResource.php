<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReceivableResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Store;
use App\Services\ReceivableService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Piutang Usaha (Accounts Receivable) — cermin PayableResource, arahnya
 * kebalik: uang yang MASIH HARUS DITERIMA dari customer. Sebagian
 * besar baris di sini lahir OTOMATIS dari Booking yang "Nominal
 * Diterima"-nya lebih kecil dari "Nominal Transaksi" (lihat
 * BookingPostingService & aksi "Proses Referral" di BookingResource).
 *
 * TIDAK BISA diedit/dihapus — nominal mengikuti jurnal yang sudah posted
 * & terkunci. Koreksi lewat "Batalkan Pelunasan" / "Batalkan Piutang"
 * (jurnal pembalik otomatis).
 *
 * Audit Piutang Usaha 2026-09-29: halaman View (riwayat pelunasan, jurnal,
 * link booking), akun Kas/Bank, nomor bukti pelunasan, pemisahan tugas,
 * filter & ringkasan total, laporan Umur Piutang, rekonsiliasi 1110,
 * pengingat jatuh tempo, izin aksi lewat Hak Akses Detail.
 */
class ReceivableResource extends Resource
{
    protected static ?string $model = Receivable::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 100-199 "Piutang & Utang".
    protected static ?string $navigationGroup = 'Piutang & Utang';

    protected static ?string $navigationLabel = 'Piutang Usaha';

    protected static ?string $modelLabel = 'Piutang Usaha';

    protected static ?string $pluralModelLabel = 'Piutang Usaha';

    protected static ?int $navigationSort = 100;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        // hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
        // sama pola dgn PayableResource, default FALSE (tetap ketat).
        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'create', false));
    }

    /** Mencatat uang masuk: full-access atau izin 'update' eksplisit (default FALSE). */
    public static function canReceive(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    /** Pembatalan pelunasan/piutang = koreksi akuntansi: full-access saja. */
    public static function canCorrect(): bool
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
        $query = parent::getEloquentQuery()
            ->with(['store', 'creator'])
            ->withCount(['payments as active_payments_count' => fn ($q) => $q->whereNull('voided_at')]);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    /** Akun Kas/Bank yang bisa dipakai menerima pelunasan. */
    private static function cashAccountOptions(): array
    {
        return ChartOfAccount::where('is_cash', true)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name])
            ->all();
    }

    private const STATUS_LABELS = [
        'unpaid' => 'Belum Diterima',
        'partial' => 'Diterima Sebagian',
        'paid' => 'Lunas',
        'cancelled' => 'Dibatalkan',
    ];

    private const SOURCE_LABELS = ['booking' => 'Booking'];

    private static function sourceLabel(?string $type): string
    {
        return self::SOURCE_LABELS[$type ?? ''] ?? 'Manual';
    }

    private static function fail(string $title, \Throwable $e): void
    {
        Notification::make()
            ->title($title)
            ->body($e instanceof QueryException ? 'Terjadi konflik data (mungkin diproses bersamaan). Muat ulang halaman lalu coba lagi.' : $e->getMessage())
            ->danger()
            ->send();

        if ($e instanceof QueryException) {
            report($e);
        }
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Piutang')->columns(3)->schema([
                Infolists\Components\TextEntry::make('receivable_number')->label('No. Piutang')->fontFamily('mono')->copyable(),
                Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'unpaid' => 'danger', 'partial' => 'warning', 'paid' => 'success', default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                Infolists\Components\TextEntry::make('source')->label('Sumber')->state(fn (Receivable $r) => self::sourceLabel($r->source_type)),
                Infolists\Components\TextEntry::make('customer_name')->label('Customer'),
                Infolists\Components\TextEntry::make('store.name')->label('Toko')->placeholder('Company-wide'),
                Infolists\Components\TextEntry::make('due_date')->label('Jatuh Tempo')->date('d M Y')->placeholder('—'),
                Infolists\Components\TextEntry::make('amount')->label('Total Piutang')->money('IDR', locale: 'id'),
                Infolists\Components\TextEntry::make('amount_paid')->label('Sudah Diterima')->money('IDR', locale: 'id'),
                Infolists\Components\TextEntry::make('sisa')->label('Sisa')->state(fn (Receivable $r) => $r->remainingAmount())->money('IDR', locale: 'id')->weight('bold'),
                Infolists\Components\TextEntry::make('creator.name')->label('Dicatat Oleh')->placeholder('Sistem'),
                Infolists\Components\TextEntry::make('created_at')->label('Dicatat Pada')->dateTime('d M Y H:i'),
                Infolists\Components\TextEntry::make('journalEntry.entry_number')->label('Jurnal Pengakuan')->placeholder('—')
                    ->url(fn (Receivable $r) => $r->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $r->journal_entry_id]) : null),
                Infolists\Components\TextEntry::make('booking_link')->label('Booking Asal')
                    ->state(fn (Receivable $r) => $r->source_type === 'booking'
                        ? (\App\Models\Booking::withoutGlobalScopes()->where('id', $r->source_id)->value('booking_number') ?? '—')
                        : '—'),
                Infolists\Components\TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
                Infolists\Components\TextEntry::make('cancel_reason')->label('Dibatalkan')->columnSpanFull()
                    ->state(fn (Receivable $r) => $r->status === 'cancelled'
                        ? ($r->cancelled_at?->format('d M Y H:i') . ' — ' . $r->cancel_reason)
                        : null)
                    ->visible(fn (Receivable $r) => $r->status === 'cancelled'),
            ]),

            Infolists\Components\Section::make('Riwayat Pelunasan')->schema([
                Infolists\Components\RepeatableEntry::make('payments')->label('')->columns(6)->schema([
                    Infolists\Components\TextEntry::make('receipt_number')->label('No. Bukti')->placeholder('—')->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('payment_date')->label('Tanggal')->date('d M Y'),
                    Infolists\Components\TextEntry::make('amount')->label('Nominal')->money('IDR', locale: 'id'),
                    Infolists\Components\TextEntry::make('journalEntry.entry_number')->label('Jurnal')->placeholder('—')
                        ->url(fn ($record) => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
                    Infolists\Components\TextEntry::make('creator.name')->label('Dicatat Oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status_pelunasan')->label('Status')->badge()
                        ->state(fn ($record) => $record->isVoided() ? 'Dibatalkan' : 'Aktif')
                        ->color(fn (string $state) => $state === 'Aktif' ? 'success' : 'gray')
                        ->helperText(fn ($record) => $record->isVoided() ? $record->void_reason : null),
                ]),
            ]),
        ]);
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
                    ->money('IDR', locale: 'id')
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total')
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (float) $query->where('status', '!=', 'cancelled')->sum('amount'))
                            ->money('IDR', locale: 'id')
                    ),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Sudah Diterima')
                    ->money('IDR', locale: 'id')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Sisa')
                    ->state(fn (Receivable $record) => $record->remainingAmount())
                    ->money('IDR', locale: 'id')
                    ->weight('bold')
                    ->color(fn (Receivable $record) => $record->remainingAmount() > 0 ? 'danger' : 'success')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("(amount - amount_paid) {$direction}"))
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total Sisa')
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (float) $query->where('status', '!=', 'cancelled')->sum(DB::raw('amount - amount_paid')))
                            ->money('IDR', locale: 'id')
                    ),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Receivable $record) => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (Receivable $record) => $record->isOverdue() ? 'Terlambat' : null),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('Sumber')
                    ->formatStateUsing(fn (?string $state) => self::sourceLabel($state))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('Sistem')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'danger' => 'unpaid',
                        'warning' => 'partial',
                        'success' => 'paid',
                        'gray' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::STATUS_LABELS),

                Tables\Filters\Filter::make('overdue')
                    ->label('Jatuh Tempo Terlewat')
                    ->query(fn (Builder $query) => $query->whereNotIn('status', ['paid', 'cancelled'])
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '<', now())),

                Tables\Filters\Filter::make('due_range')
                    ->label('Rentang Jatuh Tempo')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Jatuh tempo dari')->native(false),
                        Forms\Components\DatePicker::make('until')->label('Jatuh tempo sampai')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('due_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('due_date', '<=', $d))),

                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->options(fn () => Customer::whereIn('id', Receivable::withoutGlobalScopes()->whereNotNull('customer_id')->select('customer_id'))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                Tables\Filters\SelectFilter::make('source_type')
                    ->label('Sumber')
                    ->options(['manual' => 'Manual'] + self::SOURCE_LABELS)
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            null, '' => $query,
                            'manual' => $query->whereNull('source_type'),
                            default => $query->where('source_type', $value),
                        };
                    }),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
            ])
            ->headerActions([
                Tables\Actions\Action::make('create_manual')
                    ->label('Catat Piutang Manual')
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => static::canCreate())
                    ->form([
                        Forms\Components\Select::make('customer_id')
                            ->label('Customer Terdaftar (opsional)')
                            ->options(fn () => Customer::orderBy('name')->limit(200)->pluck('name', 'id'))
                            ->getSearchResultsUsing(fn (string $search) => Customer::where('name', 'like', "%{$search}%")->orWhere('phone_number', 'like', "%{$search}%")->limit(50)->pluck('name', 'id'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn ($state, Forms\Set $set) => $state ? $set('customer_name', Customer::whereKey($state)->value('name')) : null),

                        Forms\Components\TextInput::make('customer_name')
                            ->label('Nama Customer')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Piutang')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(ReceivableService::MAX_AMOUNT)
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

                        Forms\Components\DatePicker::make('entry_date')
                            ->label('Tanggal Piutang')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->maxDate(today())
                            ->helperText('Tanggal jurnal — boleh mundur selama periodenya belum ditutup.'),

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
                                'customer_id' => $data['customer_id'] ?? null,
                                'store_id' => $data['store_id'] ?? null,
                                'amount' => $data['amount'],
                                'entry_date' => $data['entry_date'],
                                'due_date' => $data['due_date'] ?? null,
                                'notes' => $data['notes'] ?? null,
                                'created_by' => auth()->id(),
                            ], (int) $data['credit_account_id']);

                            Notification::make()->title('Piutang dicatat')->success()->send();
                        } catch (RuntimeException|QueryException $e) {
                            static::fail('Gagal mencatat piutang', $e);
                        }
                    }),

                // Rekonsiliasi cepat saldo 1110 (buku besar) vs total sisa piutang (subledger).
                Tables\Actions\Action::make('reconcile')
                    ->label('Cek Rekonsiliasi')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->visible(fn () => static::canCorrect())
                    ->action(function () {
                        $r = app(ReceivableService::class)->reconcile();
                        $fmt = fn (float $n) => 'Rp ' . number_format($n, 2, ',', '.');
                        $ok = abs($r['diff']) < 0.005;

                        Notification::make()
                            ->title($ok ? 'Sinkron: buku besar = subledger' : 'Ada selisih ' . $fmt(abs($r['diff'])))
                            ->body('Saldo 1110 (jurnal posted): ' . $fmt($r['gl']) . ' — Total sisa piutang: ' . $fmt($r['subledger'])
                                . ($ok ? '' : '. Kemungkinan jurnal manual ke 1110 di luar menu ini, atau data sebelum modul Piutang Usaha.'))
                            ->color($ok ? 'success' : 'warning')
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Lihat'),

                Tables\Actions\Action::make('receive_payment')
                    ->label('Catat Pelunasan')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Receivable $record) => static::canReceive() && in_array($record->status, ['unpaid', 'partial'], true))
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

                        Forms\Components\Select::make('receive_account_id')
                            ->label('Diterima ke')
                            ->options(fn () => static::cashAccountOptions())
                            ->default(fn () => ChartOfAccount::where('code', '1101')->value('id'))
                            ->required()
                            ->searchable(),

                        Forms\Components\DatePicker::make('payment_date')
                            ->label('Tanggal Diterima')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->minDate($record->created_at->copy()->startOfDay())
                            ->maxDate(today()),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan (opsional)')
                            ->rows(2),
                    ])
                    ->action(function (Receivable $record, array $data) {
                        try {
                            $payment = app(ReceivableService::class)->recordPayment(
                                $record,
                                (float) $data['amount'],
                                Carbon::parse($data['payment_date']),
                                auth()->id(),
                                $data['notes'] ?? null,
                                (int) $data['receive_account_id']
                            );

                            Notification::make()->title('Pelunasan dicatat')->body('No. bukti ' . $payment->receipt_number)->success()->send();
                        } catch (RuntimeException|QueryException $e) {
                            static::fail('Gagal mencatat pelunasan', $e);
                        }
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('void_payment')
                        ->label('Batalkan Pelunasan')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn (Receivable $record) => static::canCorrect() && $record->active_payments_count > 0)
                        ->modalDescription('Jurnal pelunasan dibalik otomatis; piutang kembali berstatus belum/sebagian diterima.')
                        ->form(fn (Receivable $record) => [
                            Forms\Components\Select::make('payment_id')
                                ->label('Pelunasan yang dibatalkan')
                                ->options(fn () => $record->payments()->whereNull('voided_at')->get()
                                    ->mapWithKeys(fn ($p) => [$p->id => ($p->receipt_number ? $p->receipt_number . ' — ' : '') . $p->payment_date->format('d M Y') . ' — Rp ' . number_format((float) $p->amount, 0, ',', '.')]))
                                ->required(),
                            Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2)->maxLength(500),
                        ])
                        ->action(function (Receivable $record, array $data) {
                            try {
                                $payment = $record->payments()->whereKey($data['payment_id'])->firstOrFail();
                                app(ReceivableService::class)->voidPayment($payment, auth()->id(), $data['reason']);

                                Notification::make()->title('Pelunasan dibatalkan')->success()->send();
                            } catch (RuntimeException|QueryException $e) {
                                static::fail('Gagal membatalkan pelunasan', $e);
                            }
                        }),

                    Tables\Actions\Action::make('cancel_receivable')
                        ->label('Batalkan Piutang')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Receivable $record) => static::canCorrect()
                            && $record->status !== 'cancelled'
                            && $record->source_type !== 'booking'
                            && $record->active_payments_count === 0)
                        ->requiresConfirmation()
                        ->modalDescription('Jurnal pengakuan piutang dibalik otomatis. Piutang tidak bisa diterima lagi.')
                        ->form([
                            Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2)->maxLength(500),
                        ])
                        ->action(function (Receivable $record, array $data) {
                            try {
                                app(ReceivableService::class)->cancelReceivable($record, auth()->id(), $data['reason']);

                                Notification::make()->title('Piutang dibatalkan')->success()->send();
                            } catch (RuntimeException|QueryException $e) {
                                static::fail('Gagal membatalkan piutang', $e);
                            }
                        }),
                ]),
            ])
            ->defaultSort('due_date');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereNotIn('status', ['paid', 'cancelled'])
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
            'view' => Pages\ViewReceivable::route('/{record}'),
        ];
    }
}
