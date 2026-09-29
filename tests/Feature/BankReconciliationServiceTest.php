<?php

namespace Tests\Feature;

use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Services\BankReconciliationService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit Rekonsiliasi Bank 2026-09-29: 1 baris jurnal tidak boleh dicocokkan ke >1 mutasi bank,
 * idempotensi impor, unignore, dan status ditandai stale saat jurnalnya dibalik. (Belum pernah
 * dijalankan lokal -- tidak ada PHP; cek hasil CI.)
 */
class BankReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BankReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
        $this->service = app(BankReconciliationService::class);
    }

    private function cashAccount(): ChartOfAccount
    {
        return ChartOfAccount::where('code', '1101')->firstOrFail();
    }

    private function postJournal(float $amount, string $date = '2026-09-05')
    {
        $journal = app(JournalEntryService::class);
        $entry = $journal->create([
            'entry_date' => $date,
            'store_id' => null,
            'description' => 'Uji rekonsiliasi',
            'reference_type' => 'manual',
        ], [
            ['chart_of_account_id' => $this->cashAccount()->id, 'debit' => $amount],
            ['chart_of_account_id' => ChartOfAccount::where('type', 'pendapatan')->where('is_postable', true)->value('id'), 'credit' => $amount],
        ]);

        return $journal->post($entry, null);
    }

    public function test_cannot_match_same_journal_line_twice(): void
    {
        $entry = $this->postJournal(100_000);
        $journalLine = $entry->lines()->first();

        $line1 = BankStatementLine::create(['chart_of_account_id' => $this->cashAccount()->id, 'statement_date' => '2026-09-05', 'description' => 'A', 'amount' => 100_000, 'status' => 'unmatched']);
        $line2 = BankStatementLine::create(['chart_of_account_id' => $this->cashAccount()->id, 'statement_date' => '2026-09-05', 'description' => 'B', 'amount' => 100_000, 'status' => 'unmatched']);

        $this->service->match($line1, $journalLine);

        $this->expectException(RuntimeException::class);
        $this->service->match($line2, $journalLine);
    }

    public function test_import_skips_exact_duplicate_rows(): void
    {
        $account = $this->cashAccount();
        $rows = [['date' => '2026-09-05', 'description' => 'Setoran', 'amount' => 50_000]];

        $first = $this->service->importRows($rows, $account, null);
        $second = $this->service->importRows($rows, $account, null);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame(1, BankStatementLine::count());
    }

    public function test_import_duplicate_detection_ignores_whitespace_and_case(): void
    {
        $account = $this->cashAccount();

        $this->service->importRows([['date' => '2026-09-05', 'description' => 'Setoran  Tunai', 'amount' => 50_000]], $account, null);
        $second = $this->service->importRows([['date' => '2026-09-05', 'description' => 'setoran tunai', 'amount' => 50_000]], $account, null);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['duplicates']);
    }

    public function test_import_creates_archive_batch_record(): void
    {
        $account = $this->cashAccount();

        $result = $this->service->importRows(
            [['date' => '2026-09-05', 'description' => 'Setoran', 'amount' => 50_000]],
            $account,
            null,
            ['original_filename' => 'mutasi-sept.csv', 'archived_path' => 'bank-statement-archive/x.csv']
        );

        $batch = \App\Models\BankStatementImportBatch::where('batch', $result['batch'])->first();
        $this->assertNotNull($batch);
        $this->assertSame('mutasi-sept.csv', $batch->original_filename);
        $this->assertSame(1, $batch->imported_count);
    }

    public function test_auto_match_only_when_unambiguous(): void
    {
        $account = $this->cashAccount();
        $this->postJournal(75_000);
        $this->postJournal(75_000); // 2 kandidat identik -> ambigu, harus dilewati

        BankStatementLine::create(['chart_of_account_id' => $account->id, 'statement_date' => '2026-09-05', 'description' => 'Ambigu', 'amount' => 75_000, 'status' => 'unmatched']);

        $matched = $this->service->autoMatch($account);

        $this->assertSame(0, $matched, 'Kandidat ganda harus dilewati, bukan ditebak.');
    }

    public function test_unignore_returns_line_to_unmatched(): void
    {
        $line = BankStatementLine::create(['chart_of_account_id' => $this->cashAccount()->id, 'statement_date' => '2026-09-05', 'description' => 'Biaya admin', 'amount' => -5_000, 'status' => 'unmatched']);

        $this->service->ignore($line);
        $this->assertSame('ignored', $line->fresh()->status);

        $this->service->unignore($line->fresh());
        $this->assertSame('unmatched', $line->fresh()->status);
    }

    public function test_matched_line_is_flagged_stale_when_journal_is_reversed(): void
    {
        $entry = $this->postJournal(200_000);
        $journalLine = $entry->lines()->first();

        $line = BankStatementLine::create(['chart_of_account_id' => $this->cashAccount()->id, 'statement_date' => '2026-09-05', 'description' => 'Setoran', 'amount' => 200_000, 'status' => 'unmatched']);
        $this->service->match($line, $journalLine);

        app(JournalEntryService::class)->reverse($entry->fresh(), null, 'Koreksi uji');

        $fresh = $line->fresh();
        $this->assertSame('matched', $fresh->status, 'Status tidak diubah diam-diam.');
        $this->assertNotNull($fresh->stale_at, 'Tapi ditandai perlu ditinjau ulang.');
    }
}
