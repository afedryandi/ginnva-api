<?php

namespace Tests\Feature;

use App\Exports\ProductSalesReportExport;
use App\Filament\Pages\ProductSalesReport;
use App\Filament\ReportWidgets\ProductSalesChart;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\Store;
use App\Models\Technician;
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
 * Penjualan Produk (per SKU): pengelompokan menurut varian produk, baris "Belum Diisi SKU" (selalu paling bawah,
 * ikut total), refund dan komisi diatribusikan ke SKU booking, Laba Kotor yang bisa direkonsiliasi (kolom Komisi),
 * persentase, cakupan toko, isi Excel (angka tetap angka) / PDF + log ekspor, dan grafik.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class ProductSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private FilmProduct $ppf;
    private FilmProduct $film;
    private FilmProduct $detailing;

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
        $this->ppf = $this->product('PPF-1', 'PPF Premium', 'ppf');
        $this->film = $this->product('WF-1', 'Kaca Tint', 'window_film');
        $this->detailing = $this->product('DT-1', 'Detailing Interior', 'detailing');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $sku, string $name, string $type): FilmProduct
    {
        return FilmProduct::create(['sku' => $sku, 'name' => $name, 'product_type' => $type, 'position' => 'front', 'base_price' => 100000, 'is_active' => true]);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function admin(): User
    {
        return $this->user('super_admin');
    }

    private function sale(float $amount, string $date, Store $store, ?FilmProduct $product = null, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $date, 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount, 'film_product_id' => $product?->id,
        ], $overrides));

        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->admin(), 'web');
        $page = Livewire::test(ProductSalesReport::class);
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
     * Oktober 2026: PPF Premium 1.000.000 + 500.000 (toko A); Kaca Tint 400.000 (B); Detailing 200.000 (B);
     * tanpa SKU 300.000 (A). Kotor 2.400.000.
     */
    private function october(): array
    {
        return [
            'p1a' => $this->sale(1000000, '2026-10-03', $this->storeA, $this->ppf),
            'p1b' => $this->sale(500000, '2026-10-04', $this->storeA, $this->ppf),
            'film' => $this->sale(400000, '2026-10-05', $this->storeB, $this->film, ['product_ppf' => false, 'product_kaca_film' => true]),
            'detailing' => $this->sale(200000, '2026-10-06', $this->storeB, $this->detailing, ['product_ppf' => false, 'product_detailing' => true]),
            'none' => $this->sale(300000, '2026-10-07', $this->storeA),
        ];
    }

    // ------------------------------------------------------------- pengelompokan

    public function test_rows_are_grouped_per_sku_sorted_by_revenue_with_the_unassigned_row_last(): void
    {
        $this->october();

        $result = $this->report();
        $rows = $result['rows'];

        $this->assertSame(['PPF Premium', 'Kaca Tint', 'Detailing Interior', 'Belum Diisi SKU'], $rows->pluck('name')->all(), 'Baris tanpa SKU selalu di bawah walau nilainya lebih besar dari Detailing.');
        $this->assertSame(['PPF-1', 'WF-1', 'DT-1', '—'], $rows->pluck('sku')->all());
        $this->assertSame(['PPF', 'Kaca Film', 'Detailing', '—'], $rows->pluck('type')->all());
        $this->assertSame([2, 1, 1, 1], $rows->pluck('count')->all());
        $this->assertEquals([1500000.0, 400000.0, 200000.0, 300000.0], $rows->pluck('revenue')->all());
        $this->assertSame(5, $result['totalCount']);
        $this->assertEquals(2400000.0, $result['grossRevenue']);
    }

    public function test_percentages_and_the_unassigned_warning_numbers(): void
    {
        $this->october();

        $result = $this->report();
        $rows = $result['rows']->keyBy('name');

        $this->assertEqualsWithDelta(62.5, $rows['PPF Premium']['revenuePct'], 0.001);
        $this->assertEqualsWithDelta(12.5, $rows['Belum Diisi SKU']['revenuePct'], 0.001);
        $this->assertEqualsWithDelta(100.0, $result['rows']->sum('revenuePct'), 0.0001);
        $this->assertEqualsWithDelta(100.0, $result['rows']->sum('countPct'), 0.0001);
        $this->assertSame(1, $result['unassignedCount']);
        $this->assertEqualsWithDelta(20.0, $result['unassignedPct'], 0.001);
    }

    public function test_an_empty_period_has_no_rows_and_no_division_errors(): void
    {
        $result = $this->report(['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertTrue($result['rows']->isEmpty());
        $this->assertSame(0, $result['totalCount']);
        $this->assertEquals(0.0, $result['unassignedPct']);
    }

    public function test_the_range_is_inclusive_on_the_journal_date(): void
    {
        $this->sale(1, '2026-09-30', $this->storeA, $this->ppf);
        $this->sale(10, '2026-10-01', $this->storeA, $this->ppf);
        $this->sale(100, '2026-10-31', $this->storeA, $this->ppf);
        $this->sale(1000, '2026-11-01', $this->storeA, $this->ppf);

        $this->assertEquals(110.0, $this->report()['grossRevenue']);
    }

    // ------------------------------------------------------------- refund, komisi, laba kotor

    public function test_refunds_are_attributed_to_the_sku_of_the_refunded_booking_including_unassigned_ones(): void
    {
        $bookings = $this->october();
        app(RefundService::class)->process($bookings['p1a'], 100000, null, null);
        app(RefundService::class)->process($bookings['none'], 50000, null, null);

        $result = $this->report();
        $rows = $result['rows']->keyBy('name');

        $this->assertEquals([1, 100000.0], [$rows['PPF Premium']['refundCount'], $rows['PPF Premium']['refundAmount']]);
        $this->assertEquals([1, 50000.0], [$rows['Belum Diisi SKU']['refundCount'], $rows['Belum Diisi SKU']['refundAmount']], 'Booking tanpa SKU: refund masuk baris yang sama dengan penjualannya.');
        $this->assertEquals(0, $rows['Kaca Tint']['refundCount']);
        $this->assertEquals(150000.0, $result['totalRefundAmount']);
        $this->assertEquals(2250000.0, $result['totalRevenue'], 'Headline bersih = kotor − refund.');
    }

    public function test_refunds_follow_the_day_processed_even_with_a_time_on_the_start_date(): void
    {
        $booking = $this->sale(1000000, '2026-10-02', $this->storeA, $this->ppf);
        app(RefundService::class)->process($booking, 50000, null, null);
        DB::table('refunds')->update(['created_at' => '2026-10-01 08:00:00']);

        $this->assertEquals(50000.0, $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])['totalRefundAmount'], 'Refund jam 08:00 di hari pertama ikut.');

        DB::table('refunds')->update(['created_at' => '2026-09-20 08:00:00']);
        $this->assertEquals(0.0, $this->report(['from' => '2026-10-01', 'to' => '2026-10-31'])['totalRefundAmount']);
    }

    public function test_commission_is_shown_and_gross_profit_reconciles_with_it(): void
    {
        $bookings = $this->october();
        $withRate = $this->user('kasir', $this->storeA, ['name' => 'Teknisi Bertarif']);
        $noRate = $this->user('kasir', $this->storeB, ['name' => 'Teknisi Tanpa Tarif']);
        Technician::create(['store_id' => $this->storeA->id, 'user_id' => $withRate->id, 'name' => 'Teknisi Bertarif', 'status' => 'active', 'commission_amount' => 50000]);
        Technician::create(['store_id' => $this->storeB->id, 'user_id' => $noRate->id, 'name' => 'Teknisi Tanpa Tarif', 'status' => 'active']);
        $bookings['p1a']->installers()->attach($withRate->id);
        $bookings['p1b']->installers()->attach($withRate->id);
        $bookings['film']->installers()->attach($noRate->id);
        app(RefundService::class)->process($bookings['p1a'], 100000, null, null);

        $result = $this->report();
        $rows = $result['rows']->keyBy('name');

        $this->assertEquals(100000.0, $rows['PPF Premium']['commission']);
        $this->assertFalse($rows['PPF Premium']['hasUnratedJob']);
        $this->assertTrue($rows['Kaca Tint']['hasUnratedJob'], 'Teknisi tanpa tarif ditandai.');
        $this->assertEquals(0.0, $rows['Kaca Tint']['commission']);
        $this->assertEquals(1500000.0 - 100000.0 - 100000.0 - 0.0, $rows['PPF Premium']['grossProfit'], 'Penjualan − Komisi − Refund − HPP.');
        $this->assertEquals(100000.0, $result['totalCommission']);
        $this->assertEquals($rows->sum('grossProfit'), $result['totalGrossProfit']);
    }

    // ------------------------------------------------------------- toko & akses

    public function test_admin_filters_by_store_and_staff_are_locked_to_their_own(): void
    {
        $this->october();

        $this->assertEquals(600000.0, $this->report(['store_id' => $this->storeB->id])['grossRevenue']);

        $staff = $this->user('kasir', $this->storeA);
        $result = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertEquals(1800000.0, $result['grossRevenue'], 'Staf tidak bisa melihat toko lain.');
        $this->assertSame($this->storeA->id, $result['storeId']);
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertSame(0, $result['totalCount']);
        $this->assertTrue($result['rows']->isEmpty());
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(ProductSalesReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['ProductSalesReport']]), 'web');
        $this->assertTrue(ProductSalesReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(ProductSalesReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->admin(), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(ProductSalesReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()]);
        $this->assertEquals($this->storeB->id, $bad->get('data.store_id'));

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(ProductSalesReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(ProductSalesReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    // ------------------------------------------------------------- ekspor & tampilan

    public function test_excel_keeps_numbers_numeric_and_marks_incomplete_rows_with_a_star(): void
    {
        $bookings = $this->october();
        $noRate = $this->user('kasir', $this->storeB);
        Technician::create(['store_id' => $this->storeB->id, 'user_id' => $noRate->id, 'name' => 'Teknisi Tanpa Tarif', 'status' => 'active']);
        $bookings['film']->installers()->attach($noRate->id);

        $export = new ProductSalesReportExport($this->report());
        $rows = collect($export->array())->keyBy(fn ($r) => $r[0]);

        $this->assertSame(['Produk', 'SKU', 'Jenis Produk', 'Jumlah', 'Jumlah %', 'Penjualan', 'Penjualan %', 'Jumlah Refund', 'Refund', 'Komisi', 'HPP', 'Laba Kotor'], $export->headings());
        foreach ($rows as $row) {
            $this->assertCount(count($export->headings()), $row);
        }
        $this->assertEquals(['PPF Premium', 'PPF-1', 'PPF', 2, 40.0, 1500000.0], array_slice($rows['PPF Premium'], 0, 6), 'Jumlah % = 2 dari 5 transaksi.');
        $this->assertSame(62.5, $rows['PPF Premium'][6], 'Persentase berupa angka.');
        $this->assertEquals([0.0, 0.0, 1500000.0], [$rows['PPF Premium'][9], $rows['PPF Premium'][10], $rows['PPF Premium'][11]]);
        $this->assertSame('0 *', $rows['Kaca Tint'][9], 'Komisi belum lengkap ditandai bintang.');
    }

    public function test_page_shows_the_commission_column_the_unassigned_warning_and_totals(): void
    {
        $this->october();

        $this->page()
            ->assertSuccessful()
            ->assertSee('Total Penjualan Produk (bersih)')
            ->assertSee('Rp2.400.000')
            ->assertSee('Komisi')
            ->assertSee('Belum Diisi SKU')
            ->assertSee('1 dari 5 transaksi (20.0%) belum diisi varian produk')
            ->assertSee('PPF Premium');
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('penjualan-produk-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }

    // ------------------------------------------------------------- grafik

    public function test_the_chart_has_a_line_per_top_product_and_skips_unassigned_bookings(): void
    {
        $this->october();
        $this->actingAs($this->admin(), 'web');

        $chart = Livewire::test(ProductSalesChart::class, ['from' => '2026-10-01', 'to' => '2026-10-07', 'storeId' => null]);
        $method = new ReflectionMethod(ProductSalesChart::class, 'getData');
        $method->setAccessible(true);
        $data = $method->invoke($chart->instance());
        $datasets = collect($data['datasets'])->keyBy('label');

        $this->assertSame(['PPF Premium', 'Kaca Tint', 'Detailing Interior'], array_keys($datasets->all()), 'Urut penjualan tertinggi; booking tanpa SKU tidak punya garis.');
        $this->assertCount(7, $data['labels']);
        $this->assertEquals([0, 0, 1000000.0, 500000.0, 0, 0, 0], $datasets['PPF Premium']['data']);
    }
}
