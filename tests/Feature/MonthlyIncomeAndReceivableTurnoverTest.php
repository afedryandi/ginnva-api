<?php

namespace Tests\Feature;

use App\Filament\Pages\MonthlyIncomeStatementReport;
use App\Filament\Pages\ReceivableAgingReport;
use App\Models\ChartOfAccount;
use App\Models\Receivable;
use App\Models\Store;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\ReceivableService;
use App\Services\ReceivableTurnoverService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laba Rugi Bulanan (12 kolom) dan Perputaran Piutang / Hari Penagihan (audit ACCURATE 5). "Hari ini" dibekukan di 20 Oktober 2026.
 */
class MonthlyIncomeAndReceivableTurnoverTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private ChartOfAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->revenue = ChartOfAccount::where('code', '7200')->firstOrFail();
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $code): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->firstOrFail();
    }

    private function postJournal(string $debitCode, string $creditCode, float $amount, string $date): void
    {
        $service = app(JournalEntryService::class);
        $entry = $service->create(['entry_date' => $date, 'store_id' => null, 'description' => 'Tes bulanan', 'created_by' => null], [
            ['chart_of_account_id' => $this->account($debitCode)->id, 'debit' => $amount],
            ['chart_of_account_id' => $this->account($creditCode)->id, 'credit' => $amount],
        ]);
        $service->post($entry, null);
    }

    private function receivable(float $amount, string $issuedOn, ?Store $store = null): Receivable
    {
        $receivable = app(ReceivableService::class)->createWithJournal([
            'customer_name' => 'Pelanggan ' . uniqid(), 'amount' => $amount, 'store_id' => ($store ?? $this->store)->id,
        ], $this->revenue->id);

        DB::table('receivables')->where('id', $receivable->id)->update(['created_at' => $issuedOn . ' 10:00:00']);

        return $receivable->fresh();
    }

    // ------------------------------------------------------------- Laba Rugi Bulanan

    public function test_each_month_gets_its_own_column_and_the_totals_add_up(): void
    {
        $this->postJournal('1102', '4100', 100000, '2026-01-10');
        $this->postJournal('1102', '4100', 50000, '2026-03-05');
        $this->postJournal('6410', '1102', 20000, '2026-03-15');

        $result = Livewire::test(MonthlyIncomeStatementReport::class)->instance()->getResult();

        $revenue = $result['sections']['pendapatan'];
        $this->assertSame(100000.0, $revenue['months'][0]);
        $this->assertSame(0.0, $revenue['months'][1]);
        $this->assertSame(50000.0, $revenue['months'][2]);
        $this->assertSame(150000.0, $revenue['total']);

        $net = $result['profits']['laba_bersih'];
        $this->assertSame(30000.0, $net['months'][2]);
        $this->assertSame(130000.0, $net['total']);

        $row = collect($revenue['rows'])->first(fn (array $r) => $r['account']->code === '4100');
        $this->assertNotNull($row);
        $this->assertSame(150000.0, $row['total']);
    }

    public function test_a_month_matches_the_single_period_income_statement(): void
    {
        $this->postJournal('1102', '4100', 75000, '2026-05-20');

        $monthly = Livewire::test(MonthlyIncomeStatementReport::class)->instance()->getResult();
        $single = app(\App\Services\FinancialStatementService::class)->incomeStatement(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

        $this->assertSame($single['laba_bersih'], $monthly['profits']['laba_bersih']['months'][4]);
        $this->assertSame($single['sections']['pendapatan']['total'], $monthly['sections']['pendapatan']['months'][4]);
    }

    public function test_the_export_has_one_column_per_month_plus_total_and_blanks_for_zero(): void
    {
        $this->postJournal('1102', '4100', 100000, '2026-01-10');
        $this->postJournal('1102', '4100', 50000, '2026-03-05');

        $page = Livewire::test(MonthlyIncomeStatementReport::class);
        $data = (fn () => $this->reportExportData())->call($page->instance());

        $this->assertSame('laba-rugi-bulanan', $data['key']);
        $this->assertCount(15, $data['headings']);
        $this->assertSame(['Kode', 'Akun', 'Jan', 'Feb', 'Mar'], array_slice($data['headings'], 0, 5));
        $this->assertSame('Total', $data['headings'][14]);

        $row = collect($data['rows'])->firstWhere(0, '4100');
        $this->assertNotNull($row);
        $this->assertSame(100000.0, $row[2]);
        $this->assertNull($row[3]);
        $this->assertSame(50000.0, $row[4]);
        $this->assertSame(150000.0, $row[14]);
        $this->assertCount(15, $row);
    }

    public function test_the_buttons_download_named_files(): void
    {
        Excel::fake();

        $page = Livewire::test(MonthlyIncomeStatementReport::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('laba-rugi-bulanan-20261020-090000.xlsx');
        $page->callAction('exportPdf')->assertFileDownloaded('laba-rugi-bulanan-20261020-090000.pdf');
    }

    // ------------------------------------------------------------- Perputaran Piutang

    public function test_turnover_and_collection_days_use_the_balance_at_the_start_and_end_of_the_period(): void
    {
        $service = app(ReceivableService::class);

        // Terbit sebelum periode, dibayar sebagian di dalam periode.
        $old = $this->receivable(1_000_000, '2026-08-01');
        $service->recordPayment($old, 400_000, Carbon::parse('2026-10-05'), null);
        // Terbit di dalam periode, belum dibayar.
        $this->receivable(600_000, '2026-10-01');

        $r = app(ReceivableTurnoverService::class)->summarize(Carbon::parse('2026-09-21'), Carbon::parse('2026-10-20'));

        $this->assertSame(30, $r['days']);
        $this->assertSame(600_000.0, $r['credit_sales']);
        $this->assertSame(400_000.0, $r['collected']);
        $this->assertSame(1_000_000.0, $r['opening']);
        $this->assertSame(1_200_000.0, $r['closing']);
        $this->assertSame(1_100_000.0, $r['average']);
        $this->assertSame(0.55, $r['turnover']);
        $this->assertSame(55.0, $r['collection_days']);
    }

    public function test_a_cancelled_receivable_and_a_voided_payment_are_not_counted(): void
    {
        $service = app(ReceivableService::class);

        $cancelled = $this->receivable(300_000, '2026-10-02');
        $service->cancelReceivable($cancelled, null, 'Salah input');

        $kept = $this->receivable(500_000, '2026-10-03');
        $payment = $service->recordPayment($kept, 200_000, Carbon::parse('2026-10-10'), null);
        $service->voidPayment($payment, null, 'Salah catat');

        $r = app(ReceivableTurnoverService::class)->summarize(Carbon::parse('2026-09-21'), Carbon::parse('2026-10-20'));

        $this->assertSame(500_000.0, $r['credit_sales']);
        $this->assertSame(0.0, $r['collected']);
        $this->assertSame(500_000.0, $r['closing']);
    }

    public function test_the_store_filter_limits_the_calculation(): void
    {
        $this->receivable(400_000, '2026-10-01', $this->store);
        $this->receivable(900_000, '2026-10-02', $this->otherStore);

        $service = app(ReceivableTurnoverService::class);
        $from = Carbon::parse('2026-09-21');
        $to = Carbon::parse('2026-10-20');

        $this->assertSame(400_000.0, $service->summarize($from, $to, $this->store->id)['credit_sales']);
        $this->assertSame(1_300_000.0, $service->summarize($from, $to)['credit_sales']);
    }

    public function test_without_receivables_the_ratios_are_empty_instead_of_dividing_by_zero(): void
    {
        $r = app(ReceivableTurnoverService::class)->summarize(Carbon::parse('2026-09-21'), Carbon::parse('2026-10-20'));

        $this->assertNull($r['turnover']);
        $this->assertNull($r['collection_days']);
        $this->assertSame(0.0, $r['average']);
    }

    public function test_the_aging_page_shows_the_ratio_section_and_an_unknown_period_falls_back_to_90_days(): void
    {
        $page = Livewire::test(ReceivableAgingReport::class)
            ->assertSee('Perputaran Piutang dan Hari Penagihan')
            ->set('data.ratio_days', 7);

        $this->assertSame(90, $page->instance()->getTurnover()['days']);

        $page->set('data.ratio_days', 30);
        $this->assertSame(30, $page->instance()->getTurnover()['days']);
    }
}
