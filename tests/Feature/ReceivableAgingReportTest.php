<?php

namespace Tests\Feature;

use App\Exports\ReceivableAgingExport;
use App\Filament\Pages\ReceivableAgingReport;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Store;
use App\Models\User;
use App\Services\FinancialStatementService;
use App\Services\ReceivableService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Umur Piutang (aging): kelompok Belum Jatuh Tempo / 1-30 / 31-60 / 61-90 /
 * >90 hari dihitung dari tanggal APLIKASI, batas hari tepat di tiap kelompok,
 * hanya piutang yang masih terbuka, satu baris per customer, filter toko
 * (termasuk pusat) dan pembatasan per peran, ekspor Excel/PDF + jejak audit.
 */
class ReceivableAgingReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private ChartOfAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->revenue = ChartOfAccount::where('code', '7200')->firstOrFail();
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function admin(): User
    {
        return $this->user('super_admin', null, ['store_id' => null]);
    }

    /** Piutang yang jatuh tempo $daysAgo hari lalu (negatif = di masa depan, null = tanpa jatuh tempo). */
    private function receivable(string $customer, float $amount, ?int $daysAgo, array $overrides = []): Receivable
    {
        return app(ReceivableService::class)->createWithJournal(array_merge([
            'customer_name' => $customer, 'amount' => $amount, 'store_id' => $this->store->id,
            'due_date' => $daysAgo === null ? null : today()->subDays($daysAgo)->toDateString(),
        ], $overrides), $this->revenue->id);
    }

    private function aging(?User $viewer = null, array $data = []): array
    {
        $this->actingAs($viewer ?? $this->admin(), 'web');
        $page = Livewire::test(ReceivableAgingReport::class);
        if ($data) {
            $page->set('data.store_id', $data['store_id']);
        }

        return $page->instance()->getAging();
    }

    private function row(array $aging, string $customer)
    {
        return $aging['rows']->firstWhere('customer', $customer);
    }

    // ------------------------------------------------------------- kelompok umur

    public function test_each_bucket_starts_and_ends_on_the_exact_day(): void
    {
        $this->receivable('Belum', 100, -5);        // jatuh tempo 5 hari lagi
        $this->receivable('Hari Ini', 200, 0);      // jatuh tempo hari ini = belum terlambat
        $this->receivable('Tanpa Tempo', 300, null);
        $this->receivable('Satu Hari', 1, 1);       // terlambat 1 hari -> 1-30
        $this->receivable('Tiga Puluh', 2, 30);     // 30 hari -> 1-30
        $this->receivable('Tiga Satu', 4, 31);      // 31 -> 31-60
        $this->receivable('Enam Puluh', 8, 60);     // 60 -> 31-60
        $this->receivable('Enam Satu', 16, 61);     // 61 -> 61-90
        $this->receivable('Sembilan Puluh', 32, 90);// 90 -> 61-90
        $this->receivable('Sembilan Satu', 64, 91); // 91 -> >90

        $aging = $this->aging();

        $expected = [
            'Belum' => ['current_amt' => 100], 'Hari Ini' => ['current_amt' => 200], 'Tanpa Tempo' => ['current_amt' => 300],
            'Satu Hari' => ['b1' => 1], 'Tiga Puluh' => ['b1' => 2],
            'Tiga Satu' => ['b2' => 4], 'Enam Puluh' => ['b2' => 8],
            'Enam Satu' => ['b3' => 16], 'Sembilan Puluh' => ['b3' => 32],
            'Sembilan Satu' => ['b4' => 64],
        ];
        foreach ($expected as $customer => $buckets) {
            $row = $this->row($aging, $customer);
            foreach (['current_amt', 'b1', 'b2', 'b3', 'b4'] as $bucket) {
                $this->assertEquals($buckets[$bucket] ?? 0, (float) $row->$bucket, "{$customer}: kelompok {$bucket}");
            }
        }
    }

    public function test_totals_summary_counts_and_over_ninety_percentage(): void
    {
        $this->receivable('A', 600000, -3);
        $this->receivable('B', 300000, 10);
        $this->receivable('C', 100000, 120);

        $aging = $this->aging();

        $this->assertEquals(600000, $aging['totals']['current_amt']);
        $this->assertEquals(300000, $aging['totals']['b1']);
        $this->assertEquals(100000, $aging['totals']['b4']);
        $this->assertEquals(1000000, $aging['totals']['total']);
        $this->assertSame(3, $aging['customer_count']);
        $this->assertSame(2, $aging['overdue_count'], 'Hanya B dan C yang punya sisa terlambat.');
        $this->assertSame(10.0, $aging['over_90_pct']);
    }

    public function test_an_empty_ledger_has_zero_percent_and_no_rows(): void
    {
        $aging = $this->aging();

        $this->assertCount(0, $aging['rows']);
        $this->assertSame(0.0, $aging['over_90_pct']);
        $this->assertSame(0, $aging['customer_count']);
    }

    public function test_only_open_receivables_count_and_partial_payments_reduce_the_balance(): void
    {
        $payer = $this->admin();
        $paid = $this->receivable('Lunas', 500000, 10);
        app(ReceivableService::class)->recordPayment($paid, 500000, now(), $payer->id);
        $partial = $this->receivable('Sebagian', 500000, 10);
        app(ReceivableService::class)->recordPayment($partial, 200000, now(), $payer->id);
        $cancelled = $this->receivable('Batal', 400000, 10);
        app(ReceivableService::class)->cancelReceivable($cancelled, $payer->id, 'Salah catat');

        $aging = $this->aging();

        $this->assertNull($this->row($aging, 'Lunas'));
        $this->assertNull($this->row($aging, 'Batal'));
        $this->assertEquals(300000, (float) $this->row($aging, 'Sebagian')->b1, 'Hanya sisa yang dihitung.');
    }

    public function test_one_row_per_customer_even_with_different_name_snapshots_and_the_name_fallback(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000099']);
        $this->receivable('Budi', 50000, 5, ['customer_id' => $customer->id]);
        $this->receivable('BUDI', 75000, 40, ['customer_id' => $customer->id]);
        $this->receivable('Tanpa Master', 10000, 5);
        $this->receivable('Tanpa Master', 20000, 5);

        $aging = $this->aging();

        $this->assertSame(2, $aging['rows']->count());
        $budi = $this->row($aging, 'Budi');
        $this->assertEquals(125000, (float) $budi->total);
        $this->assertEquals(50000, (float) $budi->b1);
        $this->assertEquals(75000, (float) $budi->b2);
        $this->assertEquals(30000, (float) $this->row($aging, 'Tanpa Master')->total, 'Tanpa master dikelompokkan per nama.');
    }

    public function test_rows_are_sorted_by_total_descending(): void
    {
        $this->receivable('Kecil', 1000, 5);
        $this->receivable('Besar', 900000, 5);
        $this->receivable('Sedang', 50000, 5);

        $this->assertSame(['Besar', 'Sedang', 'Kecil'], $this->aging()['rows']->pluck('customer')->all());
    }

    // ------------------------------------------------------------- toko & peran

    public function test_store_filter_including_head_office_and_role_restriction(): void
    {
        $this->receivable('Toko A', 100, 5);
        $this->receivable('Toko B', 200, 5, ['store_id' => $this->otherStore->id]);
        $this->receivable('Pusat', 400, 5, ['store_id' => null]);

        $all = $this->aging();
        $this->assertEquals(700, $all['totals']['total']);

        $this->assertSame(['Toko B'], $this->aging($this->admin(), ['store_id' => $this->otherStore->id])['rows']->pluck('customer')->all());
        $this->assertSame(['Pusat'], $this->aging($this->admin(), ['store_id' => FinancialStatementService::COMPANY_WIDE])['rows']->pluck('customer')->all());

        $manager = $this->user('store_manager');
        $this->assertSame(['Toko A'], $this->aging($manager)['rows']->pluck('customer')->all());
        $this->assertSame(['Toko A'], $this->aging($manager, ['store_id' => $this->otherStore->id])['rows']->pluck('customer')->all(), 'Non full-access terkunci ke tokonya sendiri.');
    }

    public function test_a_restricted_user_without_a_store_sees_every_store_like_head_office_finance(): void
    {
        $this->receivable('Toko A', 100, 5);
        $this->receivable('Toko B', 200, 5, ['store_id' => $this->otherStore->id]);
        $finance = $this->user('kasir', null, ['store_id' => null]);

        $this->assertEquals(300, $this->aging($finance)['totals']['total']);
    }

    public function test_access_follows_the_receivable_menu_access(): void
    {
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(ReceivableAgingReport::canAccess());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(ReceivableAgingReport::canAccess());
    }

    // ------------------------------------------------------------- tampilan & ekspor

    public function test_the_store_label_and_the_page_render(): void
    {
        $this->receivable('Budi Pelanggan', 250000, 40);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(ReceivableAgingReport::class)->assertSuccessful()->assertSee('Budi Pelanggan');
        $this->assertSame('Semua Toko', $page->instance()->storeLabel());

        $page->set('data.store_id', FinancialStatementService::COMPANY_WIDE);
        $this->assertSame('Pusat / Tanpa Toko', $page->instance()->storeLabel());
        $page->set('data.store_id', $this->otherStore->id);
        $this->assertSame('Toko B', $page->instance()->storeLabel());
    }

    public function test_the_excel_export_mirrors_the_report_with_a_total_row(): void
    {
        $this->receivable('Budi', 100000, 5);
        $this->receivable('Ani', 300000, 100);
        $aging = $this->aging();

        $export = new ReceivableAgingExport($aging, 'Semua Toko');

        $this->assertSame(['Toko', 'Customer', 'Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '61-90 Hari', '> 90 Hari', 'Total'], $export->headings());
        $rows = $export->array();
        $this->assertCount(3, $rows);
        $this->assertEquals(['Semua Toko', 'Ani', 0.0, 0.0, 0.0, 0.0, 300000.0, 300000.0], $rows[0]);
        $this->assertEquals(['', 'Total', 0.0, 100000.0, 0.0, 0.0, 300000.0, 400000.0], $rows[2]);
    }

    public function test_exports_download_and_leave_an_audit_trail(): void
    {
        $this->receivable('Budi', 100000, 5);
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        Livewire::test(ReceivableAgingReport::class)->callAction('exportPdf')->assertFileDownloaded();

        $log = Activity::where('log_name', 'report_export')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('receivable_aging', $log->properties['report']);
        $this->assertSame('pdf', $log->properties['format']);
    }
}
