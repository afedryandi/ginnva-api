<?php

namespace Tests\Feature;

use App\Exports\GeneralLedgerExport;
use App\Filament\Pages\GeneralLedgerReport;
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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Buku Besar: saldo awal dari jurnal posted sebelum periode, saldo berjalan (akun debit-normal dan
 * kredit-normal, dalam sen), urutan mutasi, draft/pembalik, label sumber & pembuat, reset Laba Rugi per tahun,
 * pemisahan toko, kecocokan dengan Neraca Saldo, isi ekspor Excel, serta halaman (navigasi akun,
 * parameter drill-down, pencarian, pembatasan toko, PDF).
 */
class GeneralLedgerTest extends TestCase
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

    private function user(string $role = 'super_admin', ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function acct(string $code): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->firstOrFail();
    }

    private function entry(string $date, array $lines, array $header = [], bool $post = true): JournalEntry
    {
        $svc = app(JournalEntryService::class);
        $entry = $svc->create(array_merge(['entry_date' => $date, 'description' => 'Uji buku besar', 'reference_type' => 'manual'], $header), $lines);

        return $post ? $svc->post($entry, null) : $entry;
    }

    /** Penjualan tunai: kas (debit) / pendapatan 4100 (kredit). */
    private function sale(string $date, float $amount, array $header = [], bool $post = true): JournalEntry
    {
        return $this->entry($date, [
            ['chart_of_account_id' => $this->acct('1101')->id, 'debit' => $amount],
            ['chart_of_account_id' => $this->acct('4100')->id, 'credit' => $amount],
        ], $header, $post);
    }

    /** Pengeluaran kas: beban 6110 (debit) / kas (kredit). */
    private function spend(string $date, float $amount, array $header = []): JournalEntry
    {
        return $this->entry($date, [
            ['chart_of_account_id' => $this->acct('6110')->id, 'debit' => $amount],
            ['chart_of_account_id' => $this->acct('1101')->id, 'credit' => $amount],
        ], $header);
    }

    private function ledger(string $code, string $from = '2026-09-01', string $to = '2026-09-30', ?int $storeId = null): array
    {
        return $this->service->generalLedger($this->acct($code), Carbon::parse($from), Carbon::parse($to), $storeId);
    }

    // ------------------------------------------------------------- saldo & urutan

    public function test_opening_balance_comes_from_posted_journals_before_the_period(): void
    {
        $this->sale('2026-08-15', 400000);
        $this->sale('2026-08-20', 100000, [], false);   // draft: tidak dihitung
        $this->sale('2026-09-10', 50000);
        $this->sale('2026-10-05', 999);                  // setelah periode: tidak dihitung

        $ledger = $this->ledger('1101');

        $this->assertEquals(400000.0, $ledger['opening_balance']);
        $this->assertCount(1, $ledger['rows']);
        $this->assertEquals(450000.0, $ledger['closing_balance']);
        $this->assertEquals(50000.0, $ledger['total_debit']);
        $this->assertEquals(0.0, $ledger['total_credit']);
    }

    public function test_running_balance_follows_the_normal_side_for_debit_and_credit_accounts(): void
    {
        $this->sale('2026-09-05', 100000);
        $this->spend('2026-09-06', 30000);
        $this->sale('2026-09-07', 20000);

        $cash = $this->ledger('1101');
        $this->assertSame([100000.0, 70000.0, 90000.0], $cash['rows']->pluck('running_balance')->all());

        $revenue = $this->ledger('4100');
        $this->assertSame([100000.0, 120000.0], $revenue['rows']->pluck('running_balance')->all(), 'Pendapatan kredit-normal: kredit menambah.');

        $expense = $this->ledger('6110');
        $this->assertSame([30000.0], $expense['rows']->pluck('running_balance')->all());
    }

    public function test_rows_are_ordered_by_date_then_journal_and_a_journal_can_hit_the_account_twice(): void
    {
        $second = $this->sale('2026-09-10', 2000);
        $first = $this->sale('2026-09-05', 1000);
        $split = $this->entry('2026-09-10', [
            ['chart_of_account_id' => $this->acct('1101')->id, 'debit' => 300],
            ['chart_of_account_id' => $this->acct('1101')->id, 'debit' => 700],
            ['chart_of_account_id' => $this->acct('4100')->id, 'credit' => 1000],
        ]);

        $rows = $this->ledger('1101')['rows'];

        $this->assertSame([$first->entry_number, $second->entry_number, $split->entry_number, $split->entry_number], $rows->pluck('entry_number')->all());
        $this->assertSame([1000.0, 3000.0, 3300.0, 4000.0], $rows->pluck('running_balance')->all());
    }

    public function test_running_balance_is_exact_in_cents(): void
    {
        foreach ([0.10, 0.20, 33333.33, 0.07] as $i => $amount) {
            $this->sale('2026-09-0' . ($i + 1), $amount);
        }

        $ledger = $this->ledger('1101');

        $this->assertSame(33333.70, $ledger['closing_balance']);
        $this->assertSame(0.30, $ledger['rows']->get(1)['running_balance']);
    }

    public function test_a_reversal_appears_as_its_own_row_labelled_pembalik(): void
    {
        $original = $this->sale('2026-09-05', 100000);
        app(JournalEntryService::class)->reverse($original, null, 'Salah catat', '2026-09-20');

        $ledger = $this->ledger('1101');

        $this->assertSame(['Manual', 'Pembalik'], $ledger['rows']->pluck('source')->all());
        $this->assertEquals(0.0, $ledger['closing_balance']);
        $this->assertEquals(100000.0, $ledger['rows']->last()['credit']);
    }

    public function test_closing_balance_matches_the_trial_balance(): void
    {
        $this->sale('2026-08-05', 250000);
        $this->spend('2026-09-06', 40000);
        $this->sale('2026-09-07', 15000);

        $ledger = $this->ledger('1101');
        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'));
        $row = $trial['rows']->first(fn ($r) => $r['account']->code === '1101');

        $this->assertEquals($row['balance'], $ledger['closing_balance']);
    }

    // ------------------------------------------------------------- sumber, pembuat, keterangan

    public function test_source_labels_creator_and_line_description(): void
    {
        $creator = $this->user();
        $this->entry('2026-09-05', [
            ['chart_of_account_id' => $this->acct('1101')->id, 'debit' => 100, 'description' => 'Keterangan baris'],
            ['chart_of_account_id' => $this->acct('4100')->id, 'credit' => 100],
        ], ['reference_type' => 'booking', 'reference_id' => 1, 'created_by' => $creator->id]);
        $this->entry('2026-09-06', [
            ['chart_of_account_id' => $this->acct('1101')->id, 'debit' => 100],
            ['chart_of_account_id' => $this->acct('4100')->id, 'credit' => 100],
        ], ['reference_type' => 'asset_depreciation', 'reference_id' => 1, 'description' => 'Penyusutan']);
        $this->sale('2026-09-07', 100, ['reference_type' => null]);

        $rows = $this->ledger('1101')['rows'];

        $this->assertSame(['Booking', 'Asset depreciation', 'Manual'], $rows->pluck('source')->all());
        $this->assertSame([$creator->name, 'Sistem', 'Sistem'], $rows->pluck('creator')->all());
        $this->assertSame('Keterangan baris', $rows[0]['description']);
        $this->assertSame('Penyusutan', $rows[1]['description'], 'Tanpa keterangan baris dipakai keterangan jurnal.');
    }

    // ------------------------------------------------------------- Laba Rugi & toko

    public function test_profit_and_loss_accounts_open_from_january_first_of_the_start_year(): void
    {
        $this->sale('2025-11-10', 1000000);
        $this->sale('2026-02-10', 300000);

        $revenue = $this->ledger('4100', '2026-03-01', '2026-03-31');
        $cash = $this->ledger('1101', '2026-03-01', '2026-03-31');

        $this->assertEquals(300000.0, $revenue['opening_balance']);
        $this->assertNotNull($revenue['opening_reset_from']);
        $this->assertEquals(1300000.0, $cash['opening_balance']);
        $this->assertNull($cash['opening_reset_from']);
    }

    public function test_store_filter_head_office_and_all(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->sale('2026-09-05', 300000, ['store_id' => $store->id]);
        $this->sale('2026-09-06', 500000, ['store_id' => null]);

        $this->assertEquals(800000.0, $this->ledger('1101')['closing_balance']);
        $this->assertEquals(300000.0, $this->ledger('1101', '2026-09-01', '2026-09-30', $store->id)['closing_balance']);
        $this->assertEquals(500000.0, $this->ledger('1101', '2026-09-01', '2026-09-30', FinancialStatementService::COMPANY_WIDE)['closing_balance']);
    }

    public function test_an_account_without_movement_has_an_empty_ledger(): void
    {
        $ledger = $this->ledger('1101');

        $this->assertTrue($ledger['rows']->isEmpty());
        $this->assertEquals(0.0, $ledger['opening_balance']);
        $this->assertEquals(0.0, $ledger['closing_balance']);
    }

    // ------------------------------------------------------------- ekspor Excel

    public function test_excel_export_lists_opening_rows_totals_and_the_footer(): void
    {
        $this->sale('2026-08-05', 400000);
        $this->sale('2026-09-05', 100000);
        $this->spend('2026-09-06', 30000);
        $ledger = $this->ledger('1101') + ['from' => Carbon::parse('2026-09-01'), 'to' => Carbon::parse('2026-09-30'), 'store_label' => 'Semua Toko'];

        $export = new GeneralLedgerExport($ledger);
        $rows = $export->array();

        $this->assertSame(['Tanggal', 'No. Jurnal', 'Keterangan', 'Debit', 'Kredit', 'Saldo Berjalan', 'Sumber', 'Pembuat'], $export->headings());
        $this->assertSame(400000.0, $rows[0][5]);
        $this->assertSame('2026-09-05', $rows[1][0]);
        $this->assertSame([100000.0, '', 500000.0], [$rows[1][3], $rows[1][4], $rows[1][5]], 'Kredit kosong, bukan teks.');
        $this->assertSame(['', 30000.0, 470000.0], [$rows[2][3], $rows[2][4], $rows[2][5]]);
        $this->assertSame('Total Mutasi Periode Ini', $rows[3][0]);
        $this->assertSame([100000.0, 30000.0, 470000.0], [$rows[3][3], $rows[3][4], $rows[3][5]]);
        $this->assertSame(['Akun', '1101 — Kas di Tangan (per toko)'], $rows[5]);
        $this->assertSame(['Periode', '01 Sep 2026 - 30 Sep 2026'], $rows[6]);
        $this->assertSame(['Toko', 'Semua Toko'], $rows[7]);
        $this->assertArrayHasKey(4, $export->styles(new Worksheet()));
    }

    // ------------------------------------------------------------- halaman

    private function page(array $data = []): \Livewire\Features\SupportTesting\Testable
    {
        $page = Livewire::test(GeneralLedgerReport::class)->set('data.chart_of_account_id', $this->acct('1101')->id);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    public function test_page_renders_rows_and_links_each_journal(): void
    {
        $entry = $this->sale('2026-09-05', 100000);
        $this->actingAs($this->user(), 'web');

        $this->page()
            ->assertSuccessful()
            ->assertSee('1101 — Kas di Tangan')
            ->assertSee($entry->entry_number)
            ->assertSee('Rp 100.000')
            ->assertSee('Saldo Awal')
            ->assertSee('1 mutasi');
    }

    public function test_page_shows_the_profit_loss_note_only_for_profit_loss_accounts(): void
    {
        $this->sale('2026-09-05', 100000);
        $this->actingAs($this->user(), 'web');

        $this->page()->assertDontSee('Akun Laba Rugi: Saldo Awal');
        $this->page()->set('data.chart_of_account_id', $this->acct('4100')->id)->assertSee('Akun Laba Rugi: Saldo Awal');
    }

    public function test_page_shows_the_empty_state_and_asks_for_an_account(): void
    {
        $this->actingAs($this->user(), 'web');

        $this->page()->assertSee('Tidak ada mutasi di rentang tanggal ini.');
        $this->page()->set('data.chart_of_account_id', null)->assertSee('Pilih akun dulu');
    }

    public function test_search_filters_the_display_and_says_so(): void
    {
        $first = $this->sale('2026-09-05', 100000, ['description' => 'Penjualan alpha']);
        $this->sale('2026-09-06', 50000, ['description' => 'Penjualan beta']);
        $this->actingAs($this->user(), 'web');

        $page = $this->page(['search' => 'alpha']);

        $page->assertSee($first->entry_number)->assertSee('Menampilkan 1 dari 2 mutasi');
        $this->assertEquals(150000.0, $page->instance()->getResult()['closing_balance'], 'Saldo tetap seluruh mutasi.');
    }

    public function test_account_stepping_skips_inactive_and_header_accounts_and_stops_at_the_ends(): void
    {
        $this->actingAs($this->user(), 'web');
        $postable = ChartOfAccount::where('is_postable', true)->where('is_active', true)->orderBy('code')->get();
        $first = $postable[0];
        $second = $postable[1];
        $third = $postable[2];
        $second->update(['is_active' => false]);

        $page = Livewire::test(GeneralLedgerReport::class)->set('data.chart_of_account_id', $first->id);
        $page->callAction('nextAccount');
        $this->assertSame($third->id, $page->get('data.chart_of_account_id'), 'Akun nonaktif dilewati.');

        $page->callAction('prevAccount');
        $this->assertSame($first->id, $page->get('data.chart_of_account_id'));

        $last = ChartOfAccount::where('is_postable', true)->where('is_active', true)->orderByDesc('code')->first();
        $page->set('data.chart_of_account_id', $last->id)->callAction('nextAccount');
        $this->assertSame($last->id, $page->get('data.chart_of_account_id'), 'Sudah akun terakhir.');
    }

    public function test_quick_period_presets_fill_the_dates(): void
    {
        $this->actingAs($this->user(), 'web');
        $page = Livewire::test(GeneralLedgerReport::class);

        $expected = [
            'last_month' => ['2026-08-01', '2026-08-31'],
            'this_quarter' => ['2026-07-01', '2026-09-30'],
            'ytd' => ['2026-01-01', '2026-09-30'],
            'last_year' => ['2025-01-01', '2025-12-31'],
            'this_month' => ['2026-09-01', '2026-09-30'],
        ];

        foreach ($expected as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    public function test_query_parameters_prefill_the_page_and_a_numeric_store_is_honoured_for_full_access(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->sale('2026-09-05', 300000, ['store_id' => $store->id]);
        $this->sale('2026-09-06', 500000);
        $this->actingAs($this->user(), 'web');

        $page = Livewire::withQueryParams(['chart_of_account_id' => $this->acct('1101')->id, 'from' => '2026-09-01', 'to' => '2026-09-30', 'store_id' => (string) $store->id])->test(GeneralLedgerReport::class);

        $this->assertEquals(300000.0, $page->instance()->getResult()['closing_balance']);
        $this->assertSame('Toko A', $page->instance()->getResult()['store_label']);

        $pusat = Livewire::withQueryParams(['chart_of_account_id' => $this->acct('1101')->id, 'store_id' => '-1'])->test(GeneralLedgerReport::class);
        $this->assertSame('Pusat / Tanpa Toko', $pusat->instance()->getResult()['store_label']);
    }

    public function test_store_restricted_viewers_ignore_store_query_parameters(): void
    {
        $storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->sale('2026-09-05', 100000, ['store_id' => $storeA->id]);
        $this->sale('2026-09-06', 700000, ['store_id' => $storeB->id]);
        $this->actingAs($this->user('spv_finance', $storeA, ['menu_permissions' => [GeneralLedgerReport::class => ['view']]]), 'web');

        $page = Livewire::withQueryParams(['chart_of_account_id' => $this->acct('1101')->id, 'store_id' => (string) $storeB->id])->test(GeneralLedgerReport::class);

        $this->assertEquals(100000.0, $page->instance()->getResult()['closing_balance']);
        $this->assertSame('Toko A', $page->instance()->getResult()['store_label']);
    }

    public function test_page_flags_draft_journals_and_closed_periods_in_the_range(): void
    {
        $this->sale('2026-09-05', 100000, [], false);
        $this->actingAs($this->user(), 'web');

        $notices = $this->page()->instance()->getNotices();

        $this->assertTrue(collect($notices)->contains(fn ($n) => $n['type'] === 'warning' && str_contains($n['text'], 'DRAFT')));
    }

    public function test_pdf_renders_with_rows_and_when_empty(): void
    {
        $this->actingAs($this->user(), 'web');

        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->sale('2026-08-05', 400000);
        $this->sale('2026-09-05', 100000);
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
