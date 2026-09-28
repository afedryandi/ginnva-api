<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Store;
use App\Services\FinancialStatementService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Audit Laporan Keuangan 2026-09-29: invarian laporan (Neraca tetap balance lintas tahun buku,
 * filter jurnal pusat, Neraca Saldo seimbang). Belum pernah dijalankan lokal -- tidak ada PHP;
 * cek hasil CI.
 */
class FinancialStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
        $this->service = app(FinancialStatementService::class);
    }

    private function post(string $date, array $lines, ?int $storeId = null): void
    {
        $journal = app(JournalEntryService::class);
        $entry = $journal->create([
            'entry_date' => $date,
            'store_id' => $storeId,
            'description' => 'Uji laporan',
            'reference_type' => 'manual',
        ], $lines);
        $journal->post($entry, null);
    }

    private function cashId(): int
    {
        return ChartOfAccount::where('code', '1101')->value('id');
    }

    private function revenueId(): int
    {
        return ChartOfAccount::where('type', 'pendapatan')->where('is_postable', true)->orderBy('code')->value('id');
    }

    private function expenseId(): int
    {
        return ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->orderBy('code')->value('id');
    }

    public function test_balance_sheet_stays_balanced_in_following_fiscal_year(): void
    {
        $this->post('2026-06-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 1_000_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 1_000_000],
        ]);
        $this->post('2027-02-10', [
            ['chart_of_account_id' => $this->expenseId(), 'debit' => 200_000],
            ['chart_of_account_id' => $this->cashId(), 'credit' => 200_000],
        ]);

        $sheet = $this->service->balanceSheet(Carbon::parse('2027-03-31'));

        $this->assertTrue($sheet['is_balanced'], 'Neraca harus tetap balance di tahun buku berikutnya.');
        $this->assertEquals(1_000_000.0, $sheet['modal']['laba_tahun_lalu']);
        $this->assertEquals(-200_000.0, $sheet['modal']['laba_tahun_berjalan']);
    }

    public function test_company_wide_filter_only_includes_journals_without_store(): void
    {
        $store = Store::create(['name' => 'Toko A', 'is_active' => true]);

        $this->post('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 300_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 300_000],
        ], $store->id);
        $this->post('2026-09-06', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 500_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 500_000],
        ], null);

        $from = Carbon::parse('2026-09-01');
        $to = Carbon::parse('2026-09-30');

        $this->assertEquals(300_000.0, $this->service->incomeStatement($from, $to, $store->id)['laba_bersih']);
        $this->assertEquals(500_000.0, $this->service->incomeStatement($from, $to, FinancialStatementService::COMPANY_WIDE)['laba_bersih']);
        $this->assertEquals(800_000.0, $this->service->incomeStatement($from, $to, null)['laba_bersih']);
    }

    public function test_trial_balance_is_balanced(): void
    {
        $this->post('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 123_456.78],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 123_456.78],
        ]);

        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'));

        $this->assertTrue($trial['is_balanced']);
    }

    public function test_trial_balance_with_period_splits_opening_and_mutation(): void
    {
        $this->post('2026-08-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 400_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 400_000],
        ]);
        $this->post('2026-09-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 100_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 100_000],
        ]);

        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'), null, Carbon::parse('2026-09-01'));
        $cash = $trial['rows']->first(fn ($r) => $r['account']->id === $this->cashId());

        $this->assertTrue($trial['has_period']);
        $this->assertEquals(400_000.0, $cash['opening_balance']);
        $this->assertEquals(100_000.0, $cash['period_debit']);
        $this->assertEquals(500_000.0, $cash['balance']);
    }

    public function test_report_notices_flag_draft_journals(): void
    {
        $journal = app(JournalEntryService::class);
        $journal->create([
            'entry_date' => '2026-09-05',
            'store_id' => null,
            'description' => 'Masih draft',
            'reference_type' => 'manual',
        ], [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 50_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 50_000],
        ]);

        $notices = $this->service->reportNotices(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertNotEmpty(collect($notices)->where('type', 'warning'));
    }

    public function test_general_ledger_rows_carry_entry_id_for_drill_down(): void
    {
        $this->post('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 10_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 10_000],
        ]);

        $ledger = $this->service->generalLedger(ChartOfAccount::find($this->cashId()), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertNotNull($ledger['rows']->first()['entry_id']);
    }

    public function test_cash_flow_flags_accounts_without_category(): void
    {
        ChartOfAccount::whereKey($this->revenueId())->update(['cash_flow_category' => null]);

        $this->post('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 100_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 100_000],
        ]);

        $cash = $this->service->cashFlowStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertNotEmpty($cash['warnings']);
        $this->assertTrue($cash['is_reconciled']);
    }
}
