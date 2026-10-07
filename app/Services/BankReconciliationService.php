<?php

namespace App\Services;

use App\Models\BankStatementImportBatch;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rekonsiliasi Bank — impor mutasi bank/kas dari file (CSV/Excel),
 * lalu cocokkan (otomatis untuk yang jelas, manual untuk sisanya) ke
 * baris journal_entry_lines yang sudah tercatat di 1 akun kas/bank
 * tertentu. Tujuannya membuktikan pembukuan sistem SAMA dengan
 * rekening/kas fisik — bukan mengoreksi jurnal (itu tetap lewat
 * JournalEntryService/jurnal pembalik kalau ketemu selisih).
 *
 * Audit Rekonsiliasi Bank 2026-09-29: match()/autoMatch() dikunci di dalam DB::transaction()
 * (mencegah 1 baris jurnal dicocokkan ke >1 mutasi bank secara bersamaan), plus unique constraint
 * DB sebagai lapisan kedua. invalidateForReversal() menandai mutasi yang jurnalnya dibalik.
 */
class BankReconciliationService
{
    /** Normalisasi keterangan untuk pembanding duplikat: spasi berlebih & kapitalisasi diabaikan. */
    private function normalizeDescription(string $description): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $description)));
    }

    /**
     * @param array<int, array{date: string, description: string, amount: float, reference?: ?string}> $rows
     * @param array{original_filename?: ?string, archived_path?: ?string} $fileInfo
     * @return array{imported: int, duplicates: int, batch: string}
     */
    public function importRows(array $rows, ChartOfAccount $account, ?int $userId, array $fileInfo = []): array
    {
        // Form hanya menawarkan akun kas/bank, tapi nilai kiriman langsung tidak dibatasi.
        if (! $account->is_cash) {
            throw new RuntimeException('Mutasi bank hanya bisa diimpor ke akun kas/bank.');
        }

        // Akhiran acak: dua impor dalam detik yang sama sebelumnya bentrok di kolom unik batch.
        $batch = 'IMPORT-' . now()->format('YmdHis') . '-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4));
        $imported = 0;
        $duplicates = 0;

        // Duplikat dicek terhadap SEMUA baris yang sudah ada di akun ini (bukan cuma batch berjalan),
        // keterangan dinormalisasi (spasi/kapitalisasi) supaya ekspor bank yang formatnya sedikit
        // berubah antar unduhan tetap terdeteksi sebagai duplikat (audit 2026-09-29).
        $existing = BankStatementLine::where('chart_of_account_id', $account->id)
            ->get(['statement_date', 'amount', 'description'])
            ->map(fn ($l) => $l->statement_date->toDateString() . '|' . number_format((float) $l->amount, 2, '.', '') . '|' . $this->normalizeDescription($l->description))
            ->flip();

        foreach ($rows as $row) {
            $date = Carbon::parse($row['date'])->toDateString();
            $amount = round((float) $row['amount'], 2);
            $key = $date . '|' . number_format($amount, 2, '.', '') . '|' . $this->normalizeDescription($row['description']);

            if ($existing->has($key)) {
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
            $existing[$key] = true;
            $imported++;
        }

        // Arsipkan file asal (sebelumnya dihapus langsung) supaya bisa ditelusuri saat audit.
        BankStatementImportBatch::create([
            'batch' => $batch,
            'chart_of_account_id' => $account->id,
            'original_filename' => $fileInfo['original_filename'] ?? null,
            'archived_path' => $fileInfo['archived_path'] ?? null,
            'imported_count' => $imported,
            'duplicate_count' => $duplicates,
            'invalid_count' => $fileInfo['invalid_count'] ?? 0,
            'created_by' => $userId,
        ]);

        return ['imported' => $imported, 'duplicates' => $duplicates, 'batch' => $batch];
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
        $unmatchedLineIds = BankStatementLine::where('chart_of_account_id', $account->id)
            ->where('status', 'unmatched')
            ->pluck('id');

        $matched = 0;

        // 1 baris = 1 transaksi terkunci (bukan lock 1 kali di awal untuk semua baris), supaya baris
        // lain di modul lain tidak ikut menunggu selama proses auto-match akun ini berjalan.
        foreach ($unmatchedLineIds as $lineId) {
            if ($this->matchOneAutomatically($lineId)) {
                $matched++;
            }
        }

        return $matched;
    }

    private function matchOneAutomatically(int $lineId): bool
    {
        return DB::transaction(function () use ($lineId) {
            $line = BankStatementLine::whereKey($lineId)->lockForUpdate()->first();

            if (! $line || $line->status !== 'unmatched') {
                return false; // sudah diproses baris/proses lain sejak dipilih
            }

            // Baris jurnal di akun ini yang efeknya (debit untuk mutasi
            // positif/uang masuk, kredit untuk negatif/uang keluar) &
            // tanggal jurnalnya PERSIS sama dengan baris mutasi bank ini.
            $column = $line->amount >= 0 ? 'debit' : 'credit';
            $amount = abs((float) $line->amount);

            $candidates = JournalEntryLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entry_lines.chart_of_account_id', $line->chart_of_account_id)
                ->where('journal_entries.status', 'posted')
                ->where("journal_entry_lines.{$column}", $amount)
                ->whereDate('journal_entries.entry_date', $line->statement_date->toDateString())
                ->whereNotIn('journal_entry_lines.id', BankStatementLine::whereNotNull('matched_journal_entry_line_id')->pluck('matched_journal_entry_line_id'))
                ->pluck('journal_entry_lines.id');

            if ($candidates->count() !== 1) {
                return false;
            }

            try {
                $line->update([
                    'matched_journal_entry_line_id' => $candidates->first(),
                    'status' => 'matched',
                    'stale_at' => null,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Baris jurnal ini baru saja dicocokkan proses lain di antara query candidates & update ini.
                return false;
            }

            return true;
        });
    }

    /**
     * @throws RuntimeException kalau akun baris jurnal berbeda dari akun mutasi bank, atau baris
     *         jurnal itu sudah dicocokkan ke mutasi bank lain (termasuk yang baru saja terjadi
     *         bersamaan — dicegah lockForUpdate() + unique constraint).
     */
    public function match(BankStatementLine $line, JournalEntryLine $journalLine): void
    {
        DB::transaction(function () use ($line, $journalLine) {
            $locked = BankStatementLine::whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'unmatched') {
                throw new RuntimeException('Mutasi ini sudah diproses (mungkin oleh pengguna lain) — muat ulang halaman.');
            }

            if ($journalLine->chart_of_account_id !== $locked->chart_of_account_id) {
                throw new RuntimeException('Baris jurnal yang dipilih bukan dari akun yang sama dengan mutasi bank ini.');
            }

            // Dropdown form hanya menampilkan jurnal posted, tapi nilai kiriman langsung tidak dibatasi:
            // draft belum masuk pembukuan, dan arah debit/kredit harus searah dengan mutasi bank
            // (uang masuk = debit kas, uang keluar = kredit kas).
            $journal = $journalLine->journalEntry()->first();
            if (! $journal || $journal->status !== 'posted') {
                throw new RuntimeException('Hanya baris dari jurnal yang sudah diposting yang bisa dicocokkan.');
            }

            $sameDirection = $locked->amount >= 0 ? (float) $journalLine->debit > 0 : (float) $journalLine->credit > 0;
            if (! $sameDirection) {
                throw new RuntimeException('Arah baris jurnal berlawanan dengan mutasi bank (uang masuk = debit, uang keluar = kredit).');
            }

            $alreadyMatched = BankStatementLine::where('matched_journal_entry_line_id', $journalLine->id)
                ->lockForUpdate()
                ->exists();

            if ($alreadyMatched) {
                throw new RuntimeException('Baris jurnal ini sudah dicocokkan ke mutasi bank lain.');
            }

            try {
                $locked->update([
                    'matched_journal_entry_line_id' => $journalLine->id,
                    'status' => 'matched',
                    'stale_at' => null,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                throw new RuntimeException('Baris jurnal ini baru saja dicocokkan ke mutasi bank lain.');
            }
        });
    }

    public function unmatch(BankStatementLine $line): void
    {
        if ($line->status !== 'matched') {
            throw new RuntimeException('Mutasi ini tidak sedang berstatus Cocok.');
        }

        $line->update(['matched_journal_entry_line_id' => null, 'status' => 'unmatched', 'stale_at' => null]);
    }

    public function ignore(BankStatementLine $line): void
    {
        // Mutasi yang sudah dicocokkan harus dibatalkan kecocokannya dulu; kalau tidak, statusnya
        // berubah tapi tautan ke baris jurnal tertinggal dan jurnal itu tak bisa dicocokkan lagi.
        if ($line->status !== 'unmatched') {
            throw new RuntimeException('Hanya mutasi berstatus Belum Cocok yang bisa ditandai Diabaikan.');
        }

        $line->update(['status' => 'ignored']);
    }

    /** Kembalikan mutasi yang ditandai "Diabaikan" ke "Belum Cocok" (audit 2026-09-29: sebelumnya final permanen). */
    public function unignore(BankStatementLine $line): void
    {
        if ($line->status !== 'ignored') {
            throw new RuntimeException('Mutasi ini tidak sedang berstatus Diabaikan.');
        }

        $line->update(['status' => 'unmatched']);
    }

    /**
     * Dipanggil JournalEntryService::reverse() setelah jurnal pembalik dibuat -- tandai mutasi bank
     * yang tadinya dicocokkan ke baris jurnal asli ini sebagai "stale" (perlu ditinjau ulang), TANPA
     * mengubah status 'matched' itu sendiri (histori rekonsiliasi tidak diubah diam-diam).
     */
    public function invalidateForReversal(int $originalJournalEntryId): void
    {
        BankStatementLine::whereIn(
            'matched_journal_entry_line_id',
            \App\Models\JournalEntryLine::where('journal_entry_id', $originalJournalEntryId)->pluck('id')
        )->update(['stale_at' => now()]);
    }
}
