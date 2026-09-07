<?php

namespace App\Services;

use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Rekonsiliasi Bank — impor mutasi bank/kas dari file (CSV/Excel),
 * lalu cocokkan (otomatis untuk yang jelas, manual untuk sisanya) ke
 * baris journal_entry_lines yang sudah tercatat di 1 akun kas/bank
 * tertentu. Tujuannya membuktikan pembukuan sistem SAMA dengan
 * rekening/kas fisik — bukan mengoreksi jurnal (itu tetap lewat
 * JournalEntryService/jurnal pembalik kalau ketemu selisih).
 */
class BankReconciliationService
{
    /**
     * @param array<int, array{date: string, description: string, amount: float, reference?: ?string}> $rows
     * @return array{imported: int, duplicates: int}
     */
    public function importRows(array $rows, ChartOfAccount $account, ?int $userId): array
    {
        $batch = 'IMPORT-' . now()->format('YmdHis');
        $imported = 0;
        $duplicates = 0;

        foreach ($rows as $row) {
            $date = Carbon::parse($row['date'])->toDateString();
            $amount = round((float) $row['amount'], 2);

            // Duplikat = baris dengan akun+tanggal+nominal+keterangan
            // PERSIS sama sudah pernah diimpor sebelumnya — mencegah
            // file yang sama tidak sengaja diimpor 2x membuat baris
            // dobel. Kalau memang ada 2 transaksi asli yang identik di
            // hari yang sama (jarang tapi mungkin), keduanya akan
            // dianggap 1 duplikat — batasan yang disadari, bukan bug.
            $isDuplicate = BankStatementLine::where('chart_of_account_id', $account->id)
                ->whereDate('statement_date', $date)
                ->where('amount', $amount)
                ->where('description', $row['description'])
                ->exists();

            if ($isDuplicate) {
                $duplicates++;
                continue;
            }

            BankStatementLine::create([
                'chart_of_account_id' => $account->id,
                'statement_date' => $date,
                'description' => $row['description'],
                'amount' => $amount,
                'external_reference' => $row['reference'] ?? null,
                'status' => 'unmatched',
                'import_batch' => $batch,
                'created_by' => $userId,
            ]);
            $imported++;
        }

        return ['imported' => $imported, 'duplicates' => $duplicates];
    }

    /**
     * Cocokkan otomatis HANYA kalau kandidatnya TUNGGAL & TIDAK
     * AMBIGU (1 baris mutasi bank ↔ 1 baris jurnal, nominal & tanggal
     * PERSIS sama, sama-sama belum dicocokkan) — kalau ada lebih dari
     * 1 kandidat yang cocok, dilewati (dibiarkan manual) daripada
     * menebak salah satu secara diam-diam.
     *
     * @return int jumlah baris yang berhasil dicocokkan otomatis.
     */
    public function autoMatch(ChartOfAccount $account): int
    {
        $unmatchedLines = BankStatementLine::where('chart_of_account_id', $account->id)
            ->where('status', 'unmatched')
            ->get();

        $alreadyMatchedJournalLineIds = BankStatementLine::whereNotNull('matched_journal_entry_line_id')
            ->pluck('matched_journal_entry_line_id');

        $matched = 0;

        foreach ($unmatchedLines as $line) {
            // Baris jurnal di akun ini yang efeknya (debit untuk mutasi
            // positif/uang masuk, kredit untuk negatif/uang keluar) &
            // tanggal jurnalnya PERSIS sama dengan baris mutasi bank ini.
            $column = $line->amount >= 0 ? 'debit' : 'credit';
            $amount = abs((float) $line->amount);

            $candidates = JournalEntryLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entry_lines.chart_of_account_id', $account->id)
                ->where('journal_entries.status', 'posted')
                ->where("journal_entry_lines.{$column}", $amount)
                ->whereDate('journal_entries.entry_date', $line->statement_date->toDateString())
                ->whereNotIn('journal_entry_lines.id', $alreadyMatchedJournalLineIds)
                ->pluck('journal_entry_lines.id');

            if ($candidates->count() !== 1) {
                continue;
            }

            $journalLineId = $candidates->first();

            $line->update([
                'matched_journal_entry_line_id' => $journalLineId,
                'status' => 'matched',
            ]);

            $alreadyMatchedJournalLineIds->push($journalLineId);
            $matched++;
        }

        return $matched;
    }

    public function match(BankStatementLine $line, JournalEntryLine $journalLine): void
    {
        if ($journalLine->chart_of_account_id !== $line->chart_of_account_id) {
            throw new RuntimeException('Baris jurnal yang dipilih bukan dari akun yang sama dengan mutasi bank ini.');
        }

        $line->update([
            'matched_journal_entry_line_id' => $journalLine->id,
            'status' => 'matched',
        ]);
    }

    public function unmatch(BankStatementLine $line): void
    {
        $line->update([
            'matched_journal_entry_line_id' => null,
            'status' => 'unmatched',
        ]);
    }

    public function ignore(BankStatementLine $line): void
    {
        $line->update(['status' => 'ignored']);
    }
}
