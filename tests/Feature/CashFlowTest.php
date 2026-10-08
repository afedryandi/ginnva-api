<?php

namespace Tests\Feature;

use App\Exports\CashFlowExport;
use App\Filament\Pages\CashFlowReport;
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
 * Laporan Arus Kas (metode langsung): klasifikasi operasional/investasi/pendanaan menurut akun lawan terbesar,
 * arah arus (masuk/keluar), transfer antar akun kas diabaikan, jurnal tanpa kas diabaikan, saldo awal/akhir dan
 * rekonsiliasi dengan saldo aktual, rincian per akun kas, peringatan klasifikasi, potongan toko, isi ekspor Excel
 * (pembanding, rincian per jurnal), PDF, dan tampilan halaman.
 */
class CashFlowTest extends TestCase
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

    /** @param array<int, array{0:string,1:string,2:float}> $legs [kode, sisi, nominal] */
    private function book(string $date, array $legs, ?Store $store = null, bool $post = true, array $header = []): JournalEntry
    {
        $svc = app(JournalEntryService::class);
        $entry = $svc->create(array_merge(['entry_date' => $date, 'store_id' => $store?->id, 'description' => 'Uji arus kas', 'reference_type' => 'manual'], $header), array_map(
            fn (array $leg) => ['chart_of_account_id' => $this->id($leg[0]), $leg[1] => $leg[2]],
            $legs
        ));

        return $post ? $svc->post($entry, null) : $entry;
    }

    /**
     * September 2026. Saldo kas awal 500.000 (modal Agustus).
     * Operasional +370.000, investasi -195.000, pendanaan +100.000 => bersih 275.000, akhir 775.000.
     */
    private function fixture(): void
    {
        $this->book('2026-08-15', [['1101', 'debit', 500000], ['3100', 'credit', 500000]]);
        $this->book('2026-09-02', [['1101', 'debit', 300000], ['4100', 'credit', 300000]]);
        $this->book('2026-09-03', [['1102', 'debit', 120000], ['4200', 'credit', 120000]]);
        $this->book('2026-09-05', [['6110', 'debit', 50000], ['1101', 'credit', 50000]]);
        $this->book('2026-09-08', [['1210', 'debit', 200000], ['1102', 'credit', 200000]]);
        $this->book('2026-09-10', [['1101', 'debit', 100000], ['3100', 'credit', 100000]]);
        $this->book('2026-09-12', [['1102', 'debit', 5000], ['7100', 'credit', 5000]]);
    }

    private function cash(string $from = '2026-09-01', string $to = '2026-09-30', ?int $storeId = null): array
    {
        return $this->service->cashFlowStatement(Carbon::parse($from), Carbon::parse($to), $storeId);
    }

    // ------------------------------------------------------------- klasifikasi & saldo

    public function test_cash_is_classified_by_activity_with_the_right_direction_and_reconciles(): void
    {
        $this->fixture();

        $cash = $this->cash();

        $this->assertEquals(370000.0, $cash['sections']['operasional']['total']);
        $this->assertEquals(-195000.0, $cash['sections']['investasi']['total'], 'Beli peralatan keluar, bunga masuk.');
        $this->assertEquals(100000.0, $cash['sections']['pendanaan']['total']);
        $this->assertEquals(500000.0, $cash['opening_cash']);
        $this->assertEquals(275000.0, $cash['net_change']);
        $this->assertEquals(775000.0, $cash['closing_cash']);
        $this->assertEquals(775000.0, $cash['closing_cash_actual']);
        $this->assertTrue($cash['is_reconciled']);
        $this->assertEmpty($cash['warnings']);
    }

    public function test_groups_are_labelled_by_counter_account_with_subtotals_and_journal_counts(): void
    {
        $this->fixture();
        $this->book('2026-09-20', [['1101', 'debit', 10000], ['4100', 'credit', 10000]]);

        $groups = $this->cash()['sections']['operasional']['groups'];

        $this->assertSame(['4100 — Pendapatan Jasa Instalasi PPF', '4200 — Pendapatan Jasa Instalasi Kaca Film', '6110 — ' . ChartOfAccount::where('code', '6110')->value('name')], $groups->pluck('label')->all());
        $this->assertEquals(310000.0, $groups[0]['total']);
        $this->assertCount(2, $groups[0]['rows']);
        $this->assertEquals(-50000.0, $groups[2]['total']);
    }

    public function test_the_largest_counter_line_decides_the_category_and_mixed_journals_are_flagged(): void
    {
        $this->book('2026-09-05', [['1101', 'debit', 100000], ['4100', 'credit', 70000], ['3100', 'credit', 30000]]);

        $cash = $this->cash();

        $this->assertEquals(100000.0, $cash['sections']['operasional']['total']);
        $this->assertEquals(0.0, $cash['sections']['pendanaan']['total']);
        $this->assertTrue(collect($cash['warnings'])->contains(fn ($w) => str_contains($w, 'mencampur kategori arus kas')));
        $this->assertTrue($cash['is_reconciled']);
    }

    public function test_transfers_between_cash_accounts_and_journals_without_cash_are_ignored(): void
    {
        $this->book('2026-09-05', [['1102', 'debit', 80000], ['1101', 'credit', 80000]]);   // setor ke bank
        $this->book('2026-09-06', [['1110', 'debit', 90000], ['4100', 'credit', 90000]]);   // penjualan kredit: piutang

        $cash = $this->cash();

        $this->assertEquals(0.0, $cash['net_change']);
        $this->assertTrue(collect($cash['sections'])->every(fn ($s) => $s['rows']->isEmpty()));
        $byCode = collect($cash['cash_accounts'])->keyBy(fn ($r) => $r['account']->code);
        $this->assertEquals(80000.0, $byCode['1102']['mutation'], 'Rincian per akun kas tetap mencatat perpindahan.');
        $this->assertEquals(-80000.0, $byCode['1101']['mutation']);
        $this->assertTrue($cash['is_reconciled']);
    }

    public function test_drafts_are_excluded_and_a_reversal_shows_as_the_opposite_flow(): void
    {
        $this->book('2026-09-02', [['1101', 'debit', 999], ['4100', 'credit', 999]], null, false);
        $original = $this->book('2026-09-03', [['1101', 'debit', 50000], ['4100', 'credit', 50000]]);
        app(JournalEntryService::class)->reverse($original, null, 'Batal', '2026-09-20');

        $cash = $this->cash();
        $rows = $cash['sections']['operasional']['rows'];

        $this->assertCount(2, $rows);
        $this->assertEquals([50000.0, -50000.0], $rows->pluck('amount')->all());
        $this->assertEquals(0.0, $cash['net_change']);
    }

    public function test_period_boundaries_are_inclusive_and_earlier_cash_becomes_the_opening_balance(): void
    {
        $this->book('2026-08-31', [['1101', 'debit', 1000], ['4100', 'credit', 1000]]);
        $this->book('2026-09-01', [['1101', 'debit', 10], ['4100', 'credit', 10]]);
        $this->book('2026-09-30', [['1101', 'debit', 100], ['4100', 'credit', 100]]);
        $this->book('2026-10-01', [['1101', 'debit', 100000], ['4100', 'credit', 100000]]);

        $cash = $this->cash();

        $this->assertEquals(1000.0, $cash['opening_cash']);
        $this->assertEquals(110.0, $cash['net_change']);
        $this->assertEquals(1110.0, $cash['closing_cash']);
    }

    public function test_an_empty_period_carries_the_opening_balance(): void
    {
        $this->book('2026-08-15', [['1101', 'debit', 500000], ['3100', 'credit', 500000]]);

        $cash = $this->cash();

        $this->assertEquals(500000.0, $cash['opening_cash']);
        $this->assertEquals(0.0, $cash['net_change']);
        $this->assertEquals(500000.0, $cash['closing_cash']);
        $this->assertTrue($cash['is_reconciled']);
        $this->assertCount(1, $cash['cash_accounts'], 'Hanya akun kas yang punya saldo.');
    }

    public function test_cash_accounts_breakdown_adds_up_to_the_closing_cash(): void
    {
        $this->fixture();

        $accounts = collect($this->cash()['cash_accounts'])->keyBy(fn ($r) => $r['account']->code);

        $this->assertEquals([500000.0, 350000.0, 850000.0], [$accounts['1101']['opening'], $accounts['1101']['mutation'], $accounts['1101']['closing']]);
        $this->assertEquals([0.0, -75000.0, -75000.0], [$accounts['1102']['opening'], $accounts['1102']['mutation'], $accounts['1102']['closing']]);
        $this->assertEquals(775000.0, $accounts->sum('closing'));
    }

    public function test_a_cash_only_unbalanced_entry_is_caught_by_the_reconciliation_check(): void
    {
        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => '2026-09-05', 'description' => 'Kas saja', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => $this->id('1101'), 'debit' => 1000, 'credit' => 0]);

        $cash = $this->cash();

        $this->assertFalse($cash['is_reconciled']);
        $this->assertEquals(0.0, $cash['closing_cash']);
        $this->assertEquals(1000.0, $cash['closing_cash_actual']);
    }

    public function test_store_and_head_office_slices_each_reconcile(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->book('2026-09-01', [['1101', 'debit', 5000000], ['3100', 'credit', 5000000]]);
        $this->book('2026-09-05', [['1101', 'debit', 200000], ['4100', 'credit', 200000]], $store);

        $mine = $this->cash('2026-09-01', '2026-09-30', $store->id);
        $pusat = $this->cash('2026-09-01', '2026-09-30', FinancialStatementService::COMPANY_WIDE);

        $this->assertEquals(200000.0, $mine['net_change']);
        $this->assertEquals(5000000.0, $pusat['net_change']);
        $this->assertEquals(5000000.0, $pusat['sections']['pendanaan']['total']);
        $this->assertTrue($mine['is_reconciled'] && $pusat['is_reconciled'] && $this->cash()['is_reconciled']);
    }

    // ------------------------------------------------------------- ekspor Excel

    private function find(array $rows, string $label): array
    {
        return collect($rows)->first(fn ($r) => ($r[0] ?? null) === $label) ?? $this->fail("Baris '{$label}' tidak ditemukan.");
    }

    private function exportInput(array $extra = []): array
    {
        return $this->cash() + ['from' => Carbon::parse('2026-09-01'), 'to' => Carbon::parse('2026-09-30'), 'store_label' => 'Semua Toko'] + $extra;
    }

    public function test_excel_export_has_sections_totals_reconciliation_and_the_cash_account_breakdown(): void
    {
        $this->fixture();
        $export = new CashFlowExport($this->exportInput());
        $rows = $export->array();

        $this->assertSame(['Laporan Arus Kas'], $rows[0]);
        $this->assertSame(['Periode', '01 Sep 2026 - 30 Sep 2026'], $rows[1]);
        $this->assertSame(['Toko', 'Semua Toko'], $rows[2]);
        $this->assertSame(500000.0, $this->find($rows, 'Saldo Kas Awal Periode')[1]);
        $this->assertSame(370000.0, $this->find($rows, 'Total Arus Kas dari Aktivitas Operasional')[1]);
        $this->assertSame(-195000.0, $this->find($rows, 'Total Arus Kas dari Aktivitas Investasi')[1]);
        $this->assertSame(100000.0, $this->find($rows, 'Total Arus Kas dari Aktivitas Pendanaan')[1]);
        $this->assertSame(275000.0, $this->find($rows, 'Kenaikan (Penurunan) Kas Bersih')[1]);
        $this->assertSame(775000.0, $this->find($rows, 'Saldo Kas Akhir Periode')[1]);
        $this->assertSame(300000.0, $this->find($rows, '  4100 — Pendapatan Jasa Instalasi PPF (1 jurnal)')[1]);
        $this->assertContains(['Sudah sesuai dengan saldo aktual akun kas (775.000).'], $rows);
        $this->assertSame(['Rincian per Akun Kas', 'Saldo Awal', 'Mutasi', 'Saldo Akhir'], $this->find($rows, 'Rincian per Akun Kas'));
        $this->assertSame([500000.0, 350000.0, 850000.0], array_slice($this->find($rows, '1101 — Kas di Tangan (per toko)'), 1, 3));
    }

    public function test_excel_export_lists_journal_details_only_when_asked(): void
    {
        $entry = $this->book('2026-09-02', [['1101', 'debit', 300000], ['4100', 'credit', 300000]]);

        $plain = (new CashFlowExport($this->exportInput(['show_details' => false])))->array();
        $detailed = (new CashFlowExport($this->exportInput(['show_details' => true])))->array();

        $label = '      02 Sep 2026 — Uji arus kas (' . $entry->entry_number . ')';
        $this->assertNotContains($label, array_column($plain, 0));
        $this->assertSame(300000.0, $this->find($detailed, $label)[1]);
    }

    public function test_excel_export_includes_classification_warnings(): void
    {
        $this->book('2026-09-05', [['1101', 'debit', 100000], ['4100', 'credit', 70000], ['3100', 'credit', 30000]]);

        $rows = (new CashFlowExport($this->exportInput()))->array();

        $this->assertContains(['Perlu diperiksa (klasifikasi arus kas):'], $rows);
        $this->assertTrue(collect($rows)->contains(fn ($r) => str_starts_with($r[0] ?? '', '  - Jurnal ')));
    }

    public function test_excel_export_with_a_comparison_has_previous_and_delta_columns(): void
    {
        $this->fixture();
        $input = $this->exportInput();
        $input['compare'] = $this->cash('2026-08-01', '2026-08-31');
        $input['compare_label'] = '01 Aug 2026 – 31 Aug 2026';
        $export = new CashFlowExport($input);
        $rows = $export->array();

        $this->assertSame(['Pembanding', '01 Aug 2026 – 31 Aug 2026'], $rows[3]);
        $this->assertSame(['Uraian', 'Periode Ini', 'Pembanding', 'Selisih %'], $rows[5]);
        $this->assertSame([500000.0, 0.0, ''], array_slice($this->find($rows, 'Saldo Kas Awal Periode'), 1, 3));
        $this->assertSame([100000.0, 500000.0, -80.0], array_slice($this->find($rows, 'Total Arus Kas dari Aktivitas Pendanaan'), 1, 3));
        $this->assertSame([300000.0, 0.0, ''], array_slice($this->find($rows, '  4100 — Pendapatan Jasa Instalasi PPF (1 jurnal)'), 1, 3), 'Jenis baru: pembanding 0.');
        $this->assertSame(['A' => 80, 'B' => 18, 'C' => 18, 'D' => 14], $export->columnWidths());
        $styles = $export->styles(new Worksheet());
        $closing = collect($rows)->search(fn ($r) => ($r[0] ?? null) === 'Saldo Kas Akhir Periode') + 1;
        $this->assertSame(['font' => ['bold' => true]], $styles[$closing]);
    }

    // ------------------------------------------------------------- halaman & PDF

    public function test_page_shows_the_flows_net_change_and_the_reconciled_banner(): void
    {
        $this->fixture();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CashFlowReport::class)
            ->assertSuccessful()
            ->assertSee('Arus Kas dari Aktivitas Operasional')
            ->assertSee('Rp 370.000')
            ->assertSee('(Rp 195.000)')
            ->assertSee('Rp 275.000')
            ->assertSee('Rp 775.000')
            ->assertSee('✓ Sudah sesuai dengan saldo aktual akun kas')
            ->assertSee('Rincian per Akun Kas')
            ->assertSee('(1 jurnal)');
    }

    public function test_page_toggle_reveals_each_journal_with_a_link(): void
    {
        $entry = $this->book('2026-09-02', [['1101', 'debit', 300000], ['4100', 'credit', 300000]]);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CashFlowReport::class)->assertDontSee($entry->entry_number)->set('data.show_details', true)->assertSee($entry->entry_number);
    }

    public function test_page_warns_when_the_check_fails_and_about_classification(): void
    {
        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => '2026-09-05', 'description' => 'Kas saja', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => $this->id('1101'), 'debit' => 1000, 'credit' => 0]);
        $this->book('2026-09-06', [['1101', 'debit', 100000], ['4100', 'credit', 70000], ['3100', 'credit', 30000]]);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CashFlowReport::class)->assertSee('✗ Saldo akhir hasil perhitungan')->assertSee('Perlu diperiksa (klasifikasi arus kas)');
    }

    public function test_page_empty_state_and_draft_notice(): void
    {
        $this->book('2026-09-05', [['1101', 'debit', 100], ['4100', 'credit', 100]], null, false);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(CashFlowReport::class)->assertSee('Tidak ada arus kas di kategori ini.');

        $this->assertTrue(collect($page->instance()->getNotices())->contains(fn ($n) => $n['type'] === 'warning' && str_contains($n['text'], 'DRAFT')));
    }

    public function test_page_comparison_shows_the_previous_period_and_percentage(): void
    {
        $this->fixture();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CashFlowReport::class)->set('data.compare', 'prev_period')->assertSee('01 Aug 2026')->assertSee('Rp 500.000');
    }

    public function test_pdf_and_excel_work_with_details_comparison_warnings_and_an_empty_period(): void
    {
        $this->fixture();
        $this->book('2026-09-14', [['1101', 'debit', 100000], ['4100', 'credit', 70000], ['3100', 'credit', 30000]]);
        $this->actingAs($this->admin(), 'web');
        Excel::fake();

        $page = Livewire::test(CashFlowReport::class);
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        $page->set('data.show_details', true)->set('data.compare', 'prev_period')->callAction('exportPdf')->assertHasNoActionErrors();
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->set('data.from', '2026-01-01')->set('data.to', '2026-01-31')->set('data.compare', null)->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-arus-kas-20260930-100000.xlsx');
    }
}
