<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JournalEntryResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Services\JournalEntryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Jurnal Umum — TERBATAS full-access (super_admin/direksi), sama
 * filosofi dengan ChartOfAccountResource/PayrollResource: pembukuan
 * berpasangan bukan operasional harian yang cocok didelegasikan ke
 * store_manager (mereka tetap pakai Transaksi Keuangan yang sederhana).
 *
 * Semua tulis-menulis WAJIB lewat JournalEntryService (create/update/
 * post/reverse) — resource ini TIDAK PERNAH panggil JournalEntry::
 * create()/update() langsung, supaya validasi balance debit=kredit
 * selalu tertegak di SATU tempat, tidak bisa dilewati dari jalur mana
 * pun (termasuk kalau nanti ada integrasi otomatis Fase 3).
 *
 * Jurnal 'posted' TERKUNCI total (semua field ->disabled()) — koreksi
 * lewat aksi "Balik Jurnal" (bikin jurnal pembalik baru), bukan edit
 * langsung, supaya riwayat pembukuan selalu bisa diaudit.
 */
class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Jurnal Umum';

    protected static ?string $modelLabel = 'Jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal Umum';

    protected static ?int $navigationSort = 4;

    // Diperluas 2026-09-24 (keputusan user) — spv_finance boleh, tapi
    // tetap lewat hasMenuAccess() (harus dicentang eksplisit di "Akses
    // Menu"), dan create/edit/delete tetap default FALSE via
    // hasModuleAction() supaya view saja tidak otomatis termasuk hak
    // tulis pembukuan.
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class));
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'create', false));
    }

    /**
     * SENGAJA selalu true untuk full-access (bukan dibatasi status
     * draft) — halaman Edit dipakai DUA fungsi (edit beneran untuk
     * draft, tampilan read-only untuk posted, lihat form() di bawah)
     * supaya tidak perlu halaman View terpisah. Penguncian aktualnya
     * ada di ->disabled() per-field + JournalEntryService::update()
     * yang menolak kalau statusnya sudah bukan draft. Sama untuk
     * spv_finance — akses Edit page dibuka via hasModuleAction('update'),
     * penguncian read-only untuk posted tetap sama persis.
     */
    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        $allowed = $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));

        return $allowed && $record->isDraft();
    }

    /**
     * Hak POSTING (audit Jurnal Umum 2026-09-29): sebelumnya aksi "Posting"
     * cuma ber-visible() berdasarkan status, jadi spv_finance yang hanya punya
     * akses LIHAT pun bisa memposting. Sekarang: full-access, atau spv_finance
     * dengan hak 'update' (default false) -- dan spv_finance TIDAK boleh
     * memposting jurnal yang ia buat sendiri (segregation of duties).
     */
    public static function canPost(JournalEntry $record): bool
    {
        $user = auth()->user();

        if (! $record->isDraft()) {
            return false;
        }

        if ($user?->isFullAccess()) {
            return true;
        }

        return ($user?->hasRole('spv_finance') ?? false)
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'update', false)
            && (int) $record->created_by !== (int) $user->id;
    }

    /**
     * Pembalikan MANUAL hanya untuk jurnal manual (reference_type kosong).
     * Jurnal otomatis (booking, transaksi keuangan, penyusutan, DP, dst.)
     * dibalik lewat MODUL ASALNYA -- kalau dibalik dari sini, modul asal
     * (yang menyimpan journal_entry_id) tidak tahu dan bisa memposting
     * jurnal ganda. Jurnal pembalik sendiri tidak bisa dibalik lagi.
     */
    public static function canReverse(JournalEntry $record): bool
    {
        $user = auth()->user();

        if (! $record->isPosted() || ! in_array($record->reference_type, [null, 'manual'], true) || $record->reversal()->exists()) {
            return false;
        }

        if ($user?->isFullAccess()) {
            return true;
        }

        return ($user?->hasRole('spv_finance') ?? false)
            && $user->hasMenuAccess(static::class)
            && $user->hasModuleAction(static::class, 'update', false)
            && (int) $record->created_by !== (int) $user->id;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Jurnal')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('entry_number')
                        ->label('No. Jurnal')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?JournalEntry $record) => $record !== null)
                        ->helperText('Dibuat otomatis oleh sistem.'),

                    Forms\Components\DatePicker::make('entry_date')
                        ->label('Tanggal')
                        ->native(false)
                        ->required()
                        ->default(now())
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko (opsional)')
                        ->options(fn () => Store::pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Company-wide / tidak terikat 1 toko')
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted'),

                    Forms\Components\Textarea::make('description')
                        ->label('Keterangan')
                        ->required()
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted'),
                ]),

            Forms\Components\Section::make('Baris Debit / Kredit')
                ->description('Minimal 2 baris, total debit harus sama dengan total kredit.')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('chart_of_account_id')
                                ->label('Akun')
                                ->options(fn () => static::accountOptions())
                                ->searchable()
                                ->required()
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('debit')
                                ->label('Debit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(\App\Services\JournalEntryService::MAX_AMOUNT)
                                ->live(onBlur: true)
                                // Debit & kredit saling eksklusif: mengisi salah satunya mengosongkan yang lain.
                                ->afterStateUpdated(fn ($state, Forms\Set $set) => (float) $state > 0 ? $set('credit', 0) : null)
                                ->prefix('Rp'),

                            Forms\Components\TextInput::make('credit')
                                ->label('Kredit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(\App\Services\JournalEntryService::MAX_AMOUNT)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, Forms\Set $set) => (float) $state > 0 ? $set('debit', 0) : null)
                                ->prefix('Rp'),

                            Forms\Components\TextInput::make('description')
                                ->label('Keterangan Baris (opsional)')
                                ->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->minItems(2)
                        ->maxItems(\App\Services\JournalEntryService::MAX_LINES)
                        ->addActionLabel('+ Tambah Baris')
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted')
                        ->deletable(fn (?JournalEntry $record) => $record?->status !== 'posted')
                        ->addable(fn (?JournalEntry $record) => $record?->status !== 'posted')
                        ->live(),

                    // Total & selisih LIVE (gap audit Jurnal Umum 2026-09-29):
                    // sebelumnya baru ketahuan tidak balance setelah Simpan ditolak.
                    Forms\Components\Placeholder::make('totals')
                        ->label('Ringkasan')
                        ->content(function (Forms\Get $get): \Illuminate\Support\HtmlString {
                            $debit = 0;
                            $credit = 0;
                            foreach ($get('lines') ?? [] as $line) {
                                $debit += (int) round(((float) ($line['debit'] ?? 0)) * 100);
                                $credit += (int) round(((float) ($line['credit'] ?? 0)) * 100);
                            }

                            $diff = $debit - $credit;
                            $fmt = fn (int $cents) => 'Rp' . number_format($cents / 100, 2, ',', '.');
                            $status = $diff === 0 && $debit > 0
                                ? '<span style="color:#16a34a;font-weight:600">Seimbang</span>'
                                : '<span style="color:#dc2626;font-weight:600">Selisih ' . $fmt(abs($diff)) . '</span>';

                            return new \Illuminate\Support\HtmlString(
                                'Total Debit: <b>' . $fmt($debit) . '</b> &nbsp;|&nbsp; Total Kredit: <b>' . $fmt($credit) . '</b> &nbsp;|&nbsp; ' . $status
                            );
                        }),
                ]),

            Forms\Components\Section::make('Lampiran')
                ->collapsed()
                ->schema([
                    Forms\Components\FileUpload::make('attachment')
                        ->label('Bukti / Lampiran (opsional)')
                        ->disk(config('filament.default_filesystem_disk', 'public'))
                        ->directory('journal-attachments')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(5120)
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted')
                        ->helperText('Foto atau PDF, maks. 5 MB. Terkunci setelah jurnal diposting.'),
                ]),
        ]);
    }

    /** Opsi akun di-cache per request (sebelumnya 1 query per baris Repeater). */
    private static function accountOptions(): array
    {
        static $options = null;

        return $options ??= ChartOfAccount::where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name])
            ->all();
    }

    private const SOURCE_LABELS = [
        'manual' => 'Manual',
        'reversal' => 'Pembalik',
        'booking' => 'Booking',
        'finance_transaction' => 'Transaksi Keuangan',
    ];

    private static function sourceLabel(?string $type): string
    {
        return self::SOURCE_LABELS[$type ?? 'manual'] ?? ucfirst(str_replace('_', ' ', (string) $type));
    }

    /**
     * Detail jurnal (halaman View): baris debit/kredit, total & selisih,
     * pembuat/pemosting, tautan dua arah ke jurnal asal/pembalik.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Jurnal')->columns(3)->schema([
                Infolists\Components\TextEntry::make('entry_number')->label('No. Jurnal')->fontFamily('mono')->copyable(),
                Infolists\Components\TextEntry::make('entry_date')->label('Tanggal')->date('d M Y'),
                Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => $state === 'posted' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state) => $state === 'posted' ? 'Posted' : 'Draft'),
                Infolists\Components\TextEntry::make('store.name')->label('Toko')->placeholder('Company-wide'),
                Infolists\Components\TextEntry::make('source')->label('Sumber')->state(fn (JournalEntry $r) => static::sourceLabel($r->reference_type)),
                Infolists\Components\TextEntry::make('creator.name')->label('Dibuat Oleh')->placeholder('—'),
                Infolists\Components\TextEntry::make('poster.name')->label('Diposting Oleh')->placeholder('—'),
                Infolists\Components\TextEntry::make('posted_at')->label('Diposting Pada')->dateTime('d M Y H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('attachment')->label('Lampiran')
                    ->state(fn (JournalEntry $r) => $r->attachment ? 'Lihat lampiran' : null)
                    ->url(fn (JournalEntry $r) => $r->attachment
                        ? \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk', 'public'))->url($r->attachment)
                        : null)
                    ->openUrlInNewTab()
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('description')->label('Keterangan')->columnSpanFull(),
            ]),

            Infolists\Components\Section::make('Baris Jurnal')->schema([
                Infolists\Components\RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                    Infolists\Components\TextEntry::make('account.display_name')->label('Akun')->columnSpan(2),
                    Infolists\Components\TextEntry::make('debit')->label('Debit')->money('IDR', locale: 'id'),
                    Infolists\Components\TextEntry::make('credit')->label('Kredit')->money('IDR', locale: 'id'),
                ]),
                Infolists\Components\TextEntry::make('ringkasan')->label('Ringkasan')
                    ->state(function (JournalEntry $r) {
                        $d = (int) round($r->lines->sum('debit') * 100);
                        $c = (int) round($r->lines->sum('credit') * 100);
                        $fmt = fn (int $x) => 'Rp' . number_format($x / 100, 2, ',', '.');

                        return 'Debit ' . $fmt($d) . '  |  Kredit ' . $fmt($c) . '  |  ' . ($d === $c ? 'Seimbang' : 'Selisih ' . $fmt(abs($d - $c)));
                    }),
            ]),

            Infolists\Components\Section::make('Terkait')->columns(2)->schema([
                Infolists\Components\TextEntry::make('origin')->label('Jurnal Asal (kalau ini pembalik)')
                    ->state(fn (JournalEntry $r) => $r->reference_type === 'reversal'
                        ? (JournalEntry::withoutGlobalScopes()->where('id', $r->reference_id)->value('entry_number') ?? '—')
                        : '—')
                    ->url(fn (JournalEntry $r) => $r->reference_type === 'reversal' && $r->reference_id
                        ? static::getUrl('view', ['record' => $r->reference_id])
                        : null),
                Infolists\Components\TextEntry::make('reversal_link')->label('Jurnal Pembalik (kalau sudah dibalik)')
                    ->state(fn (JournalEntry $r) => $r->reversal?->entry_number ?? '—')
                    ->url(fn (JournalEntry $r) => $r->reversal ? static::getUrl('view', ['record' => $r->reversal->id]) : null),
            ]),
        ]);
    }

    /**
     * Eager-load — kolom "store.name" di table() di bawah sebelumnya
     * N+1 per baris (audit framework 2026-09-14, "N+1 query & eager
     * loading").
     */
    public static function getEloquentQuery(): Builder
    {
        // withSum: kolom "Total" sebelumnya memanggil sum() per baris tabel (N+1).
        $query = parent::getEloquentQuery()->with(['store', 'creator'])->withSum('lines', 'debit');

        // spv_finance bertugas LINTAS toko dan perlu melihat jurnal company-wide
        // (store_id kosong) -- StoreScope bawaan (store_id = toko user) menyembunyikannya
        // (gap audit Jurnal Umum 2026-09-29). Akses tulisnya tetap dibatasi gate.
        if (auth()->user()?->hasRole('spv_finance') && ! (auth()->user()?->isFullAccess() ?? false)) {
            $query->withoutGlobalScope(\App\Models\Scopes\StoreScope::class);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entry_number')
                    ->label('No. Jurnal')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('entry_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Company-wide')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_debit')
                    ->label('Total')
                    ->state(fn (JournalEntry $record) => $record->totalDebit())
                    ->money('IDR', locale: 'id')
                    // Total debit dari hasil yang sedang difilter (footer).
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total Debit')
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (float) \Illuminate\Support\Facades\DB::table('journal_entry_lines')
                                ->whereIn('journal_entry_id', $query->cloneWithout(['columns', 'orders', 'limit', 'offset'])->select('journal_entries.id'))
                                ->sum('debit'))
                            ->money('IDR', locale: 'id')
                    ),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'draft',
                        'success' => 'posted',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Draft',
                        'posted' => 'Posted',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('source')
                    ->label('Sumber')
                    ->state(fn (JournalEntry $record) => static::sourceLabel($record->reference_type))
                    ->badge()
                    ->color(fn (string $state) => $state === 'Manual' ? 'primary' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_reversal')
                    ->label('Pembalik')
                    ->state(fn (JournalEntry $record) => $record->reference_type === 'reversal')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'posted' => 'Posted']),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id')),

                Tables\Filters\Filter::make('entry_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal')->native(false),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('entry_date', '<=', $d))),

                Tables\Filters\SelectFilter::make('sumber')
                    ->label('Sumber')
                    ->options(self::SOURCE_LABELS)
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            null, '' => $query,
                            'manual' => $query->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', 'manual')),
                            default => $query->where('reference_type', $value),
                        };
                    }),

                Tables\Filters\SelectFilter::make('akun')
                    ->label('Akun')
                    ->options(fn () => static::accountOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null)
                        ? $query->whereHas('lines', fn ($q) => $q->where('chart_of_account_id', $data['value']))
                        : $query),
            ])
            ->headerActions([
                // Ekspor Excel (1 baris per baris jurnal) -- gap audit 2026-09-29.
                Tables\Actions\Action::make('exportJournals')
                    ->label('Ekspor Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal')->native(false)->required()->default(now()->startOfMonth()),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal')->native(false)->required()->default(now()),
                        Forms\Components\Select::make('status')->label('Status')->options(['posted' => 'Posted', 'draft' => 'Draft'])->placeholder('Semua'),
                    ])
                    ->action(function (array $data) {
                        $entries = static::getEloquentQuery()
                            ->withoutEagerLoads()
                            ->with(['lines.account', 'store'])
                            ->whereDate('entry_date', '>=', $data['from'])
                            ->whereDate('entry_date', '<=', $data['until'])
                            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                            ->orderBy('entry_date')
                            ->orderBy('entry_number')
                            ->get();

                        return \Maatwebsite\Excel\Facades\Excel::download(
                            new \App\Exports\JournalEntryExport($entries),
                            'jurnal-umum-' . now()->format('Ymd') . '.xlsx'
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Lihat')
                    ->visible(fn (JournalEntry $record) => $record->status === 'posted'),

                Tables\Actions\EditAction::make()
                    ->visible(fn (JournalEntry $record) => $record->status !== 'posted'),

                Tables\Actions\Action::make('post')
                    ->label('Posting')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Setelah diposting, jurnal ini TERKUNCI dan tidak bisa diedit/dihapus lagi — koreksi hanya lewat jurnal pembalik.')
                    ->visible(fn (JournalEntry $record) => static::canPost($record))
                    ->action(function (JournalEntry $record) {
                        // Otorisasi diulang di dalam aksi (bukan cuma visible()):
                        // aksi Livewire bisa dipanggil manual.
                        if (! static::canPost($record)) {
                            Notification::make()->title('Tidak berwenang')->body('Anda tidak berwenang memposting jurnal ini (mis. jurnal buatan Anda sendiri).')->danger()->send();

                            return;
                        }

                        try {
                            app(JournalEntryService::class)->post($record, auth()->id());
                            Notification::make()->title('Jurnal diposting')->success()->send();
                            static::notifyDirection($record, 'diposting');
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal posting jurnal')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reverse')
                    ->label('Balik Jurnal')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->form(fn (JournalEntry $record) => [
                        Forms\Components\DatePicker::make('reversal_date')
                            ->label('Tanggal Jurnal Pembalik')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->minDate($record->entry_date)
                            ->maxDate(today())
                            ->helperText('Default hari ini. Tidak boleh sebelum tanggal jurnal asli atau di periode yang sudah ditutup.'),
                        Forms\Components\Textarea::make('note')
                            ->label('Alasan Pembalikan')
                            ->required()
                            ->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Membuat jurnal baru dengan debit/kredit dibalik dari jurnal ini — jurnal aslinya TETAP tersimpan (tidak dihapus), sesuai praktik akuntansi standar.')
                    ->visible(fn (JournalEntry $record) => static::canReverse($record))
                    ->action(function (JournalEntry $record, array $data) {
                        if (! static::canReverse($record)) {
                            Notification::make()->title('Tidak berwenang')->body('Jurnal ini tidak bisa dibalik dari sini (jurnal otomatis dibalik lewat modul asalnya, dan Anda tidak boleh membalik jurnal buatan sendiri).')->danger()->send();

                            return;
                        }

                        try {
                            $reversal = app(JournalEntryService::class)->reverse($record, auth()->id(), $data['note'] ?? null, $data['reversal_date'] ?? null);
                            Notification::make()->title("Jurnal pembalik {$reversal->entry_number} dibuat")->success()->send();
                            static::notifyDirection($record, 'dibalik');
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal membalik jurnal')->body($e->getMessage())->danger()->send();
                        }
                    }),

                // Jurnal OTOMATIS dari modul lain tidak bisa dibalik manual dari sini.
                Tables\Actions\Action::make('reverseViaSource')
                    ->label('Dibalik lewat modul asal')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->disabled()
                    ->tooltip('Jurnal ini dibuat otomatis oleh modul lain (booking, transaksi keuangan, penyusutan, dst.). Koreksinya lewat modul asalnya supaya tetap sinkron.')
                    ->visible(fn (JournalEntry $record) => $record->isPosted()
                        && ! in_array($record->reference_type, [null, 'manual', 'reversal'], true)
                        && ! $record->reversal()->exists()),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (JournalEntry $record) => $record->isDraft()),
            ])
            ->defaultSort('entry_date', 'desc');
    }

    /**
     * Direksi diberi tahu saat jurnal diposting/dibalik OLEH NON-full-access
     * (mis. spv_finance) -- pengawasan atas pembukuan manual (gap audit
     * Jurnal Umum 2026-09-29). Kegagalan kirim tidak boleh menggagalkan aksinya.
     */
    private static function notifyDirection(JournalEntry $record, string $action): void
    {
        $actor = auth()->user();
        if (! $actor || $actor->isFullAccess()) {
            return;
        }

        try {
            foreach (\App\Models\User::where('is_active', true)->get()->filter(fn ($u) => $u->isFullAccess()) as $user) {
                Notification::make()
                    ->title("Jurnal {$record->entry_number} {$action}")
                    ->body("Oleh {$actor->name} — {$record->description}")
                    ->warning()
                    ->sendToDatabase($user);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
            'create' => Pages\CreateJournalEntry::route('/create'),
            // HARUS setelah 'create': '/{record}' akan menelan '/create' kalau didaftarkan lebih dulu.
            'view' => Pages\ViewJournalEntry::route('/{record}'),
            'edit' => Pages\EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
