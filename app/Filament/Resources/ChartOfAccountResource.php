<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChartOfAccountResource\Pages;
use App\Models\ChartOfAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Bagan Akun — TERBATAS full-access (super_admin/direksi), TIDAK lewat
 * menu_access seperti resource lain di grup Keuangan. Sama filosofi
 * dengan PayrollResource: struktur akun adalah keputusan akuntansi yang
 * mempengaruhi laporan seluruh perusahaan, bukan operasional harian
 * yang cocok didelegasikan ke store_manager (beda dari Transaksi
 * Keuangan yang memang staff toko input sendiri tiap hari).
 *
 * Akun dipakai oleh Jurnal Umum & seluruh posting otomatis — struktur akun
 * yang sudah dipakai dikunci (lihat guard di ChartOfAccount::booted()).
 */
class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartOfAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Bagan Akun';

    protected static ?string $modelLabel = 'Akun';

    protected static ?string $pluralModelLabel = 'Bagan Akun';

    protected static ?int $navigationSort = 0;

    private const TYPE_OPTIONS = [
        'aset' => 'Aset',
        'kewajiban' => 'Kewajiban',
        'modal' => 'Modal',
        'pendapatan' => 'Pendapatan',
        'beban_pokok' => 'Beban Pokok Penjualan (HPP)',
        'beban_operasional' => 'Beban Operasional',
        'pendapatan_lain' => 'Pendapatan Lain-lain',
        'beban_lain' => 'Beban Lain-lain',
        'pajak' => 'Pajak',
    ];

    private const TYPE_COLORS = [
        'aset' => 'info',
        'kewajiban' => 'warning',
        'modal' => 'primary',
        'pendapatan' => 'success',
        'beban_pokok' => 'danger',
        'beban_operasional' => 'danger',
        'pendapatan_lain' => 'gray',
        'beban_lain' => 'gray',
        'pajak' => 'gray',
    ];

    // Diperluas 2026-09-24 (keputusan user) — spv_finance boleh, tapi
    // tetap lewat hasMenuAccess() (harus dicentang eksplisit di "Akses
    // Menu"), dan create/edit tetap default FALSE via hasModuleAction()
    // supaya view saja tidak otomatis termasuk hak ubah struktur akun.
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

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /**
     * Diblokir kalau akun sistem (dipakai posting otomatis) atau masih
     * dipakai jurnal/mutasi bank/kategori/aset/template/widget/akun anak
     * (audit Bagan Akun 2026-09-28) — FK cascade/nullOnDelete di DB tidak
     * MENOLAK, cuma diam-diam menghapus/melepas data turunannya.
     */
    public static function canDelete($record): bool
    {
        return static::canDeleteAny() && $record->deletionBlocker() === null;
    }

    public static function canDeleteAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    /**
     * Saldo & jumlah jurnal per akun dihitung SEKALI lewat subquery (bukan
     * per baris -- tidak N+1). Ditambahkan 2026-09-28 (gap audit Bagan Akun).
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Hanya jurnal 'posted' (draft belum masuk laporan).
        $lines = fn (string $expr) => \Illuminate\Support\Facades\DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->selectRaw($expr)
            ->whereColumn('journal_entry_lines.chart_of_account_id', 'chart_of_accounts.id');

        return parent::getEloquentQuery()
            ->select('chart_of_accounts.*')
            ->addSelect([
                'journal_lines_count' => $lines('COUNT(*)'),
                'debit_total' => $lines('COALESCE(SUM(journal_entry_lines.debit), 0)'),
                'credit_total' => $lines('COALESCE(SUM(journal_entry_lines.credit), 0)'),
            ]);
    }

    /** Kedalaman hierarki (0 = tingkat atas), peta id=>parent dimuat sekali per request. */
    private static function depthOf(ChartOfAccount $account): int
    {
        static $parents = null;
        $parents ??= ChartOfAccount::pluck('parent_id', 'id')->all();

        $depth = 0;
        $cursor = $account->parent_id;
        while ($cursor && $depth < 10) {
            $depth++;
            $cursor = $parents[$cursor] ?? null;
        }

        return $depth;
    }

    private static function structureLocked(?ChartOfAccount $record): bool
    {
        return $record !== null && ($record->isSystem() || $record->hasJournal());
    }

    /** @return array<int> id semua turunan (anak, cucu, ...) akun ini. */
    private static function descendantIds(ChartOfAccount $account): array
    {
        $ids = [];
        $frontier = [$account->id];
        while ($frontier) {
            $frontier = ChartOfAccount::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Akun')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Kode Akun')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(10)
                        ->regex('/^[0-9]{4,6}$/')
                        ->validationMessages(['regex' => 'Kode akun harus 4-6 digit angka.'])
                        ->rules([
                            fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                $allowed = ChartOfAccount::CODE_PREFIX_TYPES[substr((string) $value, 0, 1)] ?? null;
                                if ($allowed === null || ($get('type') && ! in_array($get('type'), $allowed, true))) {
                                    $fail('Digit pertama kode harus sesuai klasifikasi (1 Aset, 2 Kewajiban, 3 Modal, 4 Pendapatan, 5 HPP, 6 Beban Operasional, 7 Lain-lain, 8 Pajak).');
                                }
                            },
                        ])
                        ->placeholder('Mis. 6480')
                        // Kode SEKALI ditentukan tidak diubah lagi — begitu
                        // akun ini mulai dipakai jurnal (Fase berikutnya),
                        // riwayat laporan lama akan merujuk kode ini. Boleh
                        // diisi bebas cuma saat pertama kali dibuat.
                        ->disabled(fn (?ChartOfAccount $record) => $record !== null)
                        ->dehydrated(),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama Akun')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('type')
                        ->label('Klasifikasi')
                        ->options(self::TYPE_OPTIONS)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('parent_id', null))
                        // Terkunci untuk akun sistem / yang sudah punya jurnal —
                        // mengubah klasifikasi membalik saldo normal dan
                        // memindahkan seluruh jurnal lama antar kelompok laporan.
                        ->disabled(fn (?ChartOfAccount $record) => static::structureLocked($record))
                        ->dehydrated()
                        ->helperText(fn (?ChartOfAccount $record) => static::structureLocked($record)
                            ? 'Terkunci: akun ini dipakai sistem/jurnal, klasifikasinya tidak boleh diubah.'
                            : 'Menentukan saldo normal (debit/kredit) akun ini secara otomatis.'),

                    Forms\Components\Select::make('parent_id')
                        ->label('Akun Induk (opsional)')
                        ->options(fn (Forms\Get $get, ?ChartOfAccount $record) => ChartOfAccount::where('is_postable', false)
                            ->when($get('type'), fn ($q, $type) => $q->where('type', $type))
                            ->when($record, fn ($q) => $q->where('id', '!=', $record->id)->whereNotIn('id', static::descendantIds($record)))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                        ->searchable()
                        ->disabled(fn (?ChartOfAccount $record) => static::structureLocked($record))
                        ->dehydrated()
                        ->placeholder('— Tidak ada (akun tingkat atas) —')
                        ->helperText('Cuma bisa memilih akun header (yang "Bisa Diposting" dimatikan) dengan klasifikasi yang sama.'),

                    Forms\Components\Toggle::make('is_postable')
                        ->label('Bisa Diposting Transaksi')
                        ->default(true)
                        ->disabled(fn (?ChartOfAccount $record) => static::structureLocked($record))
                        ->dehydrated()
                        ->helperText('Matikan untuk akun header/pengelompok (mis. "Aset Lancar") yang cuma membungkus akun detail di bawahnya — tidak boleh menerima transaksi langsung.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->disabled(fn (?ChartOfAccount $record) => $record?->isSystem() ?? false)
                        ->dehydrated()
                        ->helperText(fn (?ChartOfAccount $record) => ($record?->isSystem() ?? false)
                            ? 'Terkunci: akun ini dipakai posting otomatis sistem, tidak boleh dinonaktifkan.'
                            : 'Akun nonaktif tidak muncul lagi sebagai pilihan baru, riwayat lama tetap tersimpan.'),

                    // Sebelumnya tidak ada di form — akun kas/bank baru dari UI
                    // tidak bisa ditandai kas, jadi tidak masuk Laporan Arus Kas.
                    Forms\Components\Toggle::make('is_cash')
                        ->label('Akun Kas / Bank')
                        ->default(false)
                        ->helperText('Aktifkan untuk akun kas & setara kas (kas, rekening bank). Jurnal yang menyentuh akun ini dihitung sebagai arus kas.'),

                    Forms\Components\Toggle::make('is_contra')
                        ->label('Akun Kontra')
                        ->default(false)
                        ->helperText('Penanda untuk akun pengurang (mis. Akumulasi Penyusutan, Retur Penjualan) yang saldonya berlawanan dengan kelompoknya. Hanya label -- tidak mengubah perhitungan laporan.'),

                    Forms\Components\Select::make('cash_flow_category')
                        ->label('Kategori Arus Kas')
                        ->options(['operasional' => 'Operasional', 'investasi' => 'Investasi', 'pendanaan' => 'Pendanaan'])
                        ->placeholder('— Tidak diklasifikasi —')
                        ->helperText('Untuk akun NON-kas: masuk kelompok mana di Laporan Arus Kas. Kosong dianggap Operasional.'),

                    // Info pemakaian sebelum admin menghapus/menonaktifkan
                    // (gap audit Bagan Akun 2026-09-28).
                    Forms\Components\Placeholder::make('usage_info')
                        ->label('Pemakaian Akun')
                        ->visible(fn (?ChartOfAccount $record) => $record !== null)
                        ->content(function (?ChartOfAccount $record) {
                            if (! $record) {
                                return '';
                            }
                            $usage = $record->usageSummary();
                            $parts = $usage
                                ? collect($usage)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ')
                                : 'belum dipakai';

                            return ($record->isSystem() ? 'Akun sistem (dipakai posting otomatis). ' : '')
                                . 'Dipakai oleh: ' . $parts . '. Saldo: Rp' . number_format($record->balance(), 0, ',', '.') . '.';
                        })
                        ->columnSpanFull(),

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
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Akun')
                    ->searchable()
                    // Indentasi visual untuk akun anak — supaya hierarki
                    // kelihatan sekilas tanpa perlu tree-grid terpisah,
                    // konsisten dengan urutan defaultSort('code') yang
                    // secara alami mengelompokkan parent-child.
                    ->formatStateUsing(fn (ChartOfAccount $record, string $state) => str_repeat('— ', static::depthOf($record)) . $state)
                    ->weight(fn (ChartOfAccount $record) => $record->is_postable ? 'normal' : 'bold'),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Klasifikasi')
                    ->colors(self::TYPE_COLORS)
                    ->formatStateUsing(fn (string $state) => self::TYPE_OPTIONS[$state] ?? $state),

                Tables\Columns\TextColumn::make('normal_balance')
                    ->label('Saldo Normal')
                    ->badge()
                    ->color(fn (string $state) => $state === 'debit' ? 'info' : 'warning')
                    ->formatStateUsing(fn (string $state, ChartOfAccount $record) => ucfirst($state) . ($record->is_contra ? ' · kontra' : '')),

                Tables\Columns\TextColumn::make('saldo')
                    ->label('Saldo')
                    ->state(fn (ChartOfAccount $record) => $record->normal_balance === 'debit'
                        ? (float) $record->debit_total - (float) $record->credit_total
                        : (float) $record->credit_total - (float) $record->debit_total)
                    ->money('IDR', locale: 'id')
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('journal_lines_count')
                    ->label('Baris Jurnal')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_system')
                    ->label('Sistem')
                    ->state(fn (ChartOfAccount $record) => $record->isSystem())
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->trueColor('warning')
                    ->tooltip(fn (ChartOfAccount $record) => $record->isSystem() ? 'Dipakai posting otomatis: tidak bisa dihapus/dinonaktifkan' : null)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_postable')
                    ->label('Postable')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('children_count')
                    ->label('Akun Anak')
                    ->counts('children')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportAccounts')
                    ->label('Ekspor Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => \Maatwebsite\Excel\Facades\Excel::download(
                        new \App\Exports\ChartOfAccountExport(),
                        'bagan-akun-' . now()->format('Ymd') . '.xlsx'
                    )),

                Tables\Actions\Action::make('importAccounts')
                    ->label('Impor Akun')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn () => static::canCreate())
                    ->modalDescription('Hanya MEMBUAT akun baru: kode yang sudah ada dilewati (tidak ditimpa). Kalau ada satu baris error, tidak ada akun yang dibuat. Pakai file hasil "Ekspor Excel" sebagai template (kolom saldo diabaikan).')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('File Excel/CSV')
                            ->disk('local')
                            ->directory('coa-imports')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/csv',
                                'text/plain',
                                'application/csv',
                            ])
                            ->maxSize(2048)
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $path = $data['file'];

                        try {
                            $rows = \Maatwebsite\Excel\Facades\Excel::toArray(
                                new class implements \Maatwebsite\Excel\Concerns\WithHeadingRow {},
                                $path,
                                'local'
                            )[0] ?? [];
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
                            Notification::make()->title('File tidak bisa dibaca')->body('Pastikan formatnya .xlsx atau .csv dengan baris header.')->danger()->send();

                            return;
                        }

                        \Illuminate\Support\Facades\Storage::disk('local')->delete($path);

                        $result = app(\App\Services\ChartOfAccountImportService::class)->import($rows);

                        if ($result['errors']) {
                            Notification::make()
                                ->title('Impor dibatalkan (' . count($result['errors']) . ' error)')
                                ->body(implode("\n", array_slice($result['errors'], 0, 10)) . (count($result['errors']) > 10 ? "\n... dan " . (count($result['errors']) - 10) . ' lainnya' : ''))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title("{$result['created']} akun dibuat, {$result['skipped']} dilewati (kode sudah ada)")
                            ->success()
                            ->send();
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Klasifikasi')
                    ->options(self::TYPE_OPTIONS),

                Tables\Filters\TernaryFilter::make('is_postable')
                    ->label('Bisa Diposting'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (ChartOfAccount $record) => static::canDelete($record))
                    ->action(function (ChartOfAccount $record) {
                        try {
                            $record->delete();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Tidak bisa menghapus akun ini')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Akun dihapus')->success()->send();
                    }),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChartOfAccounts::route('/'),
            'create' => Pages\CreateChartOfAccount::route('/create'),
            'edit' => Pages\EditChartOfAccount::route('/{record}/edit'),
        ];
    }
}
