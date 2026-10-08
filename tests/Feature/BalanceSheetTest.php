<?php

namespace Tests\Feature;

use App\Exports\BalanceSheetExport;
use App\Filament\Pages\BalanceSheetReport;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\FinancialStatementService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Neraca: Aset = Kewajiban + Modal (termasuk laba tahun berjalan dan laba tahun lalu yang belum ditutup),
 * akun kontra, pergantian tahun buku, beban pajak, rasio keuangan, potongan toko, isi ekspor Excel
 * (kolom % total aset, pembanding, rasio), PDF, dan tampilan halaman.
 */
class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private FinancialStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('spv_finance', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->service = app(FinancialStatementService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    private function id(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    /** Jurnal posted dari pasangan [kode, sisi, nominal]. */
    private function book(string $date, array $legs, ?Store $store = null, bool $post = true): JournalEntry
    {
        $svc = app(JournalEntryService::class);
        $entry = $svc->create(['entry_date' => $date, 'store_id' => $store?->id, 'description' => 'Uji neraca', 'reference_type' => 'manual'], array_map(
            fn (array $leg) => ['chart_of_account_id' => $this->id($leg[0]), $leg[1] => $leg[2]],
            $legs
        ));

        return $post ? $svc->post($entry, null) : $entry;
    }

    /** Skenario September 2026: kas 1.250.000, utang 200.000, modal 800.000, laba berjalan 250.000. */
    private function fixture(): void
    {
        $this->book('2026-09-01', [['1101', 'debit', 1000000], ['3100', 'credit', 800000], ['2110', 'credit', 200000]]);
        $this->book('2026-09-05', [['1101', 'debit', 300000], ['4100', 'credit', 300000]]);
        $this->book('2026-09-06', [['6110', 'debit', 50000], ['1101', 'credit', 50000]]);
    }

    private function sheet(string $asOf = '2026-09-30', ?int $storeId = null): array
    {
        return $this->service->balanceSheet(Carbon::parse($asOf), $storeId);
    }

    // ------------------------------------------------------------- angka

    public function test_assets_equal_liabilities_plus_equity_including_current_year_profit(): void
    {
        $this->fixture();

        $sheet = $this->sheet();

        $this->assertEquals(1250000.0, $sheet['aset']['total']);
        $this->assertEquals(200000.0, $sheet['kewajiban']['total']);
        $this->assertEquals(800000.0, $sheet['modal']['total_posted']);
        $this->assertEquals(250000.0, $sheet['modal']['laba_tahun_berjalan']);
        $this->assertEquals(1050000.0, $sheet['modal']['total']);
        $this->assertEquals(1250000.0, $sheet['total_kewajiban_modal']);
        $this->assertTrue($sheet['is_balanced']);
    }

    public function test_drafts_are_excluded_and_a_reversal_cancels_out(): void
    {
        $this->fixture();
        $this->book('2026-09-08', [['1101', 'debit', 999], ['4100', 'credit', 999]], null, false);
        $original = $this->book('2026-09-09', [['1101', 'debit', 70000], ['4100', 'credit', 70000]]);
        app(JournalEntryService::class)->reverse($original, null, 'Batal', '2026-09-20');

        $sheet = $this->sheet();

        $this->assertEquals(1250000.0, $sheet['aset']['total']);
        $this->assertTrue($sheet['is_balanced']);
    }

    public function test_a_contra_asset_reduces_total_assets_and_stays_balanced(): void
    {
        $contra = ChartOfAccount::where('is_contra', true)->where('type', 'aset')->where('is_postable', true)->orderBy('code')->firstOrFail();
        $this->book('2026-09-01', [['1101', 'debit', 1000000], ['3100', 'credit', 1000000]]);
        $this->book('2026-09-10', [['6420', 'debit', 100000], [$contra->code, 'credit', 100000]]);

        $sheet = $this->sheet();
        $row = $sheet['aset']['rows']->first(fn ($r) => $r['account']->code === $contra->code);

        $this->assertEquals(-100000.0, $row['balance']);
        $this->assertEquals(900000.0, $sheet['aset']['total']);
        $this->assertEquals(-100000.0, $sheet['modal']['laba_tahun_berjalan']);
        $this->assertTrue($sheet['is_balanced']);
    }

    public function test_tax_expense_reduces_equity_and_the_sheet_still_balances(): void
    {
        $this->book('2026-09-01', [['1101', 'debit', 1000000], ['3100', 'credit', 1000000]]);
        $this->book('2026-09-05', [['1101', 'debit', 200000], ['4100', 'credit', 200000]]);
        $this->book('2026-09-20', [['8100', 'debit', 30000], ['1101', 'credit', 30000]]);

        $sheet = $this->sheet();

        $this->assertEquals(1170000.0, $sheet['aset']['total']);
        $this->assertEquals(170000.0, $sheet['modal']['laba_tahun_berjalan']);
        $this->assertTrue($sheet['is_balanced'], 'Beban pajak harus mengurangi laba, bukan menambah.');
    }

    public function test_profit_moves_from_current_year_to_prior_years_at_the_new_year_without_changing_equity(): void
    {
        $this->book('2026-01-02', [['1101', 'debit', 500000], ['3100', 'credit', 500000]]);
        $this->book('2026-12-20', [['1101', 'debit', 100000], ['4100', 'credit', 100000]]);

        $before = $this->sheet('2026-12-31');
        $after = $this->sheet('2027-01-01');

        $this->assertEquals(100000.0, $before['modal']['laba_tahun_berjalan']);
        $this->assertEquals(0.0, $before['modal']['laba_tahun_lalu']);
        $this->assertEquals(0.0, $after['modal']['laba_tahun_berjalan']);
        $this->assertEquals(100000.0, $after['modal']['laba_tahun_lalu']);
        $this->assertEquals($before['modal']['total'], $after['modal']['total']);
        $this->assertTrue($before['is_balanced'] && $after['is_balanced']);
    }

    public function test_a_prior_year_loss_and_current_year_profit_both_flow_into_equity(): void
    {
        $this->book('2025-01-02', [['1101', 'debit', 1000000], ['3100', 'credit', 1000000]]);
        $this->book('2025-08-10', [['6110', 'debit', 400000], ['1101', 'credit', 400000]]);
        $this->book('2026-02-10', [['1101', 'debit', 150000], ['4100', 'credit', 150000]]);

        $sheet = $this->sheet('2026-03-31');

        $this->assertEquals(-400000.0, $sheet['modal']['laba_tahun_lalu']);
        $this->assertEquals(150000.0, $sheet['modal']['laba_tahun_berjalan']);
        $this->assertEquals(750000.0, $sheet['aset']['total']);
        $this->assertEquals(750000.0, $sheet['modal']['total']);
        $this->assertTrue($sheet['is_balanced']);
    }

    public function test_store_and_head_office_slices_each_balance(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->book('2026-09-01', [['1101', 'debit', 5000000], ['3100', 'credit', 5000000]]);
        $this->book('2026-09-05', [['1101', 'debit', 200000], ['4100', 'credit', 200000]], $store);

        $mine = $this->sheet('2026-09-30', $store->id);
        $pusat = $this->sheet('2026-09-30', FinancialStatementService::COMPANY_WIDE);

        $this->assertEquals(200000.0, $mine['aset']['total']);
        $this->assertEquals(5000000.0, $pusat['aset']['total']);
        $this->assertTrue($mine['is_balanced'] && $pusat['is_balanced'] && $this->sheet()['is_balanced']);
    }

    public function test_a_posted_but_unbalanced_entry_is_flagged(): void
    {
        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => '2026-09-05', 'description' => 'Miring', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => $this->id('1101'), 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['chart_of_account_id' => $this->id('4100'), 'debit' => 0, 'credit' => 900]);

        $this->assertFalse($this->sheet()['is_balanced']);
    }

    public function test_ratios_use_current_assets_and_liabilities_and_are_null_without_a_denominator(): void
    {
        $this->fixture();
        $ratios = $this->sheet()['ratios'];

        $this->assertEquals(1250000.0, $ratios['current_assets']);
        $this->assertEquals(200000.0, $ratios['current_liabilities']);
        $this->assertEquals(1050000.0, $ratios['working_capital']);
        $this->assertEquals(6.25, $ratios['current_ratio']);
        $this->assertEquals(0.19, $ratios['debt_to_equity']);
        $this->assertEquals(16.0, $ratios['debt_to_assets']);

        $empty = $this->service->balanceSheet(Carbon::parse('2020-01-01'))['ratios'];
        $this->assertNull($empty['current_ratio']);
        $this->assertNull($empty['debt_to_equity']);
        $this->assertNull($empty['debt_to_assets']);
        $this->assertEquals(0.0, $empty['working_capital']);
    }

    // ------------------------------------------------------------- ekspor Excel

    private function find(array $rows, string $label): array
    {
        return collect($rows)->first(fn ($r) => ($r[0] ?? null) === $label) ?? $this->fail("Baris '{$label}' tidak ditemukan.");
    }

    public function test_excel_export_lists_sections_percent_of_assets_and_ratios(): void
    {
        $this->fixture();
        $export = new BalanceSheetExport($this->sheet() + ['store_label' => 'Semua Toko']);
        $rows = $export->array();

        $this->assertSame(['Neraca (Balance Sheet)'], $rows[0]);
        $this->assertSame(['Per Tanggal', '30 Sep 2026'], $rows[1]);
        $this->assertSame(['Toko', 'Semua Toko'], $rows[2]);
        $this->assertSame(['Akun', 'Saldo', '% Total Aset'], $rows[4]);
        $this->assertContains(['ASET'], $rows);
        $this->assertContains(['KEWAJIBAN'], $rows);
        $this->assertContains(['MODAL'], $rows);

        $this->assertSame([1250000.0, 100.0], array_slice($this->find($rows, 'Total Aset'), 1, 2));
        $this->assertSame([200000.0, 16.0], array_slice($this->find($rows, 'Total Kewajiban'), 1, 2));
        $this->assertSame([250000.0, 20.0], array_slice($this->find($rows, '    Laba (Rugi) Tahun Berjalan'), 1, 2));
        $this->assertSame([1050000.0, 84.0], array_slice($this->find($rows, 'Total Modal'), 1, 2));
        $this->assertSame(1250000.0, $this->find($rows, 'Total Kewajiban + Modal')[1]);
        $this->assertContains(['Balance — Total Aset sama dengan Total Kewajiban + Modal.'], $rows);
        $this->assertNotContains('    Laba (Rugi) Tahun-Tahun Sebelumnya (belum ditutup)', array_column($rows, 0), 'Baris laba tahun lalu hanya muncul bila tidak nol.');

        $this->assertSame('6.25x', $this->find($rows, 'Rasio Lancar (Aset Lancar / Kewajiban Lancar)')[1]);
        $this->assertSame(1050000.0, $this->find($rows, 'Modal Kerja (Aset Lancar - Kewajiban Lancar)')[1]);
        $this->assertSame('0.19x', $this->find($rows, 'Kewajiban terhadap Modal')[1]);
        $this->assertSame('16%', $this->find($rows, 'Kewajiban terhadap Aset')[1]);
    }

    public function test_excel_export_shows_prior_year_profit_and_the_unbalanced_message(): void
    {
        $this->book('2025-01-02', [['1101', 'debit', 100000], ['4100', 'credit', 100000]]);
        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => '2026-09-05', 'description' => 'Miring', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => $this->id('1101'), 'debit' => 1000, 'credit' => 0]);

        $rows = (new BalanceSheetExport($this->sheet()))->array();

        $this->assertEquals(100000.0, $this->find($rows, '    Laba (Rugi) Tahun-Tahun Sebelumnya (belum ditutup)')[1]);
        $this->assertContains(['TIDAK balance — periksa jurnal yang mungkin belum lengkap.'], $rows);
    }

    public function test_excel_export_with_a_comparison_has_previous_and_delta_columns_and_bold_totals(): void
    {
        $this->book('2026-08-01', [['1101', 'debit', 100000], ['3100', 'credit', 100000]]);
        $this->book('2026-09-05', [['1101', 'debit', 100000], ['3100', 'credit', 100000]]);
        $current = $this->sheet() + ['store_label' => 'Semua Toko'];
        $current['compare'] = $this->sheet('2026-08-31');
        $current['compare_label'] = '31 Aug 2026';
        $export = new BalanceSheetExport($current);
        $rows = $export->array();

        $this->assertSame(['Pembanding', '31 Aug 2026'], $rows[3]);
        $this->assertSame(['Akun', 'Saldo', '% Total Aset', 'Pembanding', 'Selisih %'], $rows[5]);
        $this->assertSame([200000.0, 100.0, 100000.0, 100.0], array_slice($this->find($rows, 'Total Aset'), 1, 4));
        $this->assertSame(['A' => 52, 'B' => 18, 'C' => 15, 'D' => 18, 'E' => 12], $export->columnWidths());

        $styles = $export->styles(new Worksheet());
        $totalRow = collect($rows)->search(fn ($r) => ($r[0] ?? null) === 'Total Aset') + 1;
        $this->assertSame(['font' => ['bold' => true]], $styles[$totalRow]);
    }

    // ------------------------------------------------------------- halaman & PDF

    public function test_page_shows_totals_the_balanced_banner_ratio_cards_and_the_profit_line(): void
    {
        $this->fixture();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(BalanceSheetReport::class)
            ->assertSuccessful()
            ->assertSee('Neraca per 30 Sep 2026')
            ->assertSee('Rp 1.250.000')
            ->assertSee('Laba (Rugi) Tahun Berjalan')
            ->assertSee('✓ Balance')
            ->assertSee('6,25x')
            ->assertSee('16,0%')
            ->assertSee('Rp 1.050.000');
    }

    public function test_page_warns_loudly_when_the_sheet_does_not_balance(): void
    {
        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => '2026-09-05', 'description' => 'Miring', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => $this->id('1101'), 'debit' => 1000, 'credit' => 0]);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(BalanceSheetReport::class)->assertSee('TIDAK balance');
    }

    public function test_page_empty_states_and_draft_notice(): void
    {
        $this->book('2026-09-05', [['1101', 'debit', 100], ['4100', 'credit', 100]], null, false);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(BalanceSheetReport::class)->assertSee('Belum ada saldo aset.')->assertSee('Belum ada saldo kewajiban.');

        $this->assertTrue(collect($page->instance()->getNotices())->contains(fn ($n) => $n['type'] === 'warning' && str_contains($n['text'], 'DRAFT')));
    }

    public function test_page_presets_fill_the_date_and_comparison_modes_work(): void
    {
        $this->book('2026-08-01', [['1101', 'debit', 100000], ['3100', 'credit', 100000]]);
        $this->book('2025-09-01', [['1101', 'debit', 40000], ['3100', 'credit', 40000]]);
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(BalanceSheetReport::class);

        foreach (['last_month_end' => '2026-08-31', 'last_quarter_end' => '2026-06-30', 'last_year_end' => '2025-12-31', 'today' => '2026-09-30'] as $preset => $date) {
            $page->set('data.preset', $preset);
            $this->assertSame($date, Carbon::parse($page->get('data.as_of'))->toDateString(), $preset);
        }

        $page->set('data.as_of', '2026-09-30')->set('data.compare', 'prev_month');
        $this->assertEquals(140000.0, $page->instance()->getResult()['compare']['aset']['total'], 'Kumulatif: 40.000 (2025) + 100.000 (Agustus 2026).');
        $this->assertSame('30 Aug 2026', $page->instance()->getResult()['compare_label']);

        $page->set('data.compare', 'prev_year');
        $this->assertEquals(40000.0, $page->instance()->getResult()['compare']['aset']['total']);
        $page->assertSee('+250,0%');
    }

    public function test_page_drill_down_links_to_the_ledger_from_january_first(): void
    {
        $this->actingAs($this->admin(), 'web');

        $url = Livewire::test(BalanceSheetReport::class)->set('data.as_of', '2026-09-30')->instance()->ledgerUrl($this->id('1101'));

        $this->assertStringContainsString('chart_of_account_id=' . $this->id('1101'), $url);
        $this->assertStringContainsString('from=2026-01-01', $url);
        $this->assertStringContainsString('to=2026-09-30', $url);
    }

    public function test_pdf_and_excel_download_in_every_mode(): void
    {
        $this->fixture();
        $this->book('2025-03-01', [['1101', 'debit', 10000], ['4100', 'credit', 10000]]);
        $this->actingAs($this->admin(), 'web');
        Excel::fake();

        $page = Livewire::test(BalanceSheetReport::class);
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        $page->set('data.compare', 'prev_month')->callAction('exportPdf')->assertHasNoActionErrors();
        $page->callAction('exportExcel')->assertHasNoActionErrors();

        Excel::assertDownloaded('neraca-20260930-100000.xlsx');
    }

    public function test_pdf_renders_when_the_sheet_is_empty_or_unbalanced(): void
    {
        $this->actingAs($this->admin(), 'web');
        Livewire::test(BalanceSheetReport::class)->callAction('exportPdf')->assertHasNoActionErrors();

        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => '2026-09-05', 'description' => 'Miring', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => $this->id('1101'), 'debit' => 1000, 'credit' => 0]);
        Livewire::test(BalanceSheetReport::class)->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
