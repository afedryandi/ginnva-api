<?php

namespace Tests\Feature;

use App\Exports\PeakProductTimeReportExport;
use App\Filament\Pages\PeakProductTimeReport;
use App\Models\Booking;
use App\Models\BookingFilmProduct;
use App\Models\Refund;
use App\Models\Customer;
use App\Models\FilmProduct;
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
 * Waktu Teramai Produk: penjualan berbayar dikelompokkan per varian produk (SKU) x hari dalam seminggu menurut tanggal
 * jurnal -- jumlah, nilai, persentase; transaksi tanpa SKU dikelompokkan "Belum Diisi SKU" dan selalu di bawah; produk yang
 * sudah dihapus dari katalog tetap bernama; cakupan toko; batas rentang 2 tahun; sanitasi URL; Excel/PDF + log.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PeakProductTimeReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private FilmProduct $ppf;
    private FilmProduct $kaca;
    private FilmProduct $old;

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
        $this->ppf = $this->product('PPF-01', 'Film Premium');
        $this->kaca = $this->product('KF-01', 'Kaca Film Hitam');
        $this->old = $this->product('OLD-1', 'Produk Lama');
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

    private function product(string $sku, string $name): FilmProduct
    {
        return FilmProduct::create(['sku' => $sku, 'name' => $name, 'product_type' => 'window_film', 'position' => 'front', 'base_price' => 100000, 'is_active' => true]);
    }

    private function sale(float $amount, string $date, Store $store, ?FilmProduct $product): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id, 'film_product_id' => $product?->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $date, 'status' => 'completed', 'transaction_amount' => $amount, 'amount_received' => $amount,
        ]);
        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    /**
     * PPF-01: Senin 5 Okt (A, 1.000.000) + Senin 12 Okt (A, 500.000) + Sabtu 3 Okt (B, 400.000). KF-01: Selasa 6 Okt (A, 300.000).
     * OLD-1 (dihapus dari katalog): Senin 12 Okt (B, 200.000). Tanpa SKU: Rabu 7 Okt (A, 100.000). Di luar rentang: 28 Sep.
     */
    private function october(): void
    {
        $this->sale(1000000, '2026-10-05', $this->storeA, $this->ppf);
        $this->sale(500000, '2026-10-12', $this->storeA, $this->ppf);
        $this->sale(400000, '2026-10-03', $this->storeB, $this->ppf);
        $this->sale(300000, '2026-10-06', $this->storeA, $this->kaca);
        $this->sale(200000, '2026-10-12', $this->storeB, $this->old);
        $this->sale(100000, '2026-10-07', $this->storeA, null);
        $this->sale(900000, '2026-09-28', $this->storeA, $this->ppf);
        $this->old->delete();
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(PeakProductTimeReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    private function labels(array $result): array
    {
        return collect($result['rows'])->map(fn ($r) => ($r['product']?->sku ?? 'none') . '|' . $r['dayName'])->all();
    }

    public function test_sales_are_grouped_by_product_and_weekday_and_sorted(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(['PPF-01|Senin', 'KF-01|Selasa', 'OLD-1|Senin', 'PPF-01|Sabtu', 'none|Rabu'], $this->labels($result), 'Terbanyak dulu, seri menurut SKU, tanpa SKU di bawah.');
        $first = $result['rows'][0];
        $this->assertSame(2, $first['count']);
        $this->assertEqualsWithDelta(1500000.0, $first['revenue'], 0.001);
        $this->assertEqualsWithDelta(33.333, $first['countPct'], 0.01);
        $this->assertEqualsWithDelta(60.0, $first['revenuePct'], 0.001);
    }

    public function test_totals_unassigned_count_and_percentages_add_up(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(6, $result['totalCount'], 'Penjualan 28 Sep tidak ikut.');
        $this->assertSame(1, $result['unassignedCount']);
        $this->assertEqualsWithDelta(100.0, collect($result['rows'])->sum('countPct'), 0.001);
        $this->assertEqualsWithDelta(100.0, collect($result['rows'])->sum('revenuePct'), 0.001);
    }

    public function test_a_product_removed_from_the_catalogue_keeps_its_name(): void
    {
        $this->october();

        $row = collect($this->report()['rows'])->first(fn ($r) => $r['product']?->sku === 'OLD-1');

        $this->assertSame('Produk Lama', $row['product']->name);
    }

    public function test_an_empty_period_has_no_rows_and_no_division_errors(): void
    {
        $result = $this->report(['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertCount(0, $result['rows']);
        $this->assertSame([0, 0], [$result['totalCount'], $result['unassignedCount']]);

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Belum ada data pada rentang ini.');
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
        $this->assertEqualsCanonicalizing(['OLD-1|Senin', 'PPF-01|Sabtu'], $this->labels($b));

        $staff = $this->user('kasir', $this->storeA);
        $own = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertSame(['PPF-01|Senin', 'KF-01|Selasa', 'none|Rabu'], $this->labels($own), 'Staf tidak bisa melihat toko lain.');
        $this->assertSame(4, $own['totalCount']);
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertCount(0, $result['rows']);
        $this->assertSame(0, $result['totalCount']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(PeakProductTimeReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['PeakProductTimeReport']]), 'web');
        $this->assertTrue(PeakProductTimeReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(PeakProductTimeReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(PeakProductTimeReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(PeakProductTimeReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertSame($this->storeA->id, Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(PeakProductTimeReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_rows_the_unassigned_warning_and_percentages(): void
    {
        $this->october();

        $this->page()
            ->assertSuccessful()
            ->assertSee('PPF-01 — Film Premium')
            ->assertSee('OLD-1 — Produk Lama')
            ->assertSee('Belum Diisi SKU')
            ->assertSee('1 dari 6 transaksi belum diisi varian produk (SKU)', false)
            ->assertSee('Rp1.500.000', false)
            ->assertSee('60.0%', false)
            ->assertSee('Senin')
            ->assertSee('Rabu');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $this->october();

        $export = new PeakProductTimeReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Produk', 'Hari', 'Jumlah', 'Jumlah (%)', 'Penjualan (Rp)', 'Penjualan (%)'], $export->headings());
        $this->assertCount(5, $rows);
        $this->assertSame(['PPF-01 - Film Premium', 'Senin', 2, 33.3, 1500000.0, 60.0], $rows[0]);
        $this->assertSame(['Belum Diisi SKU', 'Rabu', 1, 16.7, 100000.0, 4.0], $rows[4]);
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('peak_product_time', $logs->first()->properties['report']);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }

    public function test_a_multi_product_booking_counts_once_for_each_product_and_splits_its_value_equally(): void
    {
        // Senin 5 Okt, toko A, 900.000: produk utama PPF-01 + tambahan KF-01 + OLD-1 (duplikat produk utama diabaikan).
        $booking = $this->sale(900000, '2026-10-05', $this->storeA, $this->ppf);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $this->kaca->id, 'position' => 'depan']);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $this->old->id, 'position' => 'belakang']);
        BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $this->ppf->id, 'position' => 'atap']);

        $result = $this->report();
        $monday = collect($result['rows'])->where('dayName', 'Senin')->keyBy(fn ($r) => $r['product']->sku);

        $this->assertSame(1, $result['totalCount'], 'Satu booking.');
        $this->assertSame(3, $result['totalLines'], 'Tiga baris produk.');
        $this->assertSame([1, 1, 1], [$monday['PPF-01']['count'], $monday['KF-01']['count'], $monday['OLD-1']['count']]);
        $this->assertEqualsWithDelta(300000.0, $monday['KF-01']['revenue'], 0.001);
        $this->assertEqualsWithDelta(900000.0, collect($result['rows'])->sum('revenue'), 0.001, 'Total nilai tetap sama dengan penjualan sungguhan.');
        $this->assertEqualsWithDelta(100.0, collect($result['rows'])->sum('countPct'), 0.001);
        $this->assertEqualsWithDelta(33.333, $monday['KF-01']['countPct'], 0.01);
    }

    public function test_a_booking_with_only_extra_products_is_grouped_by_them_and_unassigned_ones_stay_unassigned(): void
    {
        $extraOnly = $this->sale(200000, '2026-10-06', $this->storeA, null);
        BookingFilmProduct::create(['booking_id' => $extraOnly->id, 'film_product_id' => $this->kaca->id, 'position' => 'depan']);
        $this->sale(100000, '2026-10-07', $this->storeA, null);

        $result = $this->report();

        $this->assertSame(1, $result['unassignedCount'], 'Hanya yang tanpa produk sama sekali.');
        $this->assertSame(['KF-01|Selasa', 'none|Rabu'], $this->labels($result));
    }

    public function test_sales_are_net_of_refunds(): void
    {
        $booking = $this->sale(500000, '2026-10-05', $this->storeA, $this->ppf);
        Refund::create(['refund_number' => 'RF-TEST-5', 'booking_id' => $booking->id, 'amount' => 125000]);

        $result = $this->report();

        $this->assertEqualsWithDelta(375000.0, $result['rows'][0]['revenue'], 0.001);
        $this->assertEqualsWithDelta(100.0, $result['rows'][0]['revenuePct'], 0.001);
    }

}
