<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankStatementLineResource\Pages;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Services\BankReconciliationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
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

    protected static ?string $navigationLabel = 'Rekonsiliasi Bank';

    protected static ?string $modelLabel = 'Mutasi Bank';

    protected static ?string $pluralModelLabel = 'Rekonsiliasi Bank';

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
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
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'unmatched' => 'Belum Cocok',
                        'matched' => 'Cocok',
                        'ignored' => 'Diabaikan',
                        default => $state,
                    }),

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
            ])
            ->headerActions([
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

                Tables\Actions\Action::make('ignore')
                    ->label('Tandai Diabaikan')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (BankStatementLine $record) => $record->status === 'unmatched')
                    ->requiresConfirmation()
                    ->modalDescription('Dipakai untuk mutasi yang memang TIDAK PERLU dicocokkan ke jurnal (mis. biaya admin bank yang belum dicatat, atau saldo pembuka) — tidak menghapus barisnya, cuma menandai supaya tidak terus muncul di daftar "Belum Cocok".')
                    ->action(fn (BankStatementLine $record) => app(BankReconciliationService::class)->ignore($record)),

                Tables\Actions\DeleteAction::make(),
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

        Storage::disk('local')->delete($uploadedPath);

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
        $result = app(BankReconciliationService::class)->importRows($parsed, $account, auth()->id());

        $bodyLines = ["{$result['imported']} baris berhasil diimpor."];
        if ($result['duplicates'] > 0) $bodyLines[] = "{$result['duplicates']} baris dilewati (duplikat — sudah pernah diimpor).";
        if ($invalidCount > 0) $bodyLines[] = "{$invalidCount} baris dilewati (format tanggal/nominal tidak valid).";

        Notification::make()
            ->title('Import mutasi bank selesai')
            ->body(implode(' ', $bodyLines))
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankStatementLines::route('/'),
        ];
    }
}
