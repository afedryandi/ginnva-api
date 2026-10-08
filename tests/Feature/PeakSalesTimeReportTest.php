<?php

namespace Tests\Feature;

use App\Exports\PeakSalesTimeReportExport;
use App\Filament\Pages\PeakSalesTimeReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Waktu Teramai Penjualan: penjualan berbayar (ada jurnal pendapatan) dikelompokkan per HARI DALAM SEMINGGU menurut
 * tanggal jurnal -- nilai, jumlah transaksi, jumlah produk (4 jenis layanan) dan pelanggan unik per hari-dalam-seminggu;
 * cakupan toko; batas rentang 2 tahun; sanitasi URL; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PeakSalesTimeReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

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

    private function customer(string $name): Customer
    {
        return Customer::create(['name' => $name, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
    }

    private function sale(Customer $customer, float $amount, string $date, Store $store, array $flags = []): Booking
    {
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'preferred_date' => $date, 'status' => 'completed', 'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $flags));
        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    /**
     * Senin 5 Okt: A 1.000.000 (PPF + Detailing, pelanggan C1). Senin 12 Okt: A 500.000 (Kaca Film, C1 lagi) dan B 300.000
     * (Premium Wash, C2). Selasa 6 Okt: A 200.000 (tanpa jenis, C3). Sabtu 3 Okt: B 400.000 (PPF, C2). Di luar: 28 Sep.
     */
    private function october(): void
    {
        $c1 = $this->customer('C1');
        $c2 = $this->customer('C2');
        $c3 = $this->customer('C3');

        $this->sale($c1, 1000000, '2026-10-05', $this->storeA, ['product_ppf' => true, 'product_detailing' => true]);
        $this->sale($c1, 500000, '2026-10-12', $this->storeA, ['product_kaca_film' => true]);
        $this->sale($c2, 300000, '2026-10-12', $this->storeB, ['product_premium_wash' => true]);
        $this->sale($c3, 200000, '2026-10-06', $this->storeA);
        $this->sale($c2, 400000, '2026-10-03', $this->storeB, ['product_ppf' => true]);
        $this->sale($c1, 900000, '2026-09-28', $this->storeA, ['product_ppf' => true]);
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(PeakSalesTimeReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    private function row(array $result, string $day): array
    {
        return collect($result['rows'])->firstWhere('dayName', $day);
    }

    public function test_sales_are_grouped_by_weekday_of_the_journal_date(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'], collect($result['rows'])->pluck('dayName')->all());
        $monday = $this->row($result, 'Senin');
        $this->assertEqualsWithDelta(1800000.0, $monday['revenue'], 0.001);
        $this->assertSame(3, $monday['count']);
        $this->assertSame(2, $monday['customers'], 'C1 datang di dua hari Senin tetap 1; ditambah C2.');
        $this->assertSame([1, 200000.0, 1], [$this->row($result, 'Selasa')['count'], $this->row($result, 'Selasa')['revenue'], $this->row($result, 'Selasa')['customers']]);
        $this->assertSame([1, 400000.0], [$this->row($result, 'Sabtu')['count'], $this->row($result, 'Sabtu')['revenue']]);
        $this->assertSame([0, 0.0, 0], [$this->row($result, 'Rabu')['count'], $this->row($result, 'Rabu')['revenue'], $this->row($result, 'Rabu')['customers']]);
    }

    public function test_totals_and_percentages(): void
    {
        $this->october();

        $result = $this->report();
        $monday = $this->row($result, 'Senin');

        $this->assertEqualsWithDelta(2400000.0, $result['totalRevenue'], 0.001, 'Penjualan 28 Sep tidak ikut.');
        $this->assertSame(5, $result['totalCount']);
        $this->assertSame(3, $result['totalCustomers'], 'Pelanggan unik lintas hari: C1, C2, C3.');
        $this->assertEqualsWithDelta(75.0, $monday['revenuePct'], 0.001);
        $this->assertEqualsWithDelta(60.0, $monday['countPct'], 0.001);
        $this->assertEqualsWithDelta(100.0, collect($result['rows'])->sum('revenuePct'), 0.001);
    }

    public function test_products_count_all_four_service_types(): void
    {
        $this->october();

        $result = $this->report();

        // Senin: PPF + Detailing (2) + Kaca Film (1) + Premium Wash (1) = 4; Selasa tanpa jenis = 0; Sabtu PPF = 1.
        $this->assertSame(4, $this->row($result, 'Senin')['products']);
        $this->assertSame(0, $this->row($result, 'Selasa')['products']);
        $this->assertSame(1, $this->row($result, 'Sabtu')['products']);
        $this->assertSame(5, $result['totalProducts']);
        $this->assertEqualsWithDelta(80.0, $this->row($result, 'Senin')['productsPct'], 0.001);
    }

    public function test_an_empty_period_has_zero_everything_without_division_errors(): void
    {
        $result = $this->report(['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertSame([0, 0.0, 0], [$result['totalCount'], $result['totalRevenue'], $result['totalCustomers']]);
        $this->assertCount(7, $result['rows']);
        $this->assertEquals(0, $this->row($result, 'Senin')['revenuePct']);
    }

    public function test_ranges_longer_than_two_years_are_cut_and_flagged(): void
    {
        $result = $this->report(['from' => '2020-01-01', 'to' => '2026-10-31']);

        $this->assertTrue($result['rangeClamped']);
        $this->assertSame('2024-10-31', $result['from']->toDateString());

        $this->page(['from' => '2020-01-01', 'to' => '2026-10-31'])->assertSee('Rentang tanggal yang dipilih terlalu panjang');
        $this->assertFalse($this->report()['rangeClamped']);
    }

    public function test_admin_store_filter_and_staff_lock(): void
    {
        $this->october();

        $b = $this->report(['store_id' => $this->storeB->id]);
        $this->assertSame(2, $b['totalCount']);
        $this->assertEqualsWithDelta(700000.0, $b['totalRevenue'], 0.001);

        $staff = $this->user('kasir', $this->storeA);
        $own = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertSame(3, $own['totalCount'], 'Staf tidak bisa melihat toko lain.');
        $this->assertEqualsWithDelta(1700000.0, $own['totalRevenue'], 0.001);
        $this->assertSame(3, $this->row($own, 'Senin')['products']);
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertSame(0, $result['totalCount']);
        $this->assertEquals(0, $result['totalRevenue']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(PeakSalesTimeReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['PeakSalesTimeReport']]), 'web');
        $this->assertTrue(PeakSalesTimeReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(PeakSalesTimeReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(PeakSalesTimeReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(PeakSalesTimeReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertSame($this->storeA->id, Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(PeakSalesTimeReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_cards_and_all_seven_days(): void
    {
        $this->october();

        $this->page()
            ->assertSuccessful()
            ->assertSee('Total Penjualan')
            ->assertSee('Rp2.400.000', false)
            ->assertSee('Rp1.800.000', false)
            ->assertSee('Senin')
            ->assertSee("Jum'at")
            ->assertSee('75.0%', false)
            ->assertSee('pelanggan UNIK');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $this->october();

        $export = new PeakSalesTimeReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Hari', 'Penjualan (Rp)', 'Penjualan (%)', 'Transaksi', 'Transaksi (%)', 'Produk', 'Produk (%)', 'Pelanggan'], $export->headings());
        $this->assertCount(7, $rows);
        $this->assertSame(['Senin', 1800000.0, 75.0, 3, 60.0, 4, 80.0, 2], $rows[1]);
        $this->assertSame(['Rabu', 0.0, 0.0, 0, 0.0, 0, 0.0, 0], $rows[3]);
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('waktu-teramai-penjualan-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('peak_sales_time', $logs->first()->properties['report']);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
