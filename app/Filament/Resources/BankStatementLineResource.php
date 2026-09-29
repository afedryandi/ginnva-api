<?php

namespace App\Filament\Resources;

use App\Exports\BankReconciliationExport;
use App\Filament\Resources\BankStatementLineResource\Pages;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Services\BankReconciliationService;
use App\Services\FinancialStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Rekonsiliasi Bank — TERBATAS full-access, sama filosofi dengan
 * ChartOfAccountResource/JournalEntryResource: alat pembuktian
 * pembukuan (bukan operasional harian toko).
 *
 * TIDAK PERNAH mengoreksi jurnal dari sini — kalau ketemu mutasi bank
 * yang tidak ada jurnalnya sama sekali (atau sebaliknya), itu ditandai
 * "Diabaikan" dulu lalu koreksinya dibuat MANUAL lewat Jurnal Umum
 * (jurnal pembalik/tambahan), supaya jejak audit tetap jelas siapa
 * yang membuat jurnal apa, bukan otomatis dari proses rekonsiliasi.
 */
class BankStatementLineResource extends Resource
{
    protected static ?string $model = BankStatementLine::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 200-299 "Jurnal & Akun" (lihat catatan
    // di ChartOfAccountResource soal penamaan).
    protected static ?string $navigationGroup = 'Jurnal & Akun';

    protected static ?string $navigationLabel = 'Rekonsiliasi Bank';

    protected static ?string $modelLabel = 'Mutasi Bank';

    protected static ?string $pluralModelLabel = 'Rekonsiliasi Bank';

    protected static ?int $navigationSort = 202;

    // Diperluas 2026-09-24 (keputusan user) — spv_finance boleh, tapi
    // tetap lewat hasMenuAccess() (harus dicentang eksplisit di "Akses
    // Menu"), delete tetap default FALSE via hasModuleAction().
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class));
    }

    // TETAP false utk semua — mutasi bank cuma diimpor (Excel) atau
    // ditandai status lewat aksi tabel, tidak pernah lewat form Edit
    // biasa (business rule, bukan soal hak akses).
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasRole('spv_finance') && $user->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    /**
     * Eager-load — kolom "account.display_name" &
     * "matchedLine.journalEntry.entry_number" di table() di bawah
     * sebelumnya N+1 per baris (audit framework 2026-09-14, "N+1 query
     * & eager loading").
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['account', 'matchedLine.journalEntry']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('statement_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('account.display_name')
                    ->label('Akun')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR', locale: 'id')
                    ->color(fn (BankStatementLine $record) => $record->amount >= 0 ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'unmatched',
                        'success' => 'matched',
                        'gray' => 'ignored',
                    ])
                    ->formatStateUsing(fn (BankStatementLine $record) => match (true) {
                        $record->status === 'matched' && $record->stale_at !== null => 'Cocok — Perlu Ditinjau Ulang',
                        $record->status === 'unmatched' => 'Belum Cocok',
                        $record->status === 'matched' => 'Cocok',
                        $record->status === 'ignored' => 'Diabaikan',
                        default => $record->status,
                    })
                    ->color(fn (BankStatementLine $record) => $record->status === 'matched' && $record->stale_at !== null ? 'danger' : match ($record->status) {
                        'unmatched' => 'warning', 'matched' => 'success', 'ignored' => 'gray', default => 'gray',
                    })
                    ->tooltip(fn (BankStatementLine $record) => $record->stale_at ? 'Jurnal yang dicocokkan ke mutasi ini sudah dibalik (' . $record->stale_at->format('d M Y H:i') . ') — cocokkan ulang atau tandai diabaikan.' : null),

                Tables\Columns\TextColumn::make('matchedLine.journalEntry.entry_number')
                    ->label('No. Jurnal')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('chart_of_account_id')
                    ->label('Akun')
                    ->options(fn () => ChartOfAccount::where('is_cash', true)
                        ->get()
                        ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name])),

                Tables\Filters\SelectFilter::make('status')
                    ->options(['unmatched' => 'Belum Cocok', 'matched' => 'Cocok', 'ignored' => 'Diabaikan'])
                    ->default('unmatched'),

                Tables\Filters\Filter::make('stale')
                    ->label('Perlu Ditinjau Ulang')
                    ->query(fn (Builder $query) => $query->whereNotNull('stale_at')),
            ])
            ->headerActions([
                // Ringkasan angka (audit 2026-09-29): jumlah & nilai mutasi yang belum cocok, biar tidak
                // perlu menghitung manual sebelum menutup periode (lihat AccountingPeriodService checklist 'bank').
                Tables\Actions\Action::make('summary')
                    ->label(function () {
                        $unmatched = BankStatementLine::where('status', 'unmatched')->selectRaw('COUNT(*) as n, COALESCE(SUM(ABS(amount)), 0) as total')->first();
                        $stale = BankStatementLine::whereNotNull('stale_at')->count();

                        $label = (int) $unmatched->n . ' belum cocok (Rp ' . number_format((float) $unmatched->total, 0, ',', '.') . ')';

                        return $stale > 0 ? $label . ' · ' . $stale . ' perlu ditinjau ulang' : $label;
                    })
                    ->icon('heroicon-o-information-circle')
                    ->color('gray')
                    ->disabled(),

                // Bukti rekonsiliasi (audit 2026-09-29): dokumen yang bisa dilampirkan saat tutup buku.
                Tables\Actions\Action::make('exportReconciliation')
                    ->label('Ekspor Bukti Rekonsiliasi')
                    ->icon('heroicon-o-document-check')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('chart_of_account_id')
                            ->label('Akun')
                            ->options(fn () => ChartOfAccount::where('is_cash', true)->get()->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->required(),
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal')->native(false)->required()->default(now()->startOfMonth()),
                        Forms\Components\DatePicker::make('to')->label('Sampai Tanggal')->native(false)->required()->default(now()),
                    ])
                    ->action(function (array $data) {
                        $result = static::buildReconciliationReport((int) $data['chart_of_account_id'], $data['from'], $data['to']);

                        return Excel::download(new BankReconciliationExport($result), 'rekonsiliasi-bank-' . now()->format('Ymd-His') . '.xlsx');
                    }),

                Tables\Actions\Action::make('exportReconciliationPdf')
                    ->label('Ekspor Bukti Rekonsiliasi (PDF)')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('chart_of_account_id')
                            ->label('Akun')
                            ->options(fn () => ChartOfAccount::where('is_cash', true)->get()->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->required(),
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal')->native(false)->required()->default(now()->startOfMonth()),
                        Forms\Components\DatePicker::make('to')->label('Sampai Tanggal')->native(false)->required()->default(now()),
                    ])
                    ->action(function (array $data) {
                        $result = static::buildReconciliationReport((int) $data['chart_of_account_id'], $data['from'], $data['to']);
                        $pdf = Pdf::loadView('pdf.bank_reconciliation_report', ['result' => $result])->setPaper('a4', 'portrait');

                        return response()->streamDownload(fn () => print($pdf->output()), 'rekonsiliasi-bank-' . now()->format('Ymd-His') . '.pdf');
                    }),

                // Riwayat impor (audit 2026-09-29): sebelumnya file yang diimpor langsung dihapus tanpa
                // arsip -- sekarang bisa ditelusuri "file apa yang diimpor tanggal X" untuk audit.
                Tables\Actions\Action::make('importHistory')
                    ->label('Riwayat Impor')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading('Riwayat Impor Mutasi Bank')
                    ->modalContent(fn () => view('filament.pages.bank-import-history', [
                        'batches' => \App\Models\BankStatementImportBatch::with(['account', 'creator'])->latest()->limit(30)->get(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\Action::make('import')
                    ->label('Import Mutasi')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\Select::make('chart_of_account_id')
                            ->label('Akun')
                            ->options(fn () => ChartOfAccount::where('is_cash', true)
                                ->get()
                                ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->required()
                            ->helperText('Akun kas/bank yang mutasinya ada di file ini.'),

                        Forms\Components\FileUpload::make('file')
                            ->label('File Mutasi (CSV/Excel)')
                            ->required()
                            ->disk('local')
                            ->directory('bank-statement-imports')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'text/csv',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->helperText('3 kolom: Tanggal, Keterangan, Nominal (positif = uang masuk, negatif = uang keluar). Baris pertama (header) otomatis dilewati.'),
                    ])
                    ->action(function (array $data) {
                        static::importFile($data['chart_of_account_id'], $data['file']);
                    })
                    ->modalSubmitActionLabel('Import'),

                Tables\Actions\Action::make('auto_match')
                    ->label('Cocokkan Otomatis')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('chart_of_account_id')
                            ->label('Akun')
                            ->options(fn () => ChartOfAccount::where('is_cash', true)
                                ->get()
                                ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $account = ChartOfAccount::find($data['chart_of_account_id']);
                        $matched = app(BankReconciliationService::class)->autoMatch($account);

                        Notification::make()
                            ->title("{$matched} mutasi berhasil dicocokkan otomatis")
                            ->body($matched === 0 ? 'Tidak ada pasangan yang jelas & tidak ambigu — cocokkan sisanya manual.' : null)
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('match')
                    ->label('Cocokkan')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->visible(fn (BankStatementLine $record) => $record->status === 'unmatched')
                    ->form(fn (BankStatementLine $record) => [
                        Forms\Components\Select::make('journal_entry_line_id')
                            ->label('Baris Jurnal')
                            ->options(fn () => JournalEntryLine::query()
                                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                                ->where('journal_entry_lines.chart_of_account_id', $record->chart_of_account_id)
                                ->where('journal_entries.status', 'posted')
                                ->whereNotIn('journal_entry_lines.id', BankStatementLine::whereNotNull('matched_journal_entry_line_id')->pluck('matched_journal_entry_line_id'))
                                ->orderByDesc('journal_entries.entry_date')
                                ->limit(200)
                                ->get([
                                    'journal_entry_lines.id',
                                    'journal_entry_lines.debit',
                                    'journal_entry_lines.credit',
                                    'journal_entries.entry_number',
                                    'journal_entries.entry_date',
                                    'journal_entries.description',
                                ])
                                ->mapWithKeys(fn ($l) => [$l->id => $l->entry_date->format('d M Y') . " — {$l->entry_number} — {$l->description} — Rp " . number_format((float) ($l->debit ?: $l->credit), 0, ',', '.')]))
                            ->searchable()
                            ->required()
                            ->helperText('Cuma baris yang belum dicocokkan mutasi bank lain yang muncul di sini.'),
                    ])
                    ->action(function (BankStatementLine $record, array $data) {
                        try {
                            app(BankReconciliationService::class)->match($record, JournalEntryLine::findOrFail($data['journal_entry_line_id']));
                            Notification::make()->title('Dicocokkan')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal mencocokkan')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('unmatch')
                    ->label('Batalkan Kecocokan')
                    ->icon('heroicon-o-link-slash')
                    ->color('warning')
                    ->visible(fn (BankStatementLine $record) => $record->status === 'matched')
                    ->requiresConfirmation()
                    ->action(fn (BankStatementLine $record) => app(BankReconciliationService::class)->unmatch($record)),

                // Sebelumnya "Diabaikan" tidak punya jalan keluar selain hapus permanen (audit 2026-09-29).
                Tables\Actions\Action::make('unignore')
                    ->label('Batalkan Pengabaian')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (BankStatementLine $record) => $record->status === 'ignored')
                    ->action(fn (BankStatementLine $record) => app(BankReconciliationService::class)->unignore($record)),

                Tables\Actions\Action::make('ignore')
                    ->label('Tandai Diabaikan')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (BankStatementLine $record) => $record->status === 'unmatched')
                    ->requiresConfirmation()
                    ->modalDescription('Dipakai untuk mutasi yang memang TIDAK PERLU dicocokkan ke jurnal (mis. biaya admin bank yang belum dicatat, atau saldo pembuka) — tidak menghapus barisnya, cuma menandai supaya tidak terus muncul di daftar "Belum Cocok".')
                    ->action(fn (BankStatementLine $record) => app(BankReconciliationService::class)->ignore($record)),

                // Baris yang masih 'matched' tidak boleh dihapus langsung -- batalkan kecocokannya
                // dulu, supaya jejak "jurnal X pernah dicocokkan ke mutasi mana" tidak hilang diam-diam.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (BankStatementLine $record) => $record->status !== 'matched'),
            ])
            ->defaultSort('statement_date', 'desc');
    }

    /**
     * Baris pertama (header) dilewati — 3 kolom: Tanggal, Keterangan,
     * Nominal. Sama pola dengan AssetResource::importAssets() (Excel::
     * toArray() bisa baca xlsx MAUPUN csv seragam).
     */
    private static function importFile(int $accountId, string $uploadedPath): void
    {
        $fullPath = Storage::disk('local')->path($uploadedPath);

        try {
            $sheets = Excel::toArray(null, $fullPath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($uploadedPath);

            Notification::make()
                ->title('Gagal membaca file')
                ->body('File tidak bisa dibaca sebagai Excel/CSV yang valid: ' . $e->getMessage())
                ->danger()
                ->send();

            return;
        }

        // File asal DIARSIPKAN (sebelumnya dihapus langsung setelah diproses) -- dipindah ke direktori
        // permanen supaya bisa ditelusuri "file apa yang diimpor tanggal X" saat audit (2026-09-29).
        $archivedPath = 'bank-statement-archive/' . basename($uploadedPath);
        Storage::disk('local')->move($uploadedPath, $archivedPath);

        $rows = $sheets[0] ?? [];
        array_shift($rows);

        $parsed = [];
        $invalidCount = 0;

        foreach ($rows as $row) {
            $date = $row[0] ?? null;
            $description = trim((string) ($row[1] ?? ''));
            $amount = $row[2] ?? null;

            if (! $date || $description === '' || ! is_numeric($amount)) {
                if ($date || $description !== '' || $amount !== null) {
                    $invalidCount++;
                }
                continue;
            }

            try {
                $parsedDate = \Illuminate\Support\Carbon::parse($date)->toDateString();
            } catch (\Throwable) {
                $invalidCount++;
                continue;
            }

            $parsed[] = ['date' => $parsedDate, 'description' => $description, 'amount' => (float) $amount];
        }

        $account = ChartOfAccount::findOrFail($accountId);
        $result = app(BankReconciliationService::class)->importRows($parsed, $account, auth()->id(), [
            'original_filename' => basename($uploadedPath),
            'archived_path' => $archivedPath,
            'invalid_count' => $invalidCount,
        ]);

        $bodyLines = ["{$result['imported']} baris berhasil diimpor."];
        if ($result['duplicates'] > 0) $bodyLines[] = "{$result['duplicates']} baris dilewati (duplikat — sudah pernah diimpor).";
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (format tanggal/nominal tidak valid).";

        Notification::make()
            ->title('Import mutasi bank selesai')
            ->body(implode(' ', $bodyLines))
            ->success()
            ->send();
    }

    /**
     * @return array{lines: array, as_of: \Illuminate\Support\Carbon, system_balance: float, matched_count: int, unmatched_count: int, unmatched_total: float, stale_count: int, account: ChartOfAccount}
     */
    private static function buildReconciliationReport(int $accountId, string $from, string $to): array
    {
        $account = ChartOfAccount::findOrFail($accountId);
        $to = \Illuminate\Support\Carbon::parse($to);

        $lines = BankStatementLine::where('chart_of_account_id', $accountId)
            ->whereBetween('statement_date', [$from, $to->toDateString()])
            ->with('matchedLine.journalEntry')
            ->orderBy('statement_date')
            ->get();

        $statusLabels = ['unmatched' => 'Belum Cocok', 'matched' => 'Cocok', 'ignored' => 'Diabaikan'];

        $rows = $lines->map(fn (BankStatementLine $l) => [
            'date' => $l->statement_date,
            'description' => $l->description,
            'amount' => (float) $l->amount,
            'status_label' => $statusLabels[$l->status] ?? $l->status,
            'journal_entry_number' => $l->matchedLine?->journalEntry?->entry_number,
        ]);

        return [
            'account' => $account,
            'from' => \Illuminate\Support\Carbon::parse($from),
            'to' => $to,
            'as_of' => $to,
            'lines' => $rows,
            'system_balance' => app(FinancialStatementService::class)->balanceAsOf($account, $to),
            'matched_count' => $lines->where('status', 'matched')->count(),
            'unmatched_count' => $lines->where('status', 'unmatched')->count(),
            'unmatched_total' => (float) $lines->where('status', 'unmatched')->sum(fn ($l) => abs((float) $l->amount)),
            'stale_count' => $lines->whereNotNull('stale_at')->count(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankStatementLines::route('/'),
        ];
    }
}
