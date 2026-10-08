<?php

namespace Tests\Feature;

use App\Exports\SalesByOutletExport;
use App\Filament\Pages\SalesByOutletReport;
use App\Filament\Widgets\SalesByOutletChart;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\RefundService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penjualan Outlet: rekap per outlet (urut penjualan bersih tertinggi, outlet tanpa transaksi tetap tampil),
 * bersih = kotor − refund menurut hari diproses, persentase kontribusi, produk untuk keempat jenis layanan,
 * piutang, cakupan toko (staf hanya tokonya, akun tanpa toko tidak melihat apa pun), sanitasi URL,
 * isi Excel (persentase numerik) / PDF + log ekspor, dan grafik per outlet.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class SalesByOutletTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private Store $storeC;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->storeC = Store::create(['city' => 'Medan', 'address' => 'Jl. C', 'name' => 'Toko C', 'is_active' => true]);
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

    private function sale(float $amount, string $date, Store $store, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $date, 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $overrides));

        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->admin(), 'web');
        $page = Livewire::test(SalesByOutletReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    /**
     * Oktober 2026: A = 1.000.000 (terbayar 600.000, PPF + Detailing) + 500.000 (terbayar kosong, PPF);
     * B = 300.000 (PPF + Kaca Film + Premium Wash), refund 50.000; C tidak ada transaksi.
     */
    private function october(): array
    {
        $a1 = $this->sale(1000000, '2026-10-03', $this->storeA, ['amount_received' => 600000, 'product_detailing' => true]);
        $a2 = $this->sale(500000, '2026-10-04', $this->storeA, ['amount_received' => null]);
        $b1 = $this->sale(300000, '2026-10-05', $this->storeB, ['product_kaca_film' => true, 'product_premium_wash' => true]);
        app(RefundService::class)->process($b1, 50000, null, null);

        return [$a1, $a2, $b1];
    }

    // ------------------------------------------------------------- angka

    public function test_rows_are_sorted_by_net_sales_and_every_outlet_is_listed(): void
    {
        $this->october();

        $result = $this->report();
        $rows = $result['rows']->keyBy(fn ($r) => $r['store']->name);

        $this->assertSame(['Toko A', 'Toko B', 'Toko C'], $result['rows']->pluck('store.name')->all());
        $this->assertSame([2, 1, 0], $result['rows']->pluck('count')->all());
        $this->assertEquals([1500000.0, 250000.0, 0.0], $result['rows']->pluck('revenue')->all());
        $this->assertEquals(1750000.0, $result['totalRevenue']);
        $this->assertEquals(50000.0, $result['totalRefund']);
        $this->assertSame(3, $result['totalCount']);
        $this->assertSame(0, $rows['Toko C']['count'], 'Outlet tanpa transaksi tetap tampil.');
    }

    public function test_net_is_gross_minus_refund_and_outstanding_uses_received(): void
    {
        $this->october();

        $rows = $this->report()['rows']->keyBy(fn ($r) => $r['store']->name);

        $this->assertEquals(300000.0, $rows['Toko B']['grossRevenue']);
        $this->assertEquals(50000.0, $rows['Toko B']['refund']);
        $this->assertEquals(250000.0, $rows['Toko B']['revenue']);
        $this->assertEquals(400000.0, $rows['Toko A']['outstanding'], '1.500.000 − (600.000 + 500.000 yang kosong dianggap lunas).');
        $this->assertEquals(0.0, $rows['Toko B']['outstanding']);
        $this->assertEquals(750000.0, $rows['Toko A']['avg']);
        $this->assertEquals(250000.0, $rows['Toko B']['avg']);
    }

    public function test_products_count_all_four_service_types_and_percentages_add_up(): void
    {
        $this->october();

        $result = $this->report();
        $rows = $result['rows']->keyBy(fn ($r) => $r['store']->name);

        $this->assertSame(3, $rows['Toko A']['products'], 'PPF + Detailing, lalu PPF.');
        $this->assertSame(3, $rows['Toko B']['products'], 'PPF + Kaca Film + Premium Wash.');
        $this->assertSame(6, $result['totalProducts']);
        $this->assertEquals(1.5, $rows['Toko A']['productsPerTransaction']);
        $this->assertEquals(3.0, $rows['Toko B']['productsPerTransaction']);
        $this->assertEqualsWithDelta(100.0, $result['rows']->sum('revenuePct'), 0.0001);
        $this->assertEqualsWithDelta(85.714, $rows['Toko A']['revenuePct'], 0.001);
        $this->assertEqualsWithDelta(50.0, $rows['Toko A']['productsPct'], 0.0001);
        $this->assertEqualsWithDelta(66.667, $rows['Toko A']['countPct'], 0.001);
    }

    public function test_an_empty_period_has_zero_percentages_instead_of_errors(): void
    {
        $result = $this->report(['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertEquals(0.0, $result['totalRevenue']);
        $this->assertTrue($result['rows']->every(fn ($r) => $r['revenuePct'] === 0 && $r['productsPct'] === 0 && $r['avg'] === 0));
    }

    public function test_refunds_follow_the_day_they_were_processed_even_with_a_time_on_the_start_date(): void
    {
        $this->october();
        DB::table('refunds')->update(['created_at' => '2026-10-01 08:00:00']);

        $inside = $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31']);
        $this->assertEquals(50000.0, $inside['totalRefund'], 'Refund jam 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');

        DB::table('refunds')->update(['created_at' => '2026-09-20 08:00:00']);
        $this->assertEquals(0.0, $this->report(['from' => '2026-10-01', 'to' => '2026-10-31'])['totalRefund']);
        $this->assertEquals(50000.0, $this->report(['from' => '2026-09-01', 'to' => '2026-09-30'])['totalRefund']);
    }

    public function test_the_date_range_is_inclusive_on_the_journal_date(): void
    {
        $this->sale(1, '2026-09-30', $this->storeA);
        $this->sale(10, '2026-10-01', $this->storeA);
        $this->sale(100, '2026-10-31', $this->storeA);
        $this->sale(1000, '2026-11-01', $this->storeA);

        $this->assertEquals(110.0, $this->report()['totalRevenue']);
    }

    // ------------------------------------------------------------- cakupan toko

    public function test_staff_see_only_their_own_outlet(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', $this->storeA));

        $this->assertSame(['Toko A'], $result['rows']->pluck('store.name')->all());
        $this->assertEquals(1500000.0, $result['totalRevenue']);
        $this->assertSame($this->storeA->id, $result['storeId']);
    }

    public function test_an_account_without_a_store_sees_no_outlets_at_all(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertTrue($result['rows']->isEmpty(), 'Bukan daftar semua outlet dengan angka nol.');
        $this->assertEquals(0.0, $result['totalRevenue']);
        $this->assertSame(0, $result['pendingCount']);
    }

    public function test_the_pending_count_is_scoped_like_the_rest(): void
    {
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '081388888888']);
        foreach ([$this->storeA, $this->storeB] as $store) {
            Booking::create(['booking_number' => 'BKG-PEND-' . $store->id, 'customer_id' => $customer->id, 'store_id' => $store->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-02', 'status' => 'completed', 'transaction_amount' => 1000]);
        }

        $this->assertSame(2, $this->report()['pendingCount']);
        $this->assertSame(1, $this->report([], $this->user('kasir', $this->storeA))['pendingCount']);
    }

    // ------------------------------------------------------------- akses & URL

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(SalesByOutletReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SalesByOutletReport']]), 'web');
        $this->assertTrue(SalesByOutletReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(SalesByOutletReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_reversed_ranges_are_corrected(): void
    {
        $this->actingAs($this->admin(), 'web');

        $fresh = Livewire::test(SalesByOutletReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($fresh->get('data.from'))->toDateString(), Carbon::parse($fresh->get('data.to'))->toDateString()]);

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => ''])->test(SalesByOutletReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()]);

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(SalesByOutletReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString());

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());
    }

    public function test_quick_periods_fill_the_dates(): void
    {
        $page = $this->page();

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31'], 'this_month' => ['2026-10-01', '2026-10-31']] as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    public function test_the_drill_down_link_carries_the_range_and_the_outlet(): void
    {
        $url = urldecode($this->page(['from' => '2026-10-01', 'to' => '2026-10-15'])->instance()->salesUrl($this->storeB->id));

        $this->assertStringContainsString('[entry_date][from]=2026-10-01', $url);
        $this->assertStringContainsString('[entry_date][until]=2026-10-15', $url);
        $this->assertStringContainsString('[store_id][value]=' . $this->storeB->id, $url);
    }

    // ------------------------------------------------------------- ekspor & tampilan

    public function test_excel_rows_keep_numbers_numeric_including_the_percentages(): void
    {
        $this->october();
        $export = new SalesByOutletExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Outlet', 'Transaksi', 'Penjualan (bersih)', 'Penjualan %', 'Pengembalian', 'Produk', 'Produk %', 'Rata-rata/Transaksi', 'Produk/Transaksi', 'Piutang'], $export->headings());
        $this->assertSame(['Toko A', 2, 1500000.0, 85.7, 0.0, 3, 50.0, 750000.0, 1.5, 400000.0], $rows[0]);
        $this->assertSame(['Toko B', 1, 250000.0, 14.3, 50000.0, 3, 50.0, 250000.0, 3.0, 0.0], $rows[1]);
        $this->assertSame(['Toko C', 0, 0.0, 0.0, 0.0, 0, 0.0, 0.0, 0.0, 0.0], $rows[2]);
    }

    public function test_page_shows_totals_rows_banners_and_links_only_for_outlets_with_sales(): void
    {
        $this->october();
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '081377777777']);
        Booking::create(['booking_number' => 'BKG-PEND', 'customer_id' => $customer->id, 'store_id' => $this->storeA->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-02', 'status' => 'completed', 'transaction_amount' => 1000]);

        $this->page()
            ->assertSuccessful()
            ->assertSee('Total Penjualan Semua Outlet (bersih)')
            ->assertSee('Rp1.750.000')
            ->assertSee('setelah pengembalian Rp50.000')
            ->assertSee('kotor Rp1.800.000')
            ->assertSee('85,7%')
            ->assertSee('Lihat Detail Penjualan outlet ini')
            ->assertSee('sudah selesai tapi belum diproses ke pendapatan')
            ->assertSee('Toko C');
    }

    public function test_exports_download_and_the_log_names_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page([], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('penjualan-outlet-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_for_admins_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }

    // ------------------------------------------------------------- grafik

    public function test_the_chart_has_a_line_per_outlet_and_a_label_per_day(): void
    {
        $this->october();
        $this->actingAs($this->admin(), 'web');

        $chart = Livewire::test(SalesByOutletChart::class, ['from' => '2026-10-01', 'to' => '2026-10-07']);
        $method = new ReflectionMethod(SalesByOutletChart::class, 'getData');
        $method->setAccessible(true);
        $data = $method->invoke($chart->instance());
        $datasets = collect($data['datasets'])->keyBy('label');

        $this->assertCount(7, $data['labels']);
        $this->assertSame(['Toko A', 'Toko B', 'Toko C'], array_keys($datasets->all()));
        $this->assertEquals([0, 0, 1000000.0, 500000.0, 0, 0, 0], $datasets['Toko A']['data']);
        $this->assertEquals([0, 0, 0, 0, 300000.0, 0, 0], $datasets['Toko B']['data'], 'Grafik menampilkan penjualan kotor per hari.');
    }

    public function test_the_chart_for_staff_shows_only_their_outlet(): void
    {
        $this->october();
        $this->actingAs($this->user('kasir', $this->storeB), 'web');

        $chart = Livewire::test(SalesByOutletChart::class, ['from' => '2026-10-01', 'to' => '2026-10-07']);
        $method = new ReflectionMethod(SalesByOutletChart::class, 'getData');
        $method->setAccessible(true);

        $this->assertSame(['Toko B'], array_column($method->invoke($chart->instance())['datasets'], 'label'));
    }
}
