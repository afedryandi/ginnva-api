<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayableResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\Payable;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\PayableService;
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
 * Hutang Usaha (Accounts Payable) — daftar tagihan supplier dengan
 * pelacakan jatuh tempo & pelunasan bertahap. Sebagian besar baris di
 * sini lahir OTOMATIS dari Permohonan Pembelian yang ditandai
 * "Terpenuhi" (lihat PurchaseRequestResource) — resource ini untuk
 * MELIHAT, MEMBAYAR, dan MENGOREKSI (batalkan pembayaran/tagihan lewat
 * jurnal pembalik), bukan mengedit nominal tagihan yang sudah tercatat
 * (jurnal aslinya sudah posted & terkunci).
 *
 * "Catat Tagihan Manual" (header action) untuk tagihan yang TIDAK
 * lewat Permohonan Pembelian (mis. sewa/jasa dari pihak ketiga) —
 * langsung memposting jurnal baru (butuh pilih akun Beban/Aset yang didebit).
 *
 * Audit Hutang Usaha 2026-09-29: master supplier, no. invoice + lampiran,
 * halaman View (riwayat pembayaran & jurnal), pilihan akun Kas/Bank,
 * pembatalan pembayaran/tagihan, filter & ringkasan total, rekonsiliasi 2110,
 * izin aksi memakai Hak Akses Detail (bukan cuma isFullAccess()).
 */
class PayableResource extends Resource
{
    protected static ?string $model = Payable::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 100-199 "Piutang & Utang".
    protected static ?string $navigationGroup = 'Piutang & Utang';

    protected static ?string $navigationLabel = 'Hutang Usaha';

    protected static ?string $modelLabel = 'Hutang Usaha';

    protected static ?string $pluralModelLabel = 'Hutang Usaha';

