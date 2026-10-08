<?php

namespace Tests\Feature;

use App\Exports\LayananReportExport;
use App\Filament\Pages\JenisOrderReport;
use App\Filament\Pages\LayananReport;
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
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Jasa & Laporan Jenis Order (satu logika, dua halaman): pembagian pendapatan per jenis servis
 * (PPF / Kaca Film / 50:50 untuk keduanya / "Lainnya" untuk booking tanpa keduanya, sehingga persentase
 * berjumlah 100%), total bersih = kotor − refund, per toko, cakupan toko, izin menu terpisah per halaman,
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

    /**
     * Oktober 2026 -- A: PPF 1.000.000, Kaca Film 400.000, PPF + Detailing 100.000; B: PPF + Kaca Film 600.001
     * (dibagi dua), hanya Detailing 200.000. Kotor 2.300.001.
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

    public function test_revenue_is_split_by_service_type_with_an_other_row_so_percentages_reach_100(): void
    {
        $this->october();

        $result = $this->report(LayananReport::class);
        $type = $result['byType'];

        $this->assertSame(3, $type['ppf']['count'], 'PPF, PPF + Kaca Film, dan PPF + Detailing.');
        $this->assertSame(2, $type['kaca_film']['count']);
        $this->assertSame(1, $type['lainnya']['count'], 'Hanya Detailing.');
        $this->assertEquals(1400000.5, $type['ppf']['revenue'], '1.000.000 + 300.000,5 (separuh) + 100.000 (PPF + Detailing seluruhnya ke PPF).');
        $this->assertEquals(700000.5, $type['kaca_film']['revenue'], '400.000 + 300.000,5.');
        $this->assertEquals(200000.0, $type['lainnya']['revenue']);
        $this->assertEqualsWithDelta($result['grossRevenue'], array_sum(array_column($type, 'revenue')), 0.001);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($type, 'revenuePct')), 0.0001);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($type, 'countPct')), 0.0001);
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
        $this->assertEquals(['count' => 3, 'revenue' => 1500000.0], $result['byStore']['Toko A']);
        $this->assertEquals(['count' => 2, 'revenue' => 800001.0], $result['byStore']['Toko B']);
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
        $this->assertEquals(1500000.0, $result['grossRevenue'], 'Staf tidak bisa melihat toko lain.');
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
    public function test_excel_has_the_page_title_the_type_rows_including_other_and_the_store_rows(string $class, string $title): void
    {
        $this->october();
        $rows = (new LayananReportExport($this->report($class), $title))->array();
        $by = collect($rows)->keyBy(fn ($r) => $r[0] ?? '');

        $this->assertSame([$title], $rows[0]);
        $this->assertSame(['Periode', '01 Oct 2026 - 31 Oct 2026'], $rows[1]);
        $this->assertSame(5, $by['Transaksi'][1]);
        $this->assertSame('2.300.001', $by['Total Pendapatan (kotor)'][1]);
        $this->assertSame('2.300.001', $by['Total Pendapatan (bersih)'][1]);
        $this->assertSame([3, '1.400.001'], [$by['PPF'][1], $by['PPF'][3]], 'number_format membulatkan 1.400.000,5 ke atas.');
        $this->assertSame([2, '700.001'], [$by['Kaca Film'][1], $by['Kaca Film'][3]]);
        $this->assertSame(1, $by['Lainnya (Detailing / Premium Wash / tanpa jenis)'][1]);
        $this->assertSame('200.000', $by['Lainnya (Detailing / Premium Wash / tanpa jenis)'][3]);
        $this->assertSame([3, '1.500.000'], [$by['Toko A'][1], $by['Toko A'][2]]);
        $this->assertSame([2, '800.001'], [$by['Toko B'][1], $by['Toko B'][2]]);
    }

    #[DataProvider('pages')]
    public function test_page_shows_the_other_row_totals_and_the_refund_note(string $class): void
    {
        $bookings = $this->october();
        app(RefundService::class)->process($bookings['ppf'], 100000, null, null);

        $this->page($class)
            ->assertSuccessful()
            ->assertSee('Lainnya')
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
}
