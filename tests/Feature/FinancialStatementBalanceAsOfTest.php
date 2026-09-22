<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\FinancialStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Audit Majoo f46 ("Tambah Widget" — saldo akun COA custom), dibangun
 * 2026-09-22 atas keputusan user (versi sederhana). Fokus test: saldo
 * dihitung sampai tanggal cutoff, mengikuti arah normal_balance akun,
 * dan mengabaikan jurnal 'draft'.
 */
class FinancialStatementBalanceAsOfTest extends TestCase
{
    use RefreshDatabase;

    private function postJournal(ChartOfAccount $account, float $debit, float $credit, string $date, string $status = 'posted'): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-TEST-' . uniqid(),
            'entry_date' => $date,
            'description' => 'Test',
            'status' => $status,
        ]);

        $entry->lines()->create([
            'chart_of_account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
        ]);
    }

    public function test_debit_normal_account_balance_is_debit_minus_credit(): void
    {
        $account = ChartOfAccount::create(['code' => '1101', 'name' => 'Kas', 'type' => 'aset', 'normal_balance' => 'debit']);

        $this->postJournal($account, 1_000_000, 0, '2026-09-01');
        $this->postJournal($account, 0, 300_000, '2026-09-10');

        $balance = app(FinancialStatementService::class)->balanceAsOf($account, Carbon::parse('2026-09-30'));

        $this->assertEquals(700_000.0, $balance);
    }

    public function test_credit_normal_account_balance_is_credit_minus_debit(): void
    {
        $account = ChartOfAccount::create(['code' => '4100', 'name' => 'Pendapatan PPF', 'type' => 'pendapatan', 'normal_balance' => 'kredit']);

        $this->postJournal($account, 0, 500_000, '2026-09-01');

        $balance = app(FinancialStatementService::class)->balanceAsOf($account, Carbon::parse('2026-09-30'));

        $this->assertEquals(500_000.0, $balance);
    }

    public function test_ignores_entries_after_cutoff_and_draft_entries(): void
    {
        $account = ChartOfAccount::create(['code' => '1101', 'name' => 'Kas', 'type' => 'aset', 'normal_balance' => 'debit']);

        $this->postJournal($account, 1_000_000, 0, '2026-09-01');
        $this->postJournal($account, 500_000, 0, '2026-10-05'); // setelah cutoff
        $this->postJournal($account, 999_000, 0, '2026-09-15', 'draft'); // draft, diabaikan

        $balance = app(FinancialStatementService::class)->balanceAsOf($account, Carbon::parse('2026-09-30'));

        $this->assertEquals(1_000_000.0, $balance);
    }
}
