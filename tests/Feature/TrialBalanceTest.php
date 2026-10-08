<?php

namespace Tests\Feature;

use App\Exports\TrialBalanceExport;
use App\Filament\Pages\TrialBalanceReport;
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
 * Neraca Saldo: hanya jurnal posted (draft keluar, jurnal pembalik menetralkan), seimbang pada nominal
 * desimal, potongan toko/pusat, aturan reset Laba Rugi per tahun termasuk saat "Dari Tanggal" jatuh di
 * tahun sebelumnya (saldo awal + mutasi = saldo akhir, total mutasi debit = kredit), isi ekspor Excel,
 * PDF, dan tampilan halaman.
 */
class TrialBalanceTest extends TestCase
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

    private function entry(string $date, array $lines, ?Store $store = null, bool $post = true): JournalEntry
    {
        $svc = app(JournalEntryService::class);
        $entry = $svc->create(['entry_date' => $date, 'store_id' => $store?->id, 'description' => 'Uji neraca saldo', 'reference_type' => 'manual'], $lines);

        return $post ? $svc->post($entry, null) : $entry;
    }

    private function sale(string $date, float $amount, ?Store $store = null): JournalEntry
    {
        return $this->entry($date, [
            ['chart_of_account_id' => $this->id('1101'), 'debit' => $amount],
            ['chart_of_account_id' => $this->id('4100'), 'credit' => $amount],
        ], $store);
    }

    private function row(array $trial, string $code): ?array
    {
        return $trial['rows']->first(fn ($r) => $r['account']->code === $code);
    }

    // ------------------------------------------------------------- angka

    public function test_drafts_are_left_out_and_a_reversal_nets_an_account_to_zero(): void
    {
        $original = $this->sale('2026-09-05', 100000);
        $this->entry('2026-09-06', [
            ['chart_of_account_id' => $this->id('1101'), 'debit' => 5000],
            ['chart_of_account_id' => $this->id('4100'), 'credit' => 5000],
        ], null, false);

        $before = $this->service->trialBalance(Carbon::parse('2026-09-30'));
        $this->assertEquals(100000.0, $this->row($before, '1101')['balance'], 'Draft tidak ikut.');

        app(JournalEntryService::class)->reverse($original, null, 'Salah catat', '2026-09-20');
        $after = $this->service->trialBalance(Carbon::parse('2026-09-30'));

        $this->assertEquals(0.0, $this->row($after, '1101')['balance']);
        $this->assertEquals(100000.0, $this->row($after, '1101')['debit']);
        $this->assertEquals(100000.0, $this->row($after, '1101')['credit']);
        $this->assertTrue($after['is_balanced']);
    }

    public function test_each_balance_follows_the_normal_side_of_its_account(): void
    {
        $this->entry('2026-09-01', [
            ['chart_of_account_id' => $this->id('1101'), 'debit' => 1000000],
            ['chart_of_account_id' => $this->id('3100'), 'credit' => 800000],
            ['chart_of_account_id' => $this->id('2110'), 'credit' => 200000],
        ]);
        $this->entry('2026-09-02', [
            ['chart_of_account_id' => $this->id('6110'), 'debit' => 50000],
            ['chart_of_account_id' => $this->id('1101'), 'credit' => 50000],
        ]);

        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'), null, null, true);

        $this->assertEquals(950000.0, $this->row($trial, '1101')['balance']);
        $this->assertEquals(800000.0, $this->row($trial, '3100')['balance']);
        $this->assertEquals(200000.0, $this->row($trial, '2110')['balance']);
        $this->assertEquals(50000.0, $this->row($trial, '6110')['balance']);
        $this->assertTrue($trial['is_balanced']);
        $this->assertSame(['1101', '2110', '3100', '6110'], $trial['rows']->map(fn ($r) => $r['account']->code)->all(), 'Urut kode akun.');
    }

    public function test_decimal_amounts_stay_balanced(): void
    {
        foreach ([0.10, 0.20, 33333.33, 0.07] as $i => $amount) {
            $this->sale('2026-09-0' . ($i + 1), $amount);
        }

        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'));

        $this->assertTrue($trial['is_balanced']);
        $this->assertEquals(33333.70, round($trial['total_debit'], 2));
    }

    public function test_a_posted_but_unbalanced_entry_is_flagged(): void
    {
        $entry = JournalEntry::create(['entry_number' => 'JE-X-1', 'entry_date' => '2026-09-05', 'description' => 'Miring', 'status' => 'posted']);
        $entry->lines()->create(['chart_of_account_id' => $this->id('1101'), 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['chart_of_account_id' => $this->id('4100'), 'debit' => 0, 'credit' => 900]);

        $this->assertFalse($this->service->trialBalance(Carbon::parse('2026-09-30'))['is_balanced']);

        $this->actingAs($this->admin(), 'web');
        Livewire::test(TrialBalanceReport::class)->assertSee('Total debit dan kredit tidak sama');
    }

    public function test_store_and_head_office_slices_are_each_balanced_and_add_up(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->sale('2026-09-05', 300000, $store);
        $this->sale('2026-09-06', 500000, null);

        $all = $this->service->trialBalance(Carbon::parse('2026-09-30'));
        $mine = $this->service->trialBalance(Carbon::parse('2026-09-30'), $store->id);
        $pusat = $this->service->trialBalance(Carbon::parse('2026-09-30'), FinancialStatementService::COMPANY_WIDE);

        $this->assertEquals(300000.0, $this->row($mine, '1101')['balance']);
        $this->assertEquals(500000.0, $this->row($pusat, '1101')['balance']);
        $this->assertEquals(800000.0, $this->row($all, '1101')['balance']);
        $this->assertTrue($mine['is_balanced'] && $pusat['is_balanced'] && $all['is_balanced']);
    }

    public function test_an_empty_ledger_gives_an_empty_balanced_result(): void
    {
        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'), null, null, true);

        $this->assertTrue($trial['rows']->isEmpty());
        $this->assertTrue($trial['is_balanced']);
        $this->assertEquals(0.0, $trial['prior_profit']);
    }

    // ------------------------------------------------------------- reset Laba Rugi per tahun

    public function test_a_start_date_in_the_previous_year_keeps_opening_plus_movement_equal_to_closing(): void
    {
        $this->sale('2025-06-10', 1000000);   // sebelum "dari": jadi saldo awal
        $this->sale('2025-11-10', 500000);    // tahun lalu, setelah "dari": mutasi periode
        $this->sale('2026-02-10', 300000);    // tahun berjalan

        $trial = $this->service->trialBalance(Carbon::parse('2026-03-31'), null, Carbon::parse('2025-10-01'), true);

        $retained = $this->row($trial, '3200*');
        $this->assertEquals(1500000.0, $retained['balance']);
        $this->assertEquals(1000000.0, $retained['opening_balance'], 'Laba sebelum 1 Okt 2025 = saldo awal.');
        $this->assertEquals(500000.0, $retained['period_credit'], 'Laba 1 Okt-31 Des 2025 = mutasi periode.');

        foreach ($trial['rows'] as $row) {
            $movement = $row['account']->isDebitNormal() ? $row['period_debit'] - $row['period_credit'] : $row['period_credit'] - $row['period_debit'];
            $this->assertEqualsWithDelta($row['balance'], $row['opening_balance'] + $movement, 0.005, 'Saldo awal + mutasi = saldo akhir untuk ' . $row['account']->code);
        }
        $this->assertEqualsWithDelta($trial['rows']->sum('period_debit'), $trial['rows']->sum('period_credit'), 0.005, 'Total mutasi debit = kredit.');
    }

    public function test_a_start_date_inside_the_current_year_treats_all_prior_profit_as_opening(): void
    {
        $this->sale('2025-06-10', 1000000);
        $this->sale('2026-02-10', 300000);
        $this->sale('2026-03-10', 100000);

        $trial = $this->service->trialBalance(Carbon::parse('2026-03-31'), null, Carbon::parse('2026-03-01'), true);

        $retained = $this->row($trial, '3200*');
        $this->assertEquals(1000000.0, $retained['opening_balance']);
        $this->assertEquals(0.0, $retained['period_credit']);
        $this->assertEquals(300000.0, $this->row($trial, '4100')['opening_balance']);
        $this->assertEquals(100000.0, $this->row($trial, '4100')['period_credit']);
        $this->assertEqualsWithDelta($trial['rows']->sum('period_debit'), $trial['rows']->sum('period_credit'), 0.005);
    }

    public function test_a_loss_in_the_previous_year_is_shown_as_a_debit_retained_row(): void
    {
        $this->entry('2025-08-10', [
            ['chart_of_account_id' => $this->id('6110'), 'debit' => 400000],
            ['chart_of_account_id' => $this->id('1101'), 'credit' => 400000],
        ]);

        $trial = $this->service->trialBalance(Carbon::parse('2026-03-31'), null, null, true);
        $retained = $this->row($trial, '3200*');

        $this->assertEquals(-400000.0, $retained['balance']);
        $this->assertEquals(400000.0, $retained['debit']);
        $this->assertTrue($trial['is_balanced']);
    }

    // ------------------------------------------------------------- ekspor

    public function test_excel_export_without_a_period_lists_accounts_and_a_bold_total_row(): void
    {
        $this->sale('2026-09-05', 250000);
        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'), null, null, true);
        $export = new TrialBalanceExport($trial);

        $this->assertSame(['Kode', 'Nama Akun', 'Tipe', 'Debit', 'Kredit', 'Saldo'], $export->headings());
        $rows = $export->array();
        $this->assertSame('1101', $rows[0][0]);
        $this->assertSame('Aset', $rows[0][2]);
        $this->assertSame(250000.0, $rows[0][3]);
        $this->assertSame(250000.0, $rows[0][5]);
        $total = end($rows);
        $this->assertSame('Total', $total[1]);
        $this->assertSame(250000.0, $total[3]);
        $this->assertSame(250000.0, $total[4]);
        $this->assertArrayHasKey(count($trial['rows']) + 2, $export->styles(new Worksheet()));
    }

    public function test_excel_export_with_a_period_has_opening_movement_and_closing_columns(): void
    {
        $this->sale('2026-08-10', 400000);
        $this->sale('2026-09-10', 100000);
        $trial = $this->service->trialBalance(Carbon::parse('2026-09-30'), null, Carbon::parse('2026-09-01'), true);
        $export = new TrialBalanceExport($trial);

        $this->assertSame(['Kode', 'Nama Akun', 'Tipe', 'Saldo Awal', 'Mutasi Debit', 'Mutasi Kredit', 'Saldo Akhir'], $export->headings());
        $cash = collect($export->array())->first(fn ($r) => $r[0] === '1101');
        $this->assertSame([400000.0, 100000.0, 0.0, 500000.0], array_slice($cash, 3));
        $total = collect($export->array())->first(fn ($r) => $r[1] === 'Total');
        $this->assertSame(100000.0, $total[4]);
        $this->assertSame(100000.0, $total[5]);
    }

    public function test_contra_accounts_are_labelled_in_the_export(): void
    {
        $contra = ChartOfAccount::where('is_contra', true)->where('is_postable', true)->first();
        if (! $contra) {
            $this->markTestSkipped('Seeder tidak punya akun kontra yang bisa diposting.');
        }
        $this->entry('2026-09-05', [
            ['chart_of_account_id' => $this->id('1101'), 'debit' => 1000],
            ['chart_of_account_id' => $contra->id, 'credit' => 1000],
        ]);

        $rows = (new TrialBalanceExport($this->service->trialBalance(Carbon::parse('2026-09-30'))))->array();

        $this->assertStringContainsString('(pengurang)', collect($rows)->first(fn ($r) => $r[0] === $contra->code)[1]);
    }

    // ------------------------------------------------------------- halaman

    public function test_page_renders_groups_subtotals_and_the_balanced_banner(): void
    {
        $this->sale('2026-09-05', 100000);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(TrialBalanceReport::class)
            ->assertSuccessful()
            ->assertSee('Subtotal Aset')
            ->assertSee('Kas di Tangan')
            ->assertSee('Seimbang')
            ->assertSee('Total (seluruh akun)');
    }

    public function test_page_shows_an_empty_state_without_posted_journals(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(TrialBalanceReport::class)->assertSee('Belum ada jurnal posted');
    }

    public function test_hide_zero_removes_netted_accounts_from_the_display_but_not_from_the_totals(): void
    {
        $original = $this->sale('2026-09-05', 100000);
        app(JournalEntryService::class)->reverse($original, null, 'Batal', '2026-09-20');
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(TrialBalanceReport::class);
        $result = $page->instance()->getResult();
        $this->assertSame(['aset', 'pendapatan'], $page->instance()->getGroupedRows($result)->keys()->all(), 'Akun yang saldonya habis tetap tampil bila filter mati.');

        $page->set('data.hide_zero', true);
        $this->assertTrue($page->instance()->getGroupedRows($result)->isEmpty(), 'Semua akun bersaldo nol disembunyikan.');

        $this->assertEquals(200000.0, $result['total_debit'], 'Total tetap seluruh akun, termasuk jurnal pembalik.');
        $this->assertEquals(200000.0, $result['total_credit']);
    }

    public function test_hide_zero_keeps_accounts_with_an_opening_balance_in_period_mode(): void
    {
        $original = $this->sale('2026-08-05', 100000);
        app(JournalEntryService::class)->reverse($original, null, 'Batal', '2026-09-20');
        // Beban yang lahir dan habis seluruhnya di bulan periode: tanpa saldo awal, saldo akhir nol.
        $expense = $this->entry('2026-09-05', [
            ['chart_of_account_id' => $this->id('6110'), 'debit' => 5000],
            ['chart_of_account_id' => $this->id('1101'), 'credit' => 5000],
        ]);
        app(JournalEntryService::class)->reverse($expense, null, 'Batal', '2026-09-21');
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(TrialBalanceReport::class)->set('data.from', '2026-09-01');
        $this->assertSame(['aset', 'pendapatan', 'beban_operasional'], $page->instance()->getGroupedRows($page->instance()->getResult())->keys()->all());

        $page->set('data.hide_zero', true);
        $this->assertSame(['aset', 'pendapatan'], $page->instance()->getGroupedRows($page->instance()->getResult())->keys()->all(), 'Kas dan pendapatan punya saldo awal 100.000 sehingga tetap tampil; beban 6110 (awal 0, akhir 0) disembunyikan.');
    }

    public function test_page_applies_the_period_and_the_store_choice(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->sale('2026-08-05', 400000, $store);
        $this->sale('2026-09-05', 100000, $store);
        $this->sale('2026-09-06', 9000, null);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(TrialBalanceReport::class)->set('data.store_id', $store->id)->set('data.from', '2026-09-01');
        $result = $page->instance()->getResult();

        $this->assertTrue($result['has_period']);
        $this->assertEquals(400000.0, $this->row($result, '1101')['opening_balance']);
        $this->assertEquals(500000.0, $this->row($result, '1101')['balance']);
    }

    public function test_pdf_renders_in_both_modes(): void
    {
        $this->sale('2026-08-10', 400000);
        $this->sale('2026-09-10', 100000);
        $this->actingAs($this->admin(), 'web');
        Excel::fake();

        $page = Livewire::test(TrialBalanceReport::class);
        $page->callAction('exportPdf')->assertHasNoActionErrors();
        $page->set('data.from', '2026-09-01')->callAction('exportPdf')->assertHasNoActionErrors();
        $page->callAction('exportExcel')->assertHasNoActionErrors();

        Excel::assertDownloaded('neraca-saldo-20260930-100000.xlsx');
    }
}
