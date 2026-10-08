<?php

namespace Tests\Feature;

use App\Exports\SalesByPeriodExport;
use App\Filament\Pages\SalesByPeriodReport;
use App\Filament\ReportWidgets\SalesByPeriodChart;
use App\Models\Booking;
use App\Models\Customer;
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
 * Penjualan Per Periode (melengkapi SalesByPeriodReportTest): kunci & label periode, tabel rekap harian /
 * mingguan / bulanan (periode kosong tetap tampil, bucket membawa rentang drill-down yang dijepit), isi tiap
 * kolom (diterima, piutang, produk untuk keempat jenis layanan, refund menurut hari diproses, komisi, laba kotor),
 * batas rentang, pembatasan toko, sanitasi URL, isi Excel/PDF + log ekspor, dan grafik yang memakai definisi sama.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class SalesByPeriodTest extends TestCase
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

    private function admin(): User
    {
        return $this->user('super_admin');
    }

    private function sale(float $amount, string $date, ?Store $store = null, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
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
        $page = Livewire::test(SalesByPeriodReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    // ------------------------------------------------------------- kunci periode

    public function test_period_keys_labels_and_bucket_ends(): void
    {
        [$key, $label, $end] = SalesByPeriodReport::periodKeyFor(Carbon::parse('2026-10-08'), 'bulanan');
        $this->assertSame(['2026-10', 'October 2026', '2026-10-31'], [$key, $label, $end->toDateString()]);

        [$key, $label, $end] = SalesByPeriodReport::periodKeyFor(Carbon::parse('2026-09-30'), 'mingguan');
        $this->assertSame(['2026-09-28', '28 Sep - 04 Oct 2026', '2026-10-04'], [$key, $label, $end->toDateString()], 'Minggu melintasi pergantian bulan.');

        [$key, $label] = SalesByPeriodReport::periodKeyFor(Carbon::parse('2026-10-08 15:00:00'), 'harian');
        $this->assertSame(['2026-10-08', '08 Oct 2026'], [$key, $label]);
    }

    // ------------------------------------------------------------- bucket

    public function test_daily_buckets_include_empty_days(): void
    {
        $this->sale(100000, '2026-10-03');

        $rows = $this->report(['from' => '2026-10-01', 'to' => '2026-10-05', 'granularity' => 'harian'])['rows'];

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'], array_keys($rows));
        $this->assertSame([0, 0, 1, 0, 0], array_column($rows, 'count'));
        $this->assertEquals(0.0, $rows['2026-10-02']['revenue']);
        $this->assertEquals(100000.0, $rows['2026-10-03']['revenue']);
    }

    public function test_weekly_buckets_carry_clamped_ranges_for_drill_down(): void
    {
        $this->sale(100000, '2026-10-06');

        $rows = $this->report(['from' => '2026-09-30', 'to' => '2026-10-12', 'granularity' => 'mingguan'])['rows'];

        $this->assertSame(['2026-09-28', '2026-10-05', '2026-10-12'], array_keys($rows));
        $this->assertSame(['2026-09-30', '2026-10-04'], [$rows['2026-09-28']['bucketFrom'], $rows['2026-09-28']['bucketTo']], 'Awal dijepit ke rentang laporan.');
        $this->assertSame(['2026-10-05', '2026-10-11'], [$rows['2026-10-05']['bucketFrom'], $rows['2026-10-05']['bucketTo']]);
        $this->assertSame(['2026-10-12', '2026-10-12'], [$rows['2026-10-12']['bucketFrom'], $rows['2026-10-12']['bucketTo']], 'Akhir dijepit ke rentang laporan.');
        $this->assertSame(1, $rows['2026-10-05']['count']);
    }

    public function test_monthly_buckets_and_range_edges(): void
    {
        $this->sale(1, '2026-08-01');
        $this->sale(10, '2026-08-31');
        $this->sale(100, '2026-09-15');
        $this->sale(1000, '2026-10-31');
        $this->sale(10000, '2026-11-01');

        $result = $this->report(['from' => '2026-08-01', 'to' => '2026-10-31', 'granularity' => 'bulanan']);

        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_keys($result['rows']));
        $this->assertEquals([11.0, 100.0, 1000.0], array_column($result['rows'], 'revenue'));
        $this->assertEquals(1111.0, $result['totalRevenue']);
        $this->assertSame(4, $result['totalCount']);
    }

    // ------------------------------------------------------------- isi kolom

    public function test_a_bucket_sums_revenue_received_outstanding_and_products_across_all_service_types(): void
    {
        $this->sale(1000000, '2026-10-03', null, ['amount_received' => 600000, 'product_detailing' => true]);                       // 2 produk
        $this->sale(500000, '2026-10-03', null, ['amount_received' => null]);                                                       // 1 produk, dianggap lunas
        $this->sale(300000, '2026-10-05', $this->storeB, ['product_kaca_film' => true, 'product_premium_wash' => true]);           // 3 produk

        $result = $this->report(['from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'bulanan']);
        $row = $result['rows']['2026-10'];

        $this->assertSame(3, $row['count']);
        $this->assertEquals(1800000.0, $row['revenue']);
        $this->assertEquals(1400000.0, $row['received']);
        $this->assertEquals(400000.0, $row['outstanding']);
        $this->assertSame(6, $row['products'], 'PPF + Detailing, PPF, dan Kaca Film + PPF + Premium Wash.');
        $this->assertSame(6, $result['totalProducts']);
    }

    public function test_refunds_are_bucketed_by_the_day_they_were_processed_including_the_first_day_with_a_time(): void
    {
        $paid = $this->sale(1000000, '2026-10-02');
        app(RefundService::class)->process($paid, 100000, null, null);
        DB::table('refunds')->update(['created_at' => '2026-10-01 08:00:00']);

        $result = $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31', 'granularity' => 'bulanan']);

        $this->assertEquals(100000.0, $result['rows']['2026-10']['refund'], 'Refund jam 08:00 di hari pertama ikut walau nilai "Dari" berjam 10:00.');

        DB::table('refunds')->update(['created_at' => '2026-09-15 08:00:00']);
        $outside = $this->report(['from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'bulanan']);
        $this->assertEquals(0.0, $outside['rows']['2026-10']['refund']);
    }

    public function test_commission_gross_profit_and_the_unrated_flag(): void
    {
        $withRate = $this->user('kasir', $this->storeA, ['name' => 'Teknisi Bertarif']);
        $noRate = $this->user('kasir', $this->storeA, ['name' => 'Teknisi Tanpa Tarif']);
        Technician::create(['store_id' => $this->storeA->id, 'user_id' => $withRate->id, 'name' => 'Teknisi Bertarif', 'status' => 'active', 'commission_amount' => 50000]);
        Technician::create(['store_id' => $this->storeA->id, 'user_id' => $noRate->id, 'name' => 'Teknisi Tanpa Tarif', 'status' => 'active']);
        $one = $this->sale(1000000, '2026-10-03');
        $two = $this->sale(500000, '2026-10-04');
        $one->installers()->attach($withRate->id);
        $two->installers()->attach([$withRate->id, $noRate->id]);
        $paid = $this->sale(200000, '2026-10-05');
        app(RefundService::class)->process($paid, 20000, null, null);
        DB::table('refunds')->update(['created_at' => '2026-10-05 12:00:00']);

        $result = $this->report(['from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'bulanan']);
        $row = $result['rows']['2026-10'];

        $this->assertEquals(100000.0, $row['commission'], 'Teknisi bertarif: 2 booking × 50.000; teknisi tanpa tarif tidak disumkan.');
        $this->assertTrue($row['hasUnratedJob']);
        $this->assertEquals(20000.0, $row['refund']);
        $this->assertEquals(1700000.0 - 100000.0 - 20000.0 - 0.0, $row['grossProfit']);
        $this->assertEquals($row['grossProfit'], $result['totalGrossProfit']);
        $this->assertFalse($result['hasMissingCost']);
    }

    // ------------------------------------------------------------- batas & toko

    public function test_a_very_long_range_is_clamped_and_flags_too_many_rows(): void
    {
        $result = $this->report(['from' => '2020-01-01', 'to' => '2026-10-31', 'granularity' => 'harian']);

        $this->assertTrue($result['rangeClamped']);
        $this->assertTrue($result['tooManyBuckets']);
        $this->assertSame('2024-10-31', $result['from']->toDateString(), '730 hari sebelum 31 Okt 2026.');
        $this->assertFalse($this->report(['from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'harian'])['rangeClamped']);
    }

    public function test_admin_filters_by_store_and_staff_are_locked_to_their_own(): void
    {
        $this->sale(100000, '2026-10-03', $this->storeA);
        $this->sale(700000, '2026-10-03', $this->storeB);
        $range = ['from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'bulanan'];

        $this->assertEquals(800000.0, $this->report($range)['totalRevenue']);
        $this->assertEquals(700000.0, $this->report($range + ['store_id' => $this->storeB->id])['totalRevenue']);

        $staff = $this->user('kasir', $this->storeA);
        $result = $this->report($range + ['store_id' => $this->storeB->id], $staff);
        $this->assertEquals(100000.0, $result['totalRevenue'], 'Staf tidak bisa melihat toko lain.');
        $this->assertSame($this->storeA->id, $result['storeId']);
    }

    // ------------------------------------------------------------- akses, URL, tanggal

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(SalesByPeriodReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SalesByPeriodReport']]), 'web');
        $this->assertTrue(SalesByPeriodReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(SalesByPeriodReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_defaults_cover_three_months(): void
    {
        $this->actingAs($this->admin(), 'web');

        $fresh = Livewire::test(SalesByPeriodReport::class);
        $this->assertSame(['2026-08-01', '2026-10-31', 'harian'], [Carbon::parse($fresh->get('data.from'))->toDateString(), Carbon::parse($fresh->get('data.to'))->toDateString(), $fresh->get('data.granularity')]);

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '2026-09-01', 'granularitas' => 'tahunan'])->test(SalesByPeriodReport::class);
        $this->assertSame('harian', $bad->get('data.granularity'));
        $this->assertSame(['2026-08-01', '2026-09-01'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()], 'from rusak jadi default (1 Agu), to yang valid dipertahankan.');

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(SalesByPeriodReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString(), 'to < from dikoreksi.');

        $linked = Livewire::withQueryParams(['from' => '2026-09-05', 'to' => '2026-09-20', 'granularitas' => 'mingguan', 'cabang' => (string) $this->storeB->id])->test(SalesByPeriodReport::class);
        $this->assertSame('mingguan', $linked->get('data.granularity'));
        $this->assertEquals($this->storeB->id, $linked->get('data.store_id'));
    }

    public function test_staff_cannot_choose_a_store_through_the_url(): void
    {
        $this->actingAs($this->user('kasir', $this->storeA), 'web');

        $page = Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(SalesByPeriodReport::class);

        $this->assertNull($page->get('storeId'));
    }

    public function test_an_end_date_before_the_start_is_corrected_and_quick_periods_fill_the_dates(): void
    {
        $page = $this->page();

        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_3_months' => ['2026-08-01', '2026-10-31'], 'this_month' => ['2026-10-01', '2026-10-31'], 'last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    public function test_the_drill_down_link_carries_the_bucket_range_and_store(): void
    {
        $url = urldecode($this->page(['store_id' => $this->storeA->id])->instance()->salesUrl('2026-10-05', '2026-10-11'));

        $this->assertStringContainsString('[entry_date][from]=2026-10-05', $url);
        $this->assertStringContainsString('[entry_date][until]=2026-10-11', $url);
        $this->assertStringContainsString('[store_id][value]=' . $this->storeA->id, $url);
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_keeps_numbers_numeric_and_marks_incomplete_data_with_a_star(): void
    {
        $withRate = $this->user('kasir', $this->storeA);
        $noRate = $this->user('kasir', $this->storeA);
        Technician::create(['store_id' => $this->storeA->id, 'user_id' => $withRate->id, 'name' => 'T1', 'status' => 'active', 'commission_amount' => 50000]);
        Technician::create(['store_id' => $this->storeA->id, 'user_id' => $noRate->id, 'name' => 'T2', 'status' => 'active']);
        $this->sale(1000000, '2026-10-03', null, ['product_detailing' => true])->installers()->attach($withRate->id);
        $this->sale(400000, '2026-10-04')->installers()->attach($noRate->id);

        $result = $this->report(['from' => '2026-10-03', 'to' => '2026-10-05', 'granularity' => 'harian']);
        $export = new SalesByPeriodExport($result);
        $rows = $export->array();

        $this->assertSame(['Periode', 'Transaksi', 'Penjualan', 'Diterima', 'Piutang', 'Produk', 'Pengembalian', 'Komisi', 'HPP', 'Laba Kotor', 'Penjualan/Transaksi', 'Produk/Transaksi'], $export->headings());
        $this->assertCount(3, $rows);
        $this->assertSame(['03 Oct 2026', 1, 1000000.0, 1000000.0, 0.0, 2, 0.0], array_slice($rows[0], 0, 7));
        $this->assertSame(50000.0, $rows[0][7], 'Komisi lengkap tetap angka.');
        $this->assertSame(0.0, $rows[0][8]);
        $this->assertSame([1000000.0, 2.0], [$rows[0][10], $rows[0][11]]);
        $this->assertSame('0 *', $rows[1][7], 'Teknisi tanpa tarif: ditandai bintang.');
        $this->assertSame([0, 0.0, 0], [$rows[2][1], $rows[2][2], $rows[2][10]], 'Periode kosong: nol, bukan error.');
    }

    // ------------------------------------------------------------- tampilan, PDF, grafik

    public function test_page_shows_totals_banners_and_a_drill_down_link_for_non_empty_rows(): void
    {
        $this->sale(1800000, '2026-10-03');
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '081377777777']);
        Booking::create(['booking_number' => 'BKG-PEND', 'customer_id' => $customer->id, 'store_id' => $this->storeA->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-02', 'status' => 'completed', 'transaction_amount' => 1000]);

        $page = $this->page(['from' => '2020-01-01', 'to' => '2026-10-31', 'granularity' => 'harian']);

        $page->assertSuccessful()
            ->assertSee('Total Penjualan (Seluruh Rentang)')
            ->assertSee('Rp1.800.000')
            ->assertSee('sudah selesai tapi belum diproses ke pendapatan')
            ->assertSee('dibatasi maksimal 2 tahun terakhir')
            ->assertSee('Pertimbangkan granularitas lebih kasar')
            ->assertSee('Lihat Detail Penjualan periode ini');
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->sale(100000, '2026-10-03', $this->storeA);
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'bulanan'], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('penjualan-per-periode-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
        $this->assertEquals(['bulanan', 'bulanan'], $logs->map(fn ($l) => $l->properties['granularity'])->all());
    }

    public function test_pdf_renders_with_data_and_for_an_empty_range(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->sale(100000, '2026-10-03', null, ['product_premium_wash' => true]);
        $this->page(['granularity' => 'mingguan'])->callAction('exportPdf')->assertHasNoActionErrors();
    }

    public function test_the_chart_groups_like_the_table_and_counts_all_four_service_types(): void
    {
        $this->sale(1000000, '2026-10-03', null, ['product_detailing' => true, 'product_premium_wash' => true]);   // 3 produk
        $this->sale(500000, '2026-10-04');                                                                          // 1 produk
        $this->actingAs($this->admin(), 'web');

        $chart = Livewire::test(SalesByPeriodChart::class, ['from' => '2026-10-01', 'to' => '2026-10-31', 'granularity' => 'bulanan', 'storeId' => null]);
        $method = new ReflectionMethod(SalesByPeriodChart::class, 'getData');
        $method->setAccessible(true);
        $data = $method->invoke($chart->instance());
        $datasets = collect($data['datasets'])->keyBy('label');

        $this->assertSame(['October 2026'], $data['labels']);
        $this->assertEquals([1500000.0], $datasets['Penjualan (Rp)']['data']);
        $this->assertSame([2], $datasets['Transaksi']['data']);
        $this->assertSame([4], $datasets['Produk']['data']);
    }
}