    protected static ?int $navigationSort = 101;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * "Catat Tagihan Manual" langsung memposting jurnal baru — full-access,
     * atau izin eksplisit lewat "Hak Akses Detail" (default FALSE).
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'create', false));
    }

    /** Membayar hutang mengeluarkan uang: full-access atau izin 'update' eksplisit (default FALSE). */
    public static function canPay(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    /** Pembatalan (pembayaran/tagihan) = koreksi akuntansi: full-access saja. */
    public static function canCorrect(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * TIDAK ADA edit sama sekali — nominal tagihan mengikuti jurnal
     * yang sudah posted & terkunci. Koreksi lewat aksi "Batalkan Pembayaran"
     * / "Batalkan Tagihan" (jurnal pembalik otomatis).
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
        $query = parent::getEloquentQuery()
            ->with(['store', 'creator', 'supplier'])
            ->withCount(['payments as active_payments_count' => fn ($q) => $q->whereNull('voided_at')]);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    /** Akun Kas/Bank yang bisa dipakai membayar. */
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
        'unpaid' => 'Belum Dibayar',
        'partial' => 'Dibayar Sebagian',
        'paid' => 'Lunas',
        'cancelled' => 'Dibatalkan',
    ];

    private const SOURCE_LABELS = [
        'purchase_request' => 'Permohonan Pembelian',
        'recurring_bill_template' => 'Tagihan Rutin',
    ];

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
            Infolists\Components\Section::make('Tagihan')->columns(3)->schema([
                Infolists\Components\TextEntry::make('payable_number')->label('No. Tagihan')->fontFamily('mono')->copyable(),
                Infolists\Components\TextEntry::make('invoice_number')->label('No. Invoice Supplier')->placeholder('—'),
                Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'unpaid' => 'danger', 'partial' => 'warning', 'paid' => 'success', default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state),
                Infolists\Components\TextEntry::make('supplier_name')->label('Supplier'),
                Infolists\Components\TextEntry::make('store.name')->label('Toko')->placeholder('Company-wide'),
                Infolists\Components\TextEntry::make('source')->label('Sumber')->state(fn (Payable $r) => self::sourceLabel($r->source_type)),
                Infolists\Components\TextEntry::make('amount')->label('Total Tagihan')->money('IDR', locale: 'id'),
                Infolists\Components\TextEntry::make('amount_paid')->label('Sudah Dibayar')->money('IDR', locale: 'id'),
                Infolists\Components\TextEntry::make('sisa')->label('Sisa')->state(fn (Payable $r) => $r->remainingAmount())->money('IDR', locale: 'id')->weight('bold'),
                Infolists\Components\TextEntry::make('due_date')->label('Jatuh Tempo')->date('d M Y')->placeholder('—'),
                Infolists\Components\TextEntry::make('creator.name')->label('Dicatat Oleh')->placeholder('Sistem'),
                Infolists\Components\TextEntry::make('created_at')->label('Dicatat Pada')->dateTime('d M Y H:i'),
                Infolists\Components\TextEntry::make('journalEntry.entry_number')->label('Jurnal Pengakuan')->placeholder('—')
                    ->url(fn (Payable $r) => $r->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $r->journal_entry_id]) : null),
                Infolists\Components\TextEntry::make('attachment')->label('Lampiran')
                    ->state(fn (Payable $r) => $r->attachment ? 'Lihat lampiran' : null)
                    ->url(fn (Payable $r) => $r->attachment
                        ? \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk', 'public'))->url($r->attachment)
                        : null)
                    ->openUrlInNewTab()
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
                Infolists\Components\TextEntry::make('cancel_reason')->label('Dibatalkan')->columnSpanFull()
                    ->state(fn (Payable $r) => $r->status === 'cancelled'
                        ? ($r->cancelled_at?->format('d M Y H:i') . ' — ' . $r->cancel_reason)
                        : null)
                    ->visible(fn (Payable $r) => $r->status === 'cancelled'),
            ]),

            Infolists\Components\Section::make('Riwayat Pembayaran')->schema([
                Infolists\Components\RepeatableEntry::make('payments')->label('')->columns(5)->schema([
                    Infolists\Components\TextEntry::make('payment_date')->label('Tanggal')->date('d M Y'),
                    Infolists\Components\TextEntry::make('amount')->label('Nominal')->money('IDR', locale: 'id'),
                    Infolists\Components\TextEntry::make('journalEntry.entry_number')->label('Jurnal')->placeholder('—')
                        ->url(fn ($record) => $record->journal_entry_id ? JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]) : null),
                    Infolists\Components\TextEntry::make('creator.name')->label('Dibayar Oleh')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status_pembayaran')->label('Status')->badge()
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
                Tables\Columns\TextColumn::make('payable_number')
                    ->label('No. Tagihan')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Payable $record) => $record->invoice_number ? 'Inv. ' . $record->invoice_number : null),

                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Company-wide')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total Tagihan')
                    ->money('IDR', locale: 'id')
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total')
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (float) $query->where('status', '!=', 'cancelled')->sum('amount'))
                            ->money('IDR', locale: 'id')
                    ),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Sudah Dibayar')
                    ->money('IDR', locale: 'id')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Sisa')
                    ->state(fn (Payable $record) => $record->remainingAmount())
                    ->money('IDR', locale: 'id')
                    ->weight('bold')
                    ->color(fn (Payable $record) => $record->remainingAmount() > 0 ? 'danger' : 'success')
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
                    ->color(fn (Payable $record) => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (Payable $record) => $record->isOverdue() ? 'Terlambat' : null),

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

                Tables\Columns\TextColumn::make('active_payments_count')
                    ->label('Pembayaran')
                    ->suffix('x')
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

                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))
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
                    ->label('Catat Tagihan Manual')
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => static::canCreate())
                    ->form([
                        Forms\Components\Select::make('supplier_id')
                            ->label('Supplier')
                            ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')->label('Nama Supplier')->required()->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data) => Supplier::create($data)->getKey()),

                        Forms\Components\TextInput::make('invoice_number')
                            ->label('No. Invoice Supplier (opsional)')
                            ->maxLength(100),

                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Tagihan')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(PayableService::MAX_AMOUNT)
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

                        Forms\Components\DatePicker::make('entry_date')
                            ->label('Tanggal Tagihan / Invoice')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->maxDate(today())
                            ->helperText('Tanggal jurnal — boleh mundur selama periodenya belum ditutup.'),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Jatuh Tempo (opsional)')
                            ->native(false),

                        Forms\Components\FileUpload::make('attachment')
                            ->label('Lampiran Invoice (opsional)')
                            ->disk(config('filament.default_filesystem_disk', 'public'))
                            ->directory('payable-attachments')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(5120),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2),
                    ])
                    ->action(function (array $data) {
                        try {
                            $supplier = Supplier::findOrFail($data['supplier_id']);

                            app(PayableService::class)->createWithJournal([
                                'supplier_name' => $supplier->name,
                                'supplier_id' => $supplier->id,
                                'invoice_number' => $data['invoice_number'] ?? null,
                                'attachment' => $data['attachment'] ?? null,
                                'store_id' => $data['store_id'] ?? null,
                                'amount' => $data['amount'],
                                'entry_date' => $data['entry_date'],
                                'due_date' => $data['due_date'] ?? null,
                                'notes' => $data['notes'] ?? null,
                                'created_by' => auth()->id(),
                            ], (int) $data['debit_account_id']);

                            Notification::make()->title('Tagihan dicatat')->success()->send();
                        } catch (RuntimeException|QueryException $e) {
                            static::fail('Gagal mencatat tagihan', $e);
                        }
                    }),

                // Rekonsiliasi cepat saldo 2110 (buku besar) vs total sisa tagihan (subledger).
                Tables\Actions\Action::make('reconcile')
                    ->label('Cek Rekonsiliasi')
                    ->icon('heroicon-o-scale')
                    ->color('gray')
                    ->visible(fn () => static::canCorrect())
                    ->action(function () {
                        $r = app(PayableService::class)->reconcile();
                        $fmt = fn (float $n) => 'Rp ' . number_format($n, 2, ',', '.');
                        $ok = abs($r['diff']) < 0.005;

                        Notification::make()
                            ->title($ok ? 'Sinkron: buku besar = subledger' : 'Ada selisih ' . $fmt(abs($r['diff'])))
                            ->body('Saldo 2110 (jurnal posted): ' . $fmt($r['gl']) . ' — Total sisa tagihan: ' . $fmt($r['subledger'])
                                . ($ok ? '' : '. Kemungkinan jurnal manual ke 2110 di luar menu ini, atau data sebelum modul Hutang Usaha.'))
                            ->color($ok ? 'success' : 'warning')
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Lihat'),

                Tables\Actions\Action::make('pay')
                    ->label('Catat Pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Payable $record) => static::canPay() && in_array($record->status, ['unpaid', 'partial'], true))
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

                        Forms\Components\Select::make('payment_account_id')
                            ->label('Dibayar dari')
                            ->options(fn () => static::cashAccountOptions())
                            ->default(fn () => ChartOfAccount::where('code', '1101')->value('id'))
                            ->required()
                            ->searchable(),

                        Forms\Components\DatePicker::make('payment_date')
                            ->label('Tanggal Bayar')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->minDate($record->created_at->copy()->startOfDay())
                            ->maxDate(today()),

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
                                $data['notes'] ?? null,
                                (int) $data['payment_account_id']
                            );

                            Notification::make()->title('Pembayaran dicatat')->success()->send();
                        } catch (RuntimeException|QueryException $e) {
                            static::fail('Gagal mencatat pembayaran', $e);
                        }
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('void_payment')
                        ->label('Batalkan Pembayaran')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn (Payable $record) => static::canCorrect() && $record->active_payments_count > 0)
                        ->modalDescription('Jurnal pembayaran dibalik otomatis; tagihan kembali berstatus belum/sebagian dibayar.')
                        ->form(fn (Payable $record) => [
                            Forms\Components\Select::make('payment_id')
                                ->label('Pembayaran yang dibatalkan')
                                ->options(fn () => $record->payments()->whereNull('voided_at')->get()
                                    ->mapWithKeys(fn ($p) => [$p->id => $p->payment_date->format('d M Y') . ' — Rp ' . number_format((float) $p->amount, 0, ',', '.')]))
                                ->required(),
                            Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2)->maxLength(500),
                        ])
                        ->action(function (Payable $record, array $data) {
                            try {
                                $payment = $record->payments()->whereKey($data['payment_id'])->firstOrFail();
                                app(PayableService::class)->voidPayment($payment, auth()->id(), $data['reason']);

                                Notification::make()->title('Pembayaran dibatalkan')->success()->send();
                            } catch (RuntimeException|QueryException $e) {
                                static::fail('Gagal membatalkan pembayaran', $e);
                            }
                        }),

                    Tables\Actions\Action::make('cancel_payable')
                        ->label('Batalkan Tagihan')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Payable $record) => static::canCorrect()
                            && $record->status !== 'cancelled'
                            && $record->source_type !== 'purchase_request'
                            && $record->active_payments_count === 0)
                        ->requiresConfirmation()
                        ->modalDescription('Jurnal pengakuan hutang dibalik otomatis. Tagihan tidak bisa dibayar lagi.')
                        ->form([
                            Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2)->maxLength(500),
                        ])
                        ->action(function (Payable $record, array $data) {
                            try {
                                app(PayableService::class)->cancelPayable($record, auth()->id(), $data['reason']);

                                Notification::make()->title('Tagihan dibatalkan')->success()->send();
                            } catch (RuntimeException|QueryException $e) {
                                static::fail('Gagal membatalkan tagihan', $e);
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
            'index' => Pages\ListPayables::route('/'),
            'view' => Pages\ViewPayable::route('/{record}'),
        ];
    }
}
