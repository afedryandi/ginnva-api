<?php

namespace Tests\Feature;

use App\Exports\IncomeStatementExport;
use App\Filament\Pages\IncomeStatementReport;
use App\Models\ChartOfAccount;
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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Laba Rugi: rumus bertingkat (laba kotor -> operasional -> sebelum pajak -> bersih), akun kontra
 * pendapatan, batas tanggal yang inklusif, draft/jurnal pembalik, isi ekspor Excel (kolom % dan
 * pembanding), PDF, serta tampilan halaman (angka, margin, selisih %, kondisi kosong, rugi).
 */
class IncomeStatementTest extends TestCase
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

    /** Satu jurnal: akun $code diisi $amount di sisi $side, lawannya kas (1101). */
    private function book(string $date, string $code, float $amount, string $side, ?Store $store = null, bool $draft = false)
    {
        $svc = app(JournalEntryService::class);
        $cashSide = $side === 'debit' ? 'credit' : 'debit';
        $entry = $svc->create(['entry_date' => $date, 'store_id' => $store?->id, 'description' => 'Uji laba rugi', 'reference_type' => 'manual'], [
            ['chart_of_account_id' => $this->id($code), $side => $amount],
            ['chart_of_account_id' => $this->id('1101'), $cashSide => $amount],
        ]);

        return $draft ? $entry : $svc->post($entry, null);
    }

    /** Skenario lengkap September 2026 -- lihat angka di assert. */
    private function fullMonth(): void
    {
        $this->book('2026-09-05', '4100', 800000, 'credit');
        $this->book('2026-09-06', '4200', 200000, 'credit');
        $this->book('2026-09-07', '4900', 100000, 'debit');   // retur/potongan: mengurangi pendapatan
        $this->book('2026-09-08', '5100', 300000, 'debit');   // HPP
        $this->book('2026-09-09', '6110', 150000, 'debit');   // beban operasional
        $this->book('2026-09-10', '7100', 20000, 'credit');   // pendapatan lain
        $this->book('2026-09-11', '7800', 10000, 'debit');    // beban lain
        $this->book('2026-09-12', '8100', 25000, 'debit');    // pajak
    }

    private function sept(): array
    {
        return $this->service->incomeStatement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
    }

    // ------------------------------------------------------------- rumus

    public function test_the_profit_waterfall_adds_up_across_all_sections(): void
    {
        $this->fullMonth();

        $result = $this->sept();

        $this->assertEquals(900000.0, $result['sections']['pendapatan']['total'], 'Retur mengurangi pendapatan.');
        $this->assertEquals(300000.0, $result['sections']['beban_pokok']['total']);
        $this->assertEquals(600000.0, $result['laba_kotor']);
        $this->assertEquals(450000.0, $result['laba_operasional']);
        $this->assertEquals(460000.0, $result['laba_sebelum_pajak']);
        $this->assertEquals(435000.0, $result['laba_bersih']);
        $this->assertEquals(-100000.0, $result['sections']['pendapatan']['rows']->first(fn ($r) => $r['account']->code === '4900')['amount']);
    }

    public function test_only_income_statement_accounts_are_counted_and_drafts_are_ignored(): void
    {
        $this->book('2026-09-05', '4100', 500000, 'credit');
        $this->book('2026-09-06', '4100', 999999, 'credit', null, true);
        app(JournalEntryService::class)->create(['entry_date' => '2026-09-07', 'description' => 'Modal', 'reference_type' => 'manual'], [
            ['chart_of_account_id' => $this->id('1101'), 'debit' => 70000],
            ['chart_of_account_id' => $this->id('3100'), 'credit' => 70000],
        ]);

        $result = $this->sept();

        $this->assertEquals(500000.0, $result['laba_bersih']);
        $this->assertCount(1, collect($result['sections'])->flatMap(fn ($s) => $s['rows']));
    }

    public function test_the_date_range_is_inclusive_at_both_ends(): void
    {
        $this->book('2026-08-31', '4100', 1, 'credit');
        $this->book('2026-09-01', '4100', 10, 'credit');
        $this->book('2026-09-30', '4100', 100, 'credit');
        $this->book('2026-10-01', '4100', 1000, 'credit');

        $this->assertEquals(110.0, $this->sept()['laba_bersih']);
    }

    public function test_a_reversal_removes_the_amount_and_an_empty_period_is_zero(): void
    {
        $entry = $this->book('2026-09-05', '4100', 400000, 'credit');
        app(JournalEntryService::class)->reverse($entry, null, 'Batal', '2026-09-20');

        $this->assertEquals(0.0, $this->sept()['laba_bersih']);

        $empty = $this->service->incomeStatement(Carbon::parse('2025-01-01'), Carbon::parse('2025-01-31'));
        $this->assertEquals(0.0, $empty['laba_bersih']);
        $this->assertTrue(collect($empty['sections'])->every(fn ($s) => $s['rows']->isEmpty()));
    }

    public function test_a_loss_is_negative_all_the_way_down(): void
    {
        $this->book('2026-09-05', '6110', 50000, 'debit');

        $result = $this->sept();

        $this->assertEquals(-50000.0, $result['laba_operasional']);
        $this->assertEquals(-50000.0, $result['laba_bersih']);
    }

    // ------------------------------------------------------------- ekspor Excel

    private function exportRows(array $result): array
    {
        return (new IncomeStatementExport($result + ['store_label' => 'Semua Toko']))->array();
    }

    private function find(array $rows, string $label): array
    {
        return collect($rows)->first(fn ($r) => ($r[0] ?? null) === $label) ?? $this->fail("Baris '{$label}' tidak ditemukan di ekspor.");
    }

    public function test_excel_export_has_the_waterfall_with_percent_of_revenue(): void
    {
        $this->fullMonth();
        $export = new IncomeStatementExport($this->sept() + ['store_label' => 'Semua Toko']);
        $rows = $export->array();

        $this->assertSame(['Laporan Laba Rugi'], $rows[0]);
        $this->assertSame(['Periode', '01 Sep 2026 - 30 Sep 2026'], $rows[1]);
        $this->assertSame(['Toko', 'Semua Toko'], $rows[2]);
        $this->assertSame(['Akun', 'Periode Ini', '% Pendapatan'], $rows[4]);

        $this->assertSame([900000.0, 100.0], array_slice($this->find($rows, 'Total Pendapatan'), 1, 2));
        $this->assertSame([600000.0, 66.7], array_slice($this->find($rows, 'Laba Kotor'), 1, 2));
        $this->assertSame([435000.0, 48.3], array_slice($this->find($rows, 'Laba Bersih'), 1, 2));
        $this->assertContains(['PENDAPATAN'], $rows);

        $contra = collect($rows)->first(fn ($r) => str_contains($r[0] ?? '', 'Retur & Potongan Penjualan'));
        $this->assertStringContainsString('(pengurang)', $contra[0]);
        $this->assertSame(-100000.0, $contra[1]);
    }

    public function test_excel_export_leaves_percent_blank_when_there_is_no_revenue(): void
    {
        $this->book('2026-09-05', '6110', 50000, 'debit');

        $row = $this->find($this->exportRows($this->sept()), 'Laba Bersih');

        $this->assertSame(-50000.0, $row[1]);
        $this->assertSame('', $row[2]);
    }

    public function test_excel_export_with_a_comparison_adds_previous_and_delta_columns(): void
    {
        $this->book('2026-08-05', '4100', 400000, 'credit');
        $this->book('2026-09-05', '4100', 500000, 'credit');
        $this->book('2026-09-06', '4200', 100000, 'credit');
        $current = $this->sept();
        $current['compare'] = $this->service->incomeStatement(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $current['compare_label'] = '01 Aug 2026 – 31 Aug 2026';

        $rows = $this->exportRows($current);

        $this->assertSame(['Pembanding', '01 Aug 2026 – 31 Aug 2026'], $rows[3]);
        $this->assertSame(['Akun', 'Periode Ini', '% Pendapatan', 'Pembanding', 'Selisih %'], $rows[5]);
        $this->assertSame([600000.0, 100.0, 400000.0, 50.0], array_slice($this->find($rows, 'Total Pendapatan'), 1, 4));

        $newAccount = collect($rows)->first(fn ($r) => str_contains($r[0] ?? '', 'Instalasi Kaca Film'));
        $this->assertSame([100000.0, 0.0, ''], [$newAccount[1], $newAccount[3], $newAccount[4]], 'Akun baru: pembanding 0, selisih % kosong.');
    }

    public function test_excel_export_groups_accounts_by_parent_with_subtotals_and_bolds_key_rows(): void
    {
        $this->book('2026-09-05', '4100', 800000, 'credit');
        $this->book('2026-09-06', '4200', 200000, 'credit');
        $export = new IncomeStatementExport($this->sept() + ['store_label' => 'Semua Toko']);
        $rows = $export->array();

        $sameParent = ChartOfAccount::whereIn('code', ['4100', '4200'])->pluck('parent_id')->unique()->count() === 1;
        if ($sameParent) {
            $parentName = ChartOfAccount::find($this->id('4100'))->parent->name;
            $this->assertSame(1000000.0, $this->find($rows, '  Subtotal ' . $parentName)[1]);
        }

        $styles = $export->styles(new Worksheet());
        $laba = collect($rows)->search(fn ($r) => ($r[0] ?? null) === 'Laba Bersih') + 1;
        $this->assertSame(['font' => ['bold' => true]], $styles[$laba]);
        $this->assertSame(['A' => 46, 'B' => 18, 'C' => 15], $export->columnWidths());
    }

    // ------------------------------------------------------------- halaman & PDF

    public function test_page_shows_figures_margins_and_negative_numbers_in_parentheses(): void
    {
        $this->fullMonth();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(IncomeStatementReport::class)
            ->assertSuccessful()
            ->assertSee('Laporan Laba Rugi')
            ->assertSee('Rp 900.000')
            ->assertSee('(Rp 100.000)')
            ->assertSee('Laba Kotor')
            ->assertSee('Rp 435.000')
            ->assertSee('48,3%')
            ->assertSee('(pengurang)');
    }

    public function test_page_shows_a_loss_in_parentheses_and_empty_sections(): void
    {
        $this->book('2026-09-05', '6110', 50000, 'debit');
        $this->actingAs($this->admin(), 'web');

        Livewire::test(IncomeStatementReport::class)->assertSee('(Rp 50.000)')->assertSee('Tidak ada transaksi.');
    }

    public function test_page_comparison_shows_the_percentage_change(): void
    {
        $this->book('2026-08-05', '4100', 400000, 'credit');
        $this->book('2026-09-05', '4100', 500000, 'credit');
        $this->actingAs($this->admin(), 'web');

        Livewire::test(IncomeStatementReport::class)->set('data.compare', 'prev_period')->assertSee('+25,0%')->assertSee('01 Aug 2026');
    }

    public function test_page_drill_down_link_carries_the_range_and_store(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->actingAs($this->admin(), 'web');

        $url = Livewire::test(IncomeStatementReport::class)->set('data.store_id', $store->id)->instance()->ledgerUrl($this->id('4100'));

        $this->assertStringContainsString('chart_of_account_id=' . $this->id('4100'), $url);
        $this->assertStringContainsString('store_id=' . $store->id, $url);
        $this->assertStringContainsString('from=2026-09-01', $url);
        $this->assertStringContainsString('to=2026-09-30', $url);
    }

    public function test_page_flags_draft_journals_in_the_range(): void
    {
        $this->book('2026-09-05', '4100', 100000, 'credit', null, true);
        $this->actingAs($this->admin(), 'web');

        $this->assertTrue(collect(Livewire::test(IncomeStatementReport::class)->instance()->getNotices())->contains(fn ($n) => $n['type'] === 'warning' && str_contains($n['text'], 'DRAFT')));
    }

    public function test_pdf_renders_with_and_without_a_comparison(): void
    {
        $this->book('2026-08-05', '4100', 400000, 'credit');
        $this->fullMonth();
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(IncomeStatementReport::class);
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        $page->set('data.compare', 'prev_period')->callAction('exportPdf')->assertHasNoActionErrors();
        $page->set('data.compare', 'prev_year')->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
