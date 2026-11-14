<?php

namespace Tests\Feature;

use App\Exports\LayananReportExport;
use App\Filament\Pages\JenisOrderReport;
use App\Filament\Pages\LayananReport;
use App\Filament\Widgets\LayananChart;
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
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Jasa & Laporan Jenis Order (satu logika, dua halaman): pembagian pendapatan per jenis layanan
 * (PPF / Kaca Film / Detailing / Premium Wash, dibagi rata kalau lebih dari satu; "Lainnya" untuk booking tanpa jenis,
 * sehingga persentase berjumlah 100% dan sama dengan Jurnal Umum), total bersih = kotor − refund, per toko, cakupan toko, izin menu terpisah per halaman,
 * isi Excel/PDF (judul & nama file mengikuti halaman), log ekspor.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class LayananReportTest extends TestCase
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

    public static function pages(): array
    {
        return [
            'laporan jasa' => [LayananReport::class, 'Laporan Jasa', 'laporan-jasa'],
            'laporan jenis order' => [JenisOrderReport::class, 'Laporan Jenis Order', 'laporan-jenis-order'],
        ];
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id, 'menu_access' => ['LayananReport', 'JenisOrderReport']], $extra)), fn (User $u) => $u->assignRole($role));
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

    /**
     * Oktober 2026 -- A: PPF 1.000.000, Kaca Film 400.000, PPF + Detailing 100.000 (dibagi dua); B: PPF + Kaca Film
     * 600.001 (dibagi dua), hanya Detailing 200.000. Kotor 2.300.001.
     */
    private function october(): array
    {
        return [
            'ppf' => $this->sale(1000000, '2026-10-03', $this->storeA),
            'kaca' => $this->sale(400000, '2026-10-04', $this->storeA, ['product_ppf' => false, 'product_kaca_film' => true]),
            'both' => $this->sale(600001, '2026-10-05', $this->storeB, ['product_kaca_film' => true]),
            'detailing' => $this->sale(200000, '2026-10-06', $this->storeB, ['product_ppf' => false, 'product_detailing' => true]),
            'ppfDetailing' => $this->sale(100000, '2026-10-07', $this->storeA, ['product_detailing' => true]),
        ];
    }

    private function page(string $class, array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->admin(), 'web');
        $page = Livewire::test($class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(string $class, array $data = [], ?User $as = null): array
    {
        return $this->page($class, $data, $as)->instance()->getResult();
    }

    // ------------------------------------------------------------- pembagian per jenis

    public function test_revenue_is_split_equally_across_the_service_types_of_a_booking_like_the_journal(): void
    {
        $this->october();

        $result = $this->report(LayananReport::class);
        $type = $result['byType'];

        $this->assertSame(3, $type['ppf']['count'], 'PPF, PPF + Kaca Film, PPF + Detailing.');
        $this->assertSame(2, $type['kaca_film']['count']);
        $this->assertSame(2, $type['detailing']['count'], 'Detailing saja, dan PPF + Detailing.');
        $this->assertSame(0, $type['premium_wash']['count']);
        $this->assertSame(0, $type['lainnya']['count']);
        $this->assertEquals(1350000.5, $type['ppf']['revenue'], '1.000.000 + 300.000,5 + 50.000.');
        $this->assertEquals(700000.5, $type['kaca_film']['revenue'], '400.000 + 300.000,5.');
        $this->assertEquals(250000.0, $type['detailing']['revenue'], '200.000 + 50.000.');
        $this->assertEqualsWithDelta($result['grossRevenue'], array_sum(array_column($type, 'revenue')), 0.001);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($type, 'revenuePct')), 0.0001);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($type, 'countPct')), 0.0001);
    }

    public function test_premium_wash_and_bookings_without_any_type_get_their_own_rows(): void
    {
        $this->sale(300000, '2026-10-03', $this->storeA, ['product_ppf' => false, 'product_premium_wash' => true]);
        $this->sale(90000, '2026-10-04', $this->storeA, ['product_ppf' => false]);

        $type = $this->report(LayananReport::class)['byType'];

        $this->assertEquals([1, 300000.0], [$type['premium_wash']['count'], $type['premium_wash']['revenue']]);
        $this->assertEquals([1, 90000.0], [$type['lainnya']['count'], $type['lainnya']['revenue']], 'Tanpa jenis: dibukukan ke Pendapatan Lain-lain.');
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($type, 'revenuePct')), 0.0001);
    }

    public function test_totals_net_of_refunds_average_and_the_store_breakdown(): void
    {
        $bookings = $this->october();
        app(RefundService::class)->process($bookings['ppf'], 100000, null, null);

        $result = $this->report(LayananReport::class);

        $this->assertSame(5, $result['totalCount']);
        $this->assertEquals(2300001.0, $result['grossRevenue']);
        $this->assertEquals(100000.0, $result['refund']);
        $this->assertEquals(2200001.0, $result['totalRevenue']);
        $this->assertEqualsWithDelta(2200001.0 / 5, $result['avgRevenue'], 0.001);
        $this->assertSame(['Toko A', 'Toko B'], array_keys($result['byStore']), 'Urut pendapatan tertinggi.');
        $this->assertEquals([3, 1500000.0, 100000.0, 1400000.0], [$result['byStore']['Toko A']['count'], $result['byStore']['Toko A']['revenue'], $result['byStore']['Toko A']['refund'], $result['byStore']['Toko A']['net']]);
        $this->assertEquals([2, 800001.0, 0.0, 800001.0], [$result['byStore']['Toko B']['count'], $result['byStore']['Toko B']['revenue'], $result['byStore']['Toko B']['refund'], $result['byStore']['Toko B']['net']]);
    }

    public function test_refunds_follow_the_day_processed_even_when_the_start_date_has_a_time(): void
    {
        $booking = $this->sale(1000000, '2026-10-02', $this->storeA);
        app(RefundService::class)->process($booking, 50000, null, null);
        DB::table('refunds')->update(['created_at' => '2026-10-01 08:00:00']);

        $this->assertEquals(50000.0, $this->report(LayananReport::class, ['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])['refund'], 'Refund jam 08:00 di hari pertama ikut.');

        DB::table('refunds')->update(['created_at' => '2026-09-20 08:00:00']);
        $this->assertEquals(0.0, $this->report(LayananReport::class, ['from' => '2026-10-01', 'to' => '2026-10-31'])['refund']);
    }

    public function test_an_empty_period_has_zero_percentages_and_no_division_errors(): void
    {
        $result = $this->report(LayananReport::class, ['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertSame(0, $result['totalCount']);
        $this->assertEquals(0.0, $result['avgRevenue']);
        $this->assertSame([], $result['byStore']);
        $this->assertTrue(collect($result['byType'])->every(fn ($t) => $t['countPct'] === 0 && $t['revenuePct'] === 0));
    }

    public function test_the_date_range_is_inclusive_on_the_journal_date(): void
    {
        $this->sale(1, '2026-09-30', $this->storeA);
        $this->sale(10, '2026-10-01', $this->storeA);
        $this->sale(100, '2026-10-31', $this->storeA);
        $this->sale(1000, '2026-11-01', $this->storeA);

        $this->assertEquals(110.0, $this->report(LayananReport::class)['grossRevenue']);
    }

    // ------------------------------------------------------------- toko & akses

    public function test_admin_filters_by_store_and_staff_are_locked_to_their_own(): void
    {
        $this->october();

        $this->assertEquals(800001.0, $this->report(LayananReport::class, ['store_id' => (string) $this->storeB->id])['grossRevenue']);

        $staff = $this->user('kasir', $this->storeA);
        $result = $this->report(LayananReport::class, ['store_id' => $this->storeB->id], $staff);
        $this->assertEquals(1500000.0, $result['grossRevenue'], 'Staff tidak bisa melihat toko lain.');
        $this->assertSame(['Toko A'], array_keys($result['byStore']));
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $result = $this->report(LayananReport::class, [], $this->user('kasir', null));

        $this->assertSame(0, $result['totalCount']);
        $this->assertSame([], $result['byStore']);
    }

    public function test_each_page_has_its_own_menu_permission(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(LayananReport::canAccess());
        $this->assertTrue(JenisOrderReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['LayananReport']]), 'web');
        $this->assertTrue(LayananReport::canAccess());
        $this->assertFalse(JenisOrderReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['JenisOrderReport']]), 'web');
        $this->assertFalse(LayananReport::canAccess());
        $this->assertTrue(JenisOrderReport::canAccess());
    }

    #[DataProvider('pages')]
    public function test_the_query_string_is_sanitised_and_dates_are_corrected(string $class): void
    {
        $this->actingAs($this->admin(), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test($class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()]);
        $this->assertEquals($this->storeB->id, $bad->get('data.store_id'));

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test($class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test($class)->get('storeIdFilter'));

        $page = $this->page($class);
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    public function test_the_drill_down_link_carries_the_range_and_the_store(): void
    {
        $url = urldecode($this->page(LayananReport::class, ['from' => '2026-10-01', 'to' => '2026-10-15'])->instance()->salesUrl('Toko B'));

        $this->assertStringContainsString('[entry_date][from]=2026-10-01', $url);
        $this->assertStringContainsString('[entry_date][until]=2026-10-15', $url);
        $this->assertStringContainsString('[store_id][value]=' . $this->storeB->id, $url);
    }

    // ------------------------------------------------------------- ekspor & tampilan

    #[DataProvider('pages')]
    public function test_excel_has_the_page_title_all_type_rows_the_store_rows_and_totals(string $class, string $title): void
    {
        $bookings = $this->october();
        app(RefundService::class)->process($bookings['ppf'], 100000, null, null);
        $rows = (new LayananReportExport($this->report($class), $title))->array();
        $by = collect($rows)->keyBy(fn ($r) => $r[0] ?? '');

        $this->assertSame([$title], $rows[0]);
        $this->assertSame(['Periode', '01 Oct 2026 - 31 Oct 2026'], $rows[1]);
        $this->assertSame(5, $by['Jumlah Transaksi'][1]);
        // Nominal berupa ANGKA (bisa dijumlah di Excel), bukan teks berformat; selisih <= 0,51 = pembulatan 0,5 lama.
        $money = function ($value, float $expected, string $message = '') {
            $this->assertIsFloat($value, $message);
            $this->assertEqualsWithDelta($expected, $value, 0.51, $message);
        };
        $money($by['Total Pendapatan (kotor)'][1], 2300001);
        $money($by['Total Pendapatan (bersih)'][1], 2200001);
        $money($by['Pengembalian'][1], -100000);

        // Baris jenis: [jenis, jumlah, jumlah %, pendapatan, pendapatan %, rata-rata, pengembalian, bersih]
        $this->assertSame(3, $by['PPF'][1]);
        $money($by['PPF'][3], 1350001);
        $this->assertIsFloat($by['PPF'][2], 'Persentase berupa angka.');
        $this->assertIsFloat($by['PPF'][4]);
        $money($by['PPF'][6], -100000);
        $money($by['PPF'][7], 1250001);
        $this->assertSame(2, $by['Kaca Film'][1]);
        $money($by['Kaca Film'][3], 700001);
        $this->assertSame(2, $by['Detailing'][1]);
        $money($by['Detailing'][3], 250000);
        $this->assertSame(0, $by['Premium Wash'][1]);
        $this->assertSame(0.0, $by['Premium Wash'][3]);
        $this->assertArrayNotHasKey('Lainnya (tanpa jenis)', $by->all(), 'Baris Lainnya hanya ada kalau ada isinya.');

        // Baris Total (jenis) lalu Total (toko): keyBy menyimpan yang terakhir, jadi cari lewat posisi.
        $totals = collect($rows)->filter(fn ($r) => ($r[0] ?? '') === 'Total')->values();
        $this->assertCount(2, $totals, 'Satu Total di tabel jenis, satu di tabel toko.');
        $this->assertSame(7, $totals[0][1], 'Total slot jenis: PPF 3 + Kaca Film 2 + Detailing 2.');
        $money($totals[0][7], 2200001);
        $this->assertSame(5, $totals[1][1], 'Total toko = jumlah transaksi.');

        // Baris toko: [toko, jumlah, Kaca Film, PPF, Detailing, Premium Wash, pendapatan, pengembalian, bersih]
        $this->assertSame(3, $by['Toko A'][1]);
        $money($by['Toko A'][6], 1500000);
        $money($by['Toko A'][7], -100000);
        $money($by['Toko A'][8], 1400000);
        $this->assertSame(2, $by['Toko B'][1]);
        $money($by['Toko B'][6], 800001);
    }

    #[DataProvider('pages')]
    public function test_page_shows_the_other_row_totals_and_the_refund_note(string $class): void
    {
        $bookings = $this->october();
        app(RefundService::class)->process($bookings['ppf'], 100000, null, null);

        $this->page($class)
            ->assertSuccessful()
            ->assertSee('Detailing')
            ->assertSee('Premium Wash')
            ->assertDontSee('Lainnya')
            ->assertSee('Rata-rata per Transaksi')
            ->assertSee('Pengembalian')
            ->assertSee('Rp2.200.001')
            ->assertSee('Per Toko')
            ->assertSee('Toko A');
    }

    #[DataProvider('pages')]
    public function test_exports_download_with_the_page_name_and_the_log_records_the_effective_store(string $class, string $title, string $slug): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page($class, ['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded($slug . '-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$slug, $slug], $logs->map(fn ($l) => $l->properties['report'])->all());
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(JenisOrderReport::class)->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page(JenisOrderReport::class)->callAction('exportPdf')->assertHasNoActionErrors();
        $this->page(LayananReport::class)->callAction('exportPdf')->assertHasNoActionErrors();
    }

    // ------------------------------------------------------------- pengembalian, toko per jenis, Lainnya, grafik

    public function test_refunds_are_split_by_type_and_store_so_the_net_columns_add_up_to_the_headline(): void
    {
        $bookings = $this->october();
        app(RefundService::class)->process($bookings['both'], 100000, null, null);

        $result = $this->report(LayananReport::class);

        // PPF + Kaca Film dibagi rata: 50.000 ke masing-masing jenis, dan 100.000 ke Toko B.
        $this->assertEquals(50000.0, $result['byType']['ppf']['refund']);
        $this->assertEquals(50000.0, $result['byType']['kaca_film']['refund']);
        $this->assertEquals(0.0, $result['byType']['detailing']['refund']);
        $this->assertEquals(100000.0, $result['byStore']['Toko B']['refund']);
        $this->assertEqualsWithDelta($result['totalRevenue'], array_sum(array_column($result['byType'], 'net')), 0.01, 'Bersih per jenis berjumlah sama dengan headline.');
        $this->assertEqualsWithDelta($result['totalRevenue'], array_sum(array_column($result['byStore'], 'net')), 0.01, 'Bersih per toko berjumlah sama dengan headline.');
    }

    public function test_each_store_row_breaks_revenue_down_by_service_type(): void
    {
        $this->october();

        $stores = $this->report(LayananReport::class)['byStore'];

        $this->assertEquals(1050000.0, $stores['Toko A']['types']['ppf'], 'PPF 1.000.000 + setengah dari PPF + Detailing 100.000.');
        $this->assertEquals(400000.0, $stores['Toko A']['types']['kaca_film']);
        $this->assertEquals(50000.0, $stores['Toko A']['types']['detailing']);
        $this->assertEquals(200000.0, $stores['Toko B']['types']['detailing']);
        $this->assertEquals(300000.5, $stores['Toko B']['types']['ppf']);
        $this->assertEqualsWithDelta($stores['Toko A']['revenue'], array_sum($stores['Toko A']['types']), 0.01);
    }

    public function test_the_other_row_and_column_appear_only_when_a_booking_has_no_type(): void
    {
        $this->sale(90000, '2026-10-04', $this->storeA, ['product_ppf' => false]);

        $this->page(LayananReport::class)->assertSee('Lainnya')->assertSee('Rp90.000');
        $export = (new LayananReportExport($this->report(LayananReport::class)))->array();
        $this->assertContains('Lainnya (tanpa jenis)', array_column($export, 0));
    }

    public function test_the_page_has_total_rows_and_the_chart_follows_the_range_and_all_four_types(): void
    {
        $this->october();
        $this->page(LayananReport::class)->assertSee('Total');

        $this->actingAs($this->admin(), 'web');
        $chart = Livewire::test(LayananChart::class, ['from' => '2026-10-01', 'to' => '2026-10-07', 'storeId' => null]);
        $method = new ReflectionMethod(LayananChart::class, 'getData');
        $method->setAccessible(true);
        $data = $method->invoke($chart->instance());
        $datasets = collect($data['datasets'])->keyBy('label');

        $this->assertSame(['Kaca Film', 'PPF', 'Detailing', 'Premium Wash'], array_keys($datasets->all()));
        $this->assertCount(7, $data['labels'], 'Mengikuti Dari/Sampai halaman, bukan 14/30/90 hari.');
        $this->assertEquals([0, 0, 1000000.0, 0, 300000.5, 0, 50000.0], $datasets['PPF']['data']);
        $this->assertEquals([0, 0, 0, 400000.0, 300000.5, 0, 0], $datasets['Kaca Film']['data']);
        $this->assertEquals([0, 0, 0, 0, 0, 200000.0, 50000.0], $datasets['Detailing']['data']);

        $storeB = Livewire::test(LayananChart::class, ['from' => '2026-10-01', 'to' => '2026-10-07', 'storeId' => $this->storeB->id]);
        $dataB = $method->invoke($storeB->instance());
        $this->assertEquals([0, 0, 0, 0, 300000.5, 0, 0], collect($dataB['datasets'])->keyBy('label')['PPF']['data'], 'Mengikuti filter toko.');
    }
}
