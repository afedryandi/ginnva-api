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

    private function postEntry(string $date, array $lines, ?int $storeId = null): void
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
        $this->postEntry('2026-06-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 1_000_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 1_000_000],
        ]);
        $this->postEntry('2027-02-10', [
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
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);

        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 300_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 300_000],
        ], $store->id);
        $this->postEntry('2026-09-06', [
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
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 123_456.78],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 123_456.78],
        ]);

        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'));

        $this->assertTrue($trial['is_balanced']);
    }

    public function test_trial_balance_with_period_splits_opening_and_mutation(): void
    {
        $this->postEntry('2026-08-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 400_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 400_000],
        ]);
        $this->postEntry('2026-09-10', [
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

    public function test_trial_balance_resets_profit_loss_each_year_and_stays_balanced(): void
    {
        $this->postEntry('2026-06-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 1_000_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 1_000_000],
        ]);
        $this->postEntry('2027-02-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 300_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 300_000],
        ]);

        $trial = $this->service->trialBalance(Carbon::parse('2027-03-31'), null, null, true);

        $revenue = $trial['rows']->first(fn ($r) => $r['account']->id === $this->revenueId());
        $retained = $trial['rows']->first(fn ($r) => $r['account']->code === '3200*');

        $this->assertEquals(300_000.0, $revenue['balance'], 'Pendapatan hanya tahun berjalan (2027).');
        $this->assertNotNull($retained);
        $this->assertEquals(1_000_000.0, $retained['balance']);
        $this->assertTrue($trial['is_balanced']);
        $this->assertArrayHasKey('as_of', $trial);
    }

    public function test_default_trial_balance_stays_cumulative_for_balance_sheet(): void
    {
        $this->postEntry('2026-06-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 1_000_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 1_000_000],
        ]);

        $trial = $this->service->trialBalance(Carbon::parse('2027-03-31'));

        $this->assertNull($trial['rows']->first(fn ($r) => $r['account']->code === '3200*'));
        $this->assertEquals(1_000_000.0, $trial['rows']->first(fn ($r) => $r['account']->id === $this->revenueId())['balance']);
    }

    public function test_income_statement_result_carries_period_for_exports(): void
    {
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 250_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 250_000],
        ]);

        $result = $this->service->incomeStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertTrue($result['from']->isSameDay('2026-09-01'));
        $this->assertTrue($result['to']->isSameDay('2026-09-30'));

        // Ekspor tidak boleh crash (sebelumnya "Undefined array key from").
        $rows = (new \App\Exports\IncomeStatementExport($result))->array();
        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('01 Sep 2026', $rows[1][1]);
    }

    public function test_income_statement_export_with_comparison_has_percentage_and_delta_columns(): void
    {
        $this->postEntry('2026-08-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 100_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 100_000],
        ]);
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 150_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 150_000],
        ]);

        $current = $this->service->incomeStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $current['store_label'] = 'Semua Toko';
        $current['compare'] = $this->service->incomeStatement(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $current['compare_label'] = '01 Aug 2026 – 31 Aug 2026';

        $rows = (new \App\Exports\IncomeStatementExport($current))->array();

        // Header kolom (baris ke-6: judul, periode, toko, pembanding, kosong, header).
        $this->assertSame(['Akun', 'Periode Ini', '% Pendapatan', 'Pembanding', 'Selisih %'], $rows[5]);

        $revenueRow = collect($rows)->first(fn ($r) => is_array($r) && ($r[0] ?? '') === 'Total Pendapatan');
        $this->assertEquals(150_000.0, $revenueRow[1]);
        $this->assertEquals(100.0, $revenueRow[2]);
        $this->assertEquals(100_000.0, $revenueRow[3]);
        $this->assertEquals(50.0, $revenueRow[4]);
    }

    public function test_general_ledger_opening_for_profit_loss_account_resets_each_year(): void
    {
        $this->postEntry('2026-06-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 1_000_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 1_000_000],
        ]);
        $this->postEntry('2027-02-10', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 300_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 300_000],
        ]);

        $ledger = $this->service->generalLedger(ChartOfAccount::find($this->revenueId()), Carbon::parse('2027-03-01'), Carbon::parse('2027-03-31'));

        // Saldo awal Maret 2027 = pendapatan Jan-Feb 2027 saja (300.000), bukan + 1.000.000 dari 2026.
        $this->assertEquals(300_000.0, $ledger['opening_balance']);
        $this->assertNotNull($ledger['opening_reset_from']);

        // Akun neraca (kas) tetap kumulatif sejak awal.
        $cash = $this->service->generalLedger(ChartOfAccount::find($this->cashId()), Carbon::parse('2027-03-01'), Carbon::parse('2027-03-31'));
        $this->assertEquals(1_300_000.0, $cash['opening_balance']);
        $this->assertNull($cash['opening_reset_from']);
    }

    public function test_general_ledger_export_bolds_total_row_and_keeps_numeric_columns(): void
    {
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 10_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 10_000],
        ]);

        $ledger = $this->service->generalLedger(ChartOfAccount::find($this->cashId()), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $ledger['from'] = Carbon::parse('2026-09-01');
        $ledger['to'] = Carbon::parse('2026-09-30');

        $export = new \App\Exports\GeneralLedgerExport($ledger);
        $rows = $export->array();

        // Baris kredit kosong (bukan teks), dan baris Total ada di posisi yang di-bold (3 + jumlah mutasi = 4).
        $this->assertSame('', $rows[1][4]);
        $this->assertSame('Total Mutasi Periode Ini', $rows[2][0]);
        $this->assertArrayHasKey(4, $export->styles(new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet()));
    }

    public function test_general_ledger_rows_carry_source_and_creator(): void
    {
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 10_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 10_000],
        ]);

        $ledger = $this->service->generalLedger(ChartOfAccount::find($this->cashId()), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $row = $ledger['rows']->first();

        $this->assertSame('Manual', $row['source']);
        $this->assertSame('Sistem', $row['creator']); // jurnal uji dibuat tanpa user
    }

    public function test_balance_sheet_stays_balanced_when_filtered_by_store_or_head_office(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);

        // Setoran modal pusat (tanpa toko) dan penjualan tunai toko A.
        $capitalId = ChartOfAccount::where('type', 'modal')->where('is_postable', true)->orderBy('code')->value('id');
        $this->postEntry('2026-09-01', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 5_000_000],
            ['chart_of_account_id' => $capitalId, 'credit' => 5_000_000],
        ], null);
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 200_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 200_000],
        ], $store->id);

        $all = $this->service->balanceSheet(Carbon::parse('2026-09-30'));
        $scoped = $this->service->balanceSheet(Carbon::parse('2026-09-30'), $store->id);
        $pusat = $this->service->balanceSheet(Carbon::parse('2026-09-30'), FinancialStatementService::COMPANY_WIDE);

        // Tiap jurnal punya 1 toko dan sudah seimbang, jadi setiap potongan per toko/pusat juga seimbang.
        // Kalau salah satunya TIDAK balance, itu tanda data bermasalah -- bukan hal yang wajar.
        $this->assertTrue($all['is_balanced']);
        $this->assertTrue($scoped['is_balanced']);
        $this->assertTrue($pusat['is_balanced']);
    }

    public function test_balance_sheet_ratios_use_current_and_total_figures(): void
    {
        $capitalId = ChartOfAccount::where('code', '3100')->value('id');
        $payableId = ChartOfAccount::where('code', '2110')->value('id'); // Kewajiban Lancar (induk 2100)

        $this->postEntry('2026-09-01', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 1_000_000],   // Aset Lancar (induk 1100)
            ['chart_of_account_id' => $capitalId, 'credit' => 800_000],
            ['chart_of_account_id' => $payableId, 'credit' => 200_000],
        ]);

        $sheet = $this->service->balanceSheet(Carbon::parse('2026-09-30'));
        $ratios = $sheet['ratios'];

        $this->assertEquals(1_000_000.0, $ratios['current_assets']);
        $this->assertEquals(200_000.0, $ratios['current_liabilities']);
        $this->assertEquals(5.0, $ratios['current_ratio']);
        $this->assertEquals(800_000.0, $ratios['working_capital']);
        $this->assertEquals(0.25, $ratios['debt_to_equity']);
        $this->assertEquals(20.0, $ratios['debt_to_assets']);
    }

    public function test_balance_sheet_export_with_comparison_has_percentage_and_delta_columns(): void
    {
        $this->postEntry('2026-08-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 100_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 100_000],
        ]);
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 100_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 100_000],
        ]);

        $current = $this->service->balanceSheet(Carbon::parse('2026-09-30'));
        $current['store_label'] = 'Semua Toko';
        $current['compare'] = $this->service->balanceSheet(Carbon::parse('2026-08-31'));
        $current['compare_label'] = '31 Aug 2026';

        $rows = (new \App\Exports\BalanceSheetExport($current))->array();

        $this->assertSame(['Akun', 'Saldo', '% Total Aset', 'Pembanding', 'Selisih %'], $rows[5]);

        $totalAset = collect($rows)->first(fn ($r) => is_array($r) && ($r[0] ?? '') === 'Total Aset');
        $this->assertEquals(200_000.0, $totalAset[1]);
        $this->assertEquals(100.0, $totalAset[2]);
        $this->assertEquals(100_000.0, $totalAset[3]);
        $this->assertEquals(100.0, $totalAset[4]);
    }

    public function test_cash_flow_totals_are_exact_in_cents_and_reconciled(): void
    {
        // Banyak nominal desimal: penjumlahan float rawan drift, sen integer tidak.
        foreach ([33_333.33, 66_666.67, 0.10, 0.20, 0.30] as $i => $amount) {
            $this->postEntry('2026-09-0' . ($i + 1), [
                ['chart_of_account_id' => $this->cashId(), 'debit' => $amount],
                ['chart_of_account_id' => $this->revenueId(), 'credit' => $amount],
            ]);
        }

        $cash = $this->service->cashFlowStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertEquals(100_000.60, $cash['net_change']);
        $this->assertEquals(100_000.60, $cash['closing_cash']);
        $this->assertTrue($cash['is_reconciled']);
        $this->assertSame(10_000_060, $cash['sections']['operasional']['rows']->sum('amount_cents') + $cash['sections']['investasi']['rows']->sum('amount_cents') + $cash['sections']['pendanaan']['rows']->sum('amount_cents'));
    }

    public function test_cash_flow_excel_writes_numbers_not_text(): void
    {
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 1_500_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 1_500_000],
        ]);

        $cash = $this->service->cashFlowStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $cash['from'] = Carbon::parse('2026-09-01');
        $cash['to'] = Carbon::parse('2026-09-30');

        $rows = (new \App\Exports\CashFlowExport($cash))->array();
        $closing = collect($rows)->first(fn ($r) => ($r[0] ?? '') === 'Saldo Kas Akhir Periode');

        $this->assertIsFloat($closing[1]);
        $this->assertEquals(1_500_000.0, $closing[1]);
    }

    public function test_cash_flow_groups_by_counterparty_and_breaks_down_cash_accounts(): void
    {
        $bankId = ChartOfAccount::where('code', '1102')->value('id'); // Kas di Bank (is_cash)

        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 100_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 100_000],
        ]);
        $this->postEntry('2026-09-06', [
            ['chart_of_account_id' => $bankId, 'debit' => 250_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 250_000],
        ]);

        $cash = $this->service->cashFlowStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        // Kedua jurnal berlawanan dengan akun pendapatan yang sama -> 1 kelompok jenis, 2 jurnal, total 350.000.
        $groups = collect($cash['sections'])->flatMap(fn ($s) => $s['groups']);
        $this->assertCount(1, $groups);
        $this->assertEquals(350_000.0, $groups->first()['total']);
        $this->assertCount(2, $groups->first()['rows']);
        $this->assertNotNull($groups->first()['rows']->first()['entry_id']);

        // Rincian per akun kas: Kas di Tangan 100.000 dan Kas di Bank 250.000, total = saldo akhir.
        $byCode = collect($cash['cash_accounts'])->keyBy(fn ($r) => $r['account']->code);
        $this->assertEquals(100_000.0, $byCode['1101']['closing']);
        $this->assertEquals(250_000.0, $byCode['1102']['closing']);
        $this->assertEquals($cash['closing_cash'], collect($cash['cash_accounts'])->sum('closing'));
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
        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 10_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 10_000],
        ]);

        $ledger = $this->service->generalLedger(ChartOfAccount::find($this->cashId()), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertNotNull($ledger['rows']->first()['entry_id']);
    }

    public function test_cash_flow_flags_accounts_without_category(): void
    {
        ChartOfAccount::whereKey($this->revenueId())->update(['cash_flow_category' => null]);

        $this->postEntry('2026-09-05', [
            ['chart_of_account_id' => $this->cashId(), 'debit' => 100_000],
            ['chart_of_account_id' => $this->revenueId(), 'credit' => 100_000],
        ]);

        $cash = $this->service->cashFlowStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertNotEmpty($cash['warnings']);
        $this->assertTrue($cash['is_reconciled']);
    }
}
