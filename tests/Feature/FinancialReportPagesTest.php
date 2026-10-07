<?php

namespace Tests\Feature;

use App\Filament\Pages\BalanceSheetReport;
use App\Filament\Pages\CashFlowReport;
use App\Filament\Pages\FinanceReport;
use App\Filament\Pages\GeneralLedgerReport;
use App\Filament\Pages\IncomeStatementReport;
use App\Filament\Pages\TrialBalanceReport;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceDashboardWidget;
use App\Models\FinanceTransaction;
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
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman Laporan Keuangan (melengkapi FinancialStatementServiceTest yang menguji angkanya): izin akses,
 * pembatasan toko untuk non-full-access (nilai toko yang diutak-atik tidak dipakai), filter & pembanding
 * periode, koreksi tanggal, drill-down, ekspor Excel/PDF + log ekspor, dan ringkasan Laporan Keuangan
 * (transaksi) beserta widget saldo akun.
 */
class FinancialReportPagesTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['spv_finance', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function admin(): User
    {
        return $this->user('super_admin');
    }

    private function viewer(string $page, ?Store $store = null): User
    {
        return $this->user('spv_finance', $store ?? $this->storeA, ['menu_permissions' => [$page => ['view']]]);
    }

    private function account(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    private function revenue(string $date, float $amount, ?Store $store = null, string $revenueCode = '4100', string $cashCode = '1101'): void
    {
        $this->entry($date, [
            ['chart_of_account_id' => $this->account($cashCode), 'debit' => $amount],
            ['chart_of_account_id' => $this->account($revenueCode), 'credit' => $amount],
        ], $store, true);
    }

    private function entry(string $date, array $lines, ?Store $store = null, bool $post = true)
    {
        $svc = app(JournalEntryService::class);
        $entry = $svc->create(['entry_date' => $date, 'store_id' => $store?->id, 'description' => 'Uji laporan', 'reference_type' => 'manual'], $lines);

        return $post ? $svc->post($entry, null) : $entry;
    }

    public static function pages(): array
    {
        return [
            'neraca saldo' => [TrialBalanceReport::class],
            'laba rugi' => [IncomeStatementReport::class],
            'buku besar' => [GeneralLedgerReport::class],
            'neraca' => [BalanceSheetReport::class],
            'arus kas' => [CashFlowReport::class],
        ];
    }

    // ------------------------------------------------------------- izin

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_access_is_full_access_or_a_staff_member_granted_view(string $page): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue($page::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertFalse((bool) $page::canAccess());

        $this->actingAs($this->user('spv_finance', $this->storeA), 'web');
        $this->assertFalse((bool) $page::canAccess(), 'Tanpa izin "view" default ditolak.');

        $this->actingAs($this->viewer($page), 'web');
        $this->assertTrue((bool) $page::canAccess());

        $this->actingAs($this->user('spv_finance', $this->storeA, ['menu_permissions' => [$page => ['view']], 'menu_access' => ['JournalEntryResource']]), 'web');
        $this->assertFalse((bool) $page::canAccess(), 'Menu tidak dicentang di Akses Menu.');
    }

    public function test_finance_summary_needs_a_store_unless_full_access(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue((bool) FinanceReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertTrue((bool) FinanceReport::canAccess());

        $this->actingAs($this->user('kasir', null), 'web');
        $this->assertFalse((bool) FinanceReport::canAccess(), 'Tanpa toko = akan melihat semua toko, jadi ditolak.');
    }

    // ------------------------------------------------------------- pembatasan toko

    public function test_store_restricted_viewers_cannot_widen_the_store_filter(): void
    {
        $this->revenue('2026-09-10', 100000, $this->storeA);
        $this->revenue('2026-09-11', 700000, $this->storeB);

        $checks = [
            IncomeStatementReport::class => fn ($page) => $page->instance()->getResult()['laba_bersih'],
            TrialBalanceReport::class => fn ($page) => $page->instance()->getResult()['total_debit'],
            BalanceSheetReport::class => fn ($page) => $page->instance()->getResult()['aset']['total'],
            CashFlowReport::class => fn ($page) => $page->instance()->getResult()['closing_cash'],
            GeneralLedgerReport::class => fn ($page) => $page->instance()->getResult()['closing_balance'],
        ];

        foreach ($checks as $pageClass => $read) {
            $this->actingAs($this->viewer($pageClass), 'web');
            $page = Livewire::test($pageClass);
            if ($pageClass === GeneralLedgerReport::class) {
                $page->set('data.chart_of_account_id', $this->account('1101'));
            }

            $this->assertEquals(100000.0, (float) $read($page), "{$pageClass}: mulai dari toko sendiri");

            $page->set('data.store_id', $this->storeB->id);
            $this->assertEquals(100000.0, (float) $read($page), "{$pageClass}: toko lain tidak boleh dipilih");

            $page->set('data.store_id', null);
            $this->assertEquals(100000.0, (float) $read($page), "{$pageClass}: 'semua toko' tidak boleh dipilih");
        }
    }

    public function test_full_access_can_pick_store_head_office_or_all(): void
    {
        $this->revenue('2026-09-10', 100000, $this->storeA);
        $this->revenue('2026-09-11', 700000, $this->storeB);
        $this->revenue('2026-09-12', 5000, null);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(IncomeStatementReport::class);
        $this->assertEquals(805000.0, $page->instance()->getResult()['laba_bersih']);
        $this->assertSame('Semua Toko', $page->instance()->getResult()['store_label']);

        $page->set('data.store_id', $this->storeB->id);
        $this->assertEquals(700000.0, $page->instance()->getResult()['laba_bersih']);
        $this->assertSame('Toko B', $page->instance()->getResult()['store_label']);

        $page->set('data.store_id', FinancialStatementService::COMPANY_WIDE);
        $this->assertEquals(5000.0, $page->instance()->getResult()['laba_bersih']);
        $this->assertSame('Pusat / Tanpa Toko', $page->instance()->getResult()['store_label']);
    }

    // ------------------------------------------------------------- Laba Rugi

    public function test_income_statement_defaults_to_this_month_and_presets_fill_the_dates(): void
    {
        $this->revenue('2026-09-05', 100000);
        $this->revenue('2026-08-05', 40000);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(IncomeStatementReport::class);
        $this->assertSame('2026-09-01', $page->get('data.from'));
        $this->assertSame('2026-09-30', $page->get('data.to'));
        $this->assertEquals(100000.0, $page->instance()->getResult()['laba_bersih']);

        $page->set('data.preset', 'last_month');
        $this->assertSame('2026-08-01', $page->get('data.from'));
        $this->assertSame('2026-08-31', $page->get('data.to'));
        $this->assertEquals(40000.0, $page->instance()->getResult()['laba_bersih']);

        $page->set('data.preset', 'ytd');
        $this->assertSame('2026-01-01', $page->get('data.from'));
        $this->assertEquals(140000.0, $page->instance()->getResult()['laba_bersih']);
    }

    public function test_an_end_date_before_the_start_is_corrected_to_the_start(): void
    {
        $this->actingAs($this->admin(), 'web');

        foreach ([IncomeStatementReport::class, CashFlowReport::class, GeneralLedgerReport::class] as $pageClass) {
            $page = Livewire::test($pageClass)->set('data.from', '2026-09-20')->set('data.to', '2026-09-10');

            $this->assertSame('2026-09-20', $page->get('data.to'), $pageClass);
        }
    }

    public function test_income_statement_comparison_ranges_and_accounts_only_in_the_comparison(): void
    {
        $this->revenue('2026-08-10', 100000, null, '4100');
        $this->revenue('2026-09-10', 150000, null, '4200');
        $this->revenue('2025-09-10', 60000, null, '4100');
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(IncomeStatementReport::class)->set('data.compare', 'prev_period');
        $result = $page->instance()->getResult();
        $this->assertSame('01 Aug 2026 – 31 Aug 2026', $result['compare_label']);
        $this->assertEquals(100000.0, $result['compare']['laba_bersih']);

        $rows = $result['sections']['pendapatan']['rows']->keyBy(fn ($r) => $r['account']->code);
        $this->assertEquals(150000.0, $rows['4200']['amount']);
        $this->assertEquals(0.0, $rows['4100']['amount'], 'Akun yang hanya ada di pembanding tetap muncul dengan nilai 0.');
        $this->assertTrue($rows['4100']['compare_only']);

        $page->set('data.hide_zero', true);
        $this->assertTrue($page->instance()->getResult()['sections']['pendapatan']['rows']->contains(fn ($r) => $r['account']->code === '4100'), 'Akun pembanding tidak ikut disembunyikan.');

        $page->set('data.compare', 'prev_year');
        $this->assertSame('01 Sep 2025 – 30 Sep 2025', $page->instance()->getResult()['compare_label']);
        $this->assertEquals(60000.0, $page->instance()->getResult()['compare']['laba_bersih']);
    }

    public function test_hide_zero_removes_accounts_without_amounts(): void
    {
        $this->revenue('2026-09-10', 1000);
        $this->entry('2026-09-11', [
            ['chart_of_account_id' => $this->account('6110'), 'debit' => 500],
            ['chart_of_account_id' => $this->account('1101'), 'credit' => 500],
        ]);
        $this->entry('2026-09-12', [
            ['chart_of_account_id' => $this->account('6110'), 'credit' => 500],
            ['chart_of_account_id' => $this->account('1101'), 'debit' => 500],
        ]);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(IncomeStatementReport::class);
        $this->assertTrue($page->instance()->getResult()['sections']['beban_operasional']['rows']->contains(fn ($r) => $r['account']->code === '6110'));

        $page->set('data.hide_zero', true);
        $this->assertFalse($page->instance()->getResult()['sections']['beban_operasional']['rows']->contains(fn ($r) => $r['account']->code === '6110'));
    }

    // ------------------------------------------------------------- Arus Kas

    public function test_cash_flow_comparison_ranges(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(CashFlowReport::class)->set('data.compare', 'prev_period');
        $this->assertSame('01 Aug 2026 – 31 Aug 2026', $page->instance()->getResult()['compare_label']);

        $page->set('data.from', '2026-07-01')->set('data.to', '2026-09-30');
        $this->assertSame('01 Apr 2026 – 30 Jun 2026', $page->instance()->getResult()['compare_label'], 'Kuartal penuh mundur 3 bulan.');

        $page->set('data.from', '2026-09-10')->set('data.to', '2026-09-19');
        $this->assertSame('31 Aug 2026 – 09 Sep 2026', $page->instance()->getResult()['compare_label'], 'Rentang acak mundur sejumlah hari yang sama.');

        $page->set('data.compare', 'prev_year');
        $this->assertSame('10 Sep 2025 – 19 Sep 2025', $page->instance()->getResult()['compare_label']);

        $page->set('data.compare', null);
        $this->assertNull($page->instance()->getResult()['compare']);
    }

    public function test_cash_flow_page_reports_the_period_and_stays_reconciled(): void
    {
        $this->revenue('2026-08-20', 300000);
        $this->revenue('2026-09-10', 120000, null, '4100', '1102');
        $this->actingAs($this->admin(), 'web');

        $result = Livewire::test(CashFlowReport::class)->instance()->getResult();

        $this->assertEquals(300000.0, $result['opening_cash']);
        $this->assertEquals(120000.0, $result['net_change']);
        $this->assertEquals(420000.0, $result['closing_cash']);
        $this->assertTrue($result['is_reconciled']);
        $this->assertFalse($result['show_details']);
        $this->assertTrue(Livewire::test(CashFlowReport::class)->set('data.show_details', true)->instance()->getResult()['show_details']);
    }

    // ------------------------------------------------------------- Neraca & Neraca Saldo

    public function test_balance_sheet_presets_comparison_and_comparison_only_accounts(): void
    {
        $this->revenue('2026-08-15', 100000, null, '4100', '1102');
        $this->revenue('2026-09-15', 50000, null, '4100', '1101');
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(BalanceSheetReport::class)->set('data.preset', 'last_month_end');
        $this->assertSame('2026-08-31', $page->get('data.as_of'));

        $page->set('data.as_of', '2026-09-30')->set('data.compare', 'prev_month');
        $result = $page->instance()->getResult();
        $this->assertEquals(150000.0, $result['aset']['total']);
        $this->assertEquals(100000.0, $result['compare']['aset']['total']);
        $this->assertSame('30 Aug 2026', $result['compare_label']);
        $this->assertTrue($result['is_balanced']);

        $page->set('data.as_of', '2026-08-31')->set('data.compare', 'prev_year');
        $this->assertEquals(0.0, $page->instance()->getResult()['compare']['aset']['total']);
    }

    public function test_balance_sheet_requires_a_date_and_falls_back_to_today(): void
    {
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(BalanceSheetReport::class)->set('data.as_of', null);

        $this->assertSame('2026-09-30', $page->get('data.as_of'));
    }

    public function test_trial_balance_filters_grouping_drill_down_and_notices(): void
    {
        $this->revenue('2026-09-10', 100000);
        $this->entry('2026-09-11', [
            ['chart_of_account_id' => $this->account('1101'), 'debit' => 5000],
            ['chart_of_account_id' => $this->account('4100'), 'credit' => 5000],
        ], null, false);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(TrialBalanceReport::class);
        $instance = $page->instance();
        $result = $instance->getResult();

        $this->assertTrue($result['is_balanced']);
        $this->assertSame(['aset', 'pendapatan'], $instance->getGroupedRows($result)->keys()->all());

        $page->set('data.search', 'kas');
        $this->assertSame(['aset'], $page->instance()->getGroupedRows($result)->keys()->all());
        $this->assertCount(count($result['rows']), $result['rows'], 'Total tetap seluruh akun.');

        $url = $instance->ledgerUrl($this->account('1101'));
        $this->assertStringContainsString('chart_of_account_id=' . $this->account('1101'), $url);
        $this->assertStringContainsString('from=2026-01-01', $url);

        $this->assertTrue(collect($instance->getNotices())->contains(fn ($n) => $n['type'] === 'warning' && str_contains($n['text'], 'DRAFT')));

        $page->set('data.as_of', '2026-12-31');
        $this->assertTrue(collect($page->instance()->getNotices())->contains(fn ($n) => $n['type'] === 'info' && str_contains($n['text'], 'masa depan')));
    }

    public function test_trial_balance_clears_a_start_date_after_the_cutoff(): void
    {
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(TrialBalanceReport::class)->set('data.as_of', '2026-09-30')->set('data.from', '2026-10-15');

        $this->assertNull($page->get('data.from'));
    }

    public function test_trial_balance_with_a_start_date_returns_opening_and_period_columns(): void
    {
        $this->revenue('2026-08-10', 400000);
        $this->revenue('2026-09-10', 100000);
        $this->actingAs($this->admin(), 'web');

        $result = Livewire::test(TrialBalanceReport::class)->set('data.from', '2026-09-01')->instance()->getResult();
        $cash = $result['rows']->first(fn ($r) => $r['account']->code === '1101');

        $this->assertTrue($result['has_period']);
        $this->assertEquals(400000.0, $cash['opening_balance']);
        $this->assertEquals(100000.0, $cash['period_debit']);
        $this->assertEquals(500000.0, $cash['balance']);
    }

    // ------------------------------------------------------------- Buku Besar

    public function test_general_ledger_defaults_validates_query_parameters_and_steps_through_accounts(): void
    {
        $this->revenue('2026-09-10', 100000);
        $this->actingAs($this->admin(), 'web');

        $first = ChartOfAccount::where('is_postable', true)->where('is_active', true)->orderBy('code')->first();
        $page = Livewire::test(GeneralLedgerReport::class);
        $this->assertSame($first->id, $page->get('data.chart_of_account_id'));
        $this->assertSame('2026-09-01', $page->get('data.from'));

        $page->callAction('prevAccount');
        $this->assertSame($first->id, $page->get('data.chart_of_account_id'), 'Sudah di akun pertama.');

        $page->callAction('nextAccount');
        $second = ChartOfAccount::where('is_postable', true)->where('is_active', true)->orderBy('code')->skip(1)->first();
        $this->assertSame($second->id, $page->get('data.chart_of_account_id'));

        $cashId = $this->account('1101');
        $drill = Livewire::withQueryParams(['chart_of_account_id' => $cashId, 'from' => '2026-09-01', 'to' => '2026-09-30', 'store_id' => 'x'])->test(GeneralLedgerReport::class);
        $this->assertSame($cashId, $drill->get('data.chart_of_account_id'));
        $this->assertEquals(100000.0, $drill->instance()->getResult()['closing_balance']);

        $bad = Livewire::withQueryParams(['chart_of_account_id' => 999999, 'from' => 'kemarin', 'to' => '2026-9-1'])->test(GeneralLedgerReport::class);
        $this->assertSame($first->id, $bad->get('data.chart_of_account_id'));
        $this->assertSame('2026-09-01', $bad->get('data.from'));
        $this->assertSame('2026-09-30', $bad->get('data.to'));
    }

    public function test_general_ledger_search_only_filters_the_display(): void
    {
        $this->revenue('2026-09-10', 100000);
        $this->revenue('2026-09-11', 50000);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(GeneralLedgerReport::class)->set('data.chart_of_account_id', $this->account('1101'));
        $result = $page->instance()->getResult();
        $this->assertCount(2, $page->instance()->getDisplayRows($result));

        $page->set('data.search', $result['rows']->first()['entry_number']);
        $this->assertCount(1, $page->instance()->getDisplayRows($result));
        $this->assertEquals(150000.0, $result['closing_balance']);
    }

    public function test_general_ledger_export_without_an_account_does_not_crash(): void
    {
        $this->actingAs($this->admin(), 'web');
        Excel::fake();

        Livewire::test(GeneralLedgerReport::class)->set('data.chart_of_account_id', null)->callAction('exportExcel')->callAction('exportPdf');

        Excel::assertNothingDownloaded();
    }

    // ------------------------------------------------------------- ekspor

    public static function exportPages(): array
    {
        return [
            'neraca saldo' => [TrialBalanceReport::class, 'neraca-saldo-', 'trial_balance'],
            'laba rugi' => [IncomeStatementReport::class, 'laporan-laba-rugi-', 'income_statement'],
            'buku besar' => [GeneralLedgerReport::class, 'buku-besar-', 'general_ledger'],
            'neraca' => [BalanceSheetReport::class, 'neraca-', 'balance_sheet'],
            'arus kas' => [CashFlowReport::class, 'laporan-arus-kas-', 'cash_flow'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exportPages')]
    public function test_excel_and_pdf_exports_work_and_are_logged(string $page, string $prefix, string $logKey): void
    {
        $this->revenue('2026-09-10', 100000, $this->storeA);
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        Excel::fake();

        $component = Livewire::test($page);
        if ($page === GeneralLedgerReport::class) {
            $component->set('data.chart_of_account_id', $this->account('1101'));
        }

        $component->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded($prefix . '20260930-100000.xlsx');

        $component->callAction('exportPdf')->assertHasNoActionErrors();

        $formats = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get()
            ->filter(fn ($a) => ($a->properties['report'] ?? null) === $logKey)
            ->map(fn ($a) => $a->properties['format'])->sort()->values()->all();
        $this->assertSame(['pdf', 'xlsx'], $formats);
    }

    // ------------------------------------------------------------- Laporan Keuangan (transaksi)

    private function categoryAndTransactions(): void
    {
        $in = FinanceCategory::create(['name' => 'Pendapatan Lain', 'type' => 'in', 'chart_of_account_id' => $this->account('4400'), 'is_active' => true]);
        $out = FinanceCategory::create(['name' => 'Beban Listrik', 'type' => 'out', 'chart_of_account_id' => $this->account('6510'), 'is_active' => true]);
        $make = fn ($cat, $store, $amount, $date = '2026-09-10') => FinanceTransaction::create([
            'type' => $cat->type, 'finance_category_id' => $cat->id, 'store_id' => $store->id, 'amount' => $amount, 'transaction_date' => $date,
        ]);
        $make($in, $this->storeA, 1000000);
        $make($out, $this->storeA, 300000);
        $make($in, $this->storeB, 5000000);
        $make($out, $this->storeA, 999, '2026-08-10');
    }

    public function test_finance_summary_totals_month_store_and_breakdown(): void
    {
        $this->categoryAndTransactions();
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(FinanceReport::class);
        $this->assertSame(['in' => 6000000.0, 'out' => 300000.0, 'net' => 5700000.0], $page->instance()->getTotals());

        $page->set('data.store_id', $this->storeA->id);
        $this->assertSame(['in' => 1000000.0, 'out' => 300000.0, 'net' => 700000.0], $page->instance()->getTotals());
        $this->assertSame(['Pendapatan Lain', 'Beban Listrik'], $page->instance()->getBreakdown()->pluck('category')->all(), 'Terbesar dulu.');

        $page->set('data.month', '2026-08-20');
        $this->assertSame(999.0, $page->instance()->getTotals()['out']);
    }

    public function test_finance_summary_locks_store_staff_to_their_own_store(): void
    {
        $this->categoryAndTransactions();
        $this->actingAs($this->user('kasir', $this->storeA), 'web');

        $page = Livewire::test(FinanceReport::class)->set('data.store_id', $this->storeB->id);

        $this->assertSame(1000000.0, $page->instance()->getTotals()['in']);
        $this->assertTrue($page->instance()->getPinnedAccountBalances()->isEmpty());
    }

    public function test_finance_summary_warns_about_transactions_without_a_journal(): void
    {
        $this->categoryAndTransactions();
        $this->actingAs($this->admin(), 'web');

        $this->assertStringContainsString('3 transaksi bulan ini belum tertaut ke jurnal', Livewire::test(FinanceReport::class)->instance()->getSubheading());
    }

    public function test_pinned_account_widgets_show_balances_as_of_month_end(): void
    {
        $this->revenue('2026-09-10', 100000);
        $this->revenue('2026-10-05', 999);
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        $page = Livewire::test(FinanceReport::class)
            ->callAction('manageWidgets', data: ['chart_of_account_ids' => [$this->account('4100'), $this->account('1101')]])
            ->assertHasNoActionErrors();

        $pinned = $page->instance()->getPinnedAccountBalances();
        $this->assertSame(['4100', '1101'], $pinned->map(fn ($p) => $p['account']->code)->all());
        $this->assertEquals(100000.0, $pinned->first()['balance']);

        $page->callAction('manageWidgets', data: ['chart_of_account_ids' => []]);
        $this->assertSame(0, FinanceDashboardWidget::where('user_id', $admin->id)->count());
    }

    public function test_finance_summary_exports_download(): void
    {
        $this->categoryAndTransactions();
        $this->actingAs($this->admin(), 'web');
        Excel::fake();

        Livewire::test(FinanceReport::class)->callAction('exportExcel')->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-keuangan-20260930-100000.xlsx');
    }
}
