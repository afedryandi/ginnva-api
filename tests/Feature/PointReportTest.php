<?php

namespace Tests\Feature;

use App\Exports\PointReportExport;
use App\Filament\Pages\PointReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Store;
use App\Models\User;
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
 * Laporan Poin: rincian harian poin Customer + Partner (didapat/ditukar, jumlah transaksi, nilai Rp transaksi booking
 * yang memicu poin), batas rentang 2 tahun, sanitasi URL, Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PointReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Siti', 'phone_number' => '081300000001']);
        $this->partner = Partner::create(['user_id' => $this->user('kasir')->id, 'business_name' => 'Bengkel Mitra', 'referral_code' => 'MITRA1', 'status' => 'active']);
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

    private function booking(float $amount): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-05', 'status' => 'completed', 'transaction_amount' => $amount,
        ]);
    }

    private function customerTx(string $type, int $points, string $at, ?string $refType = null, ?int $refId = null): void
    {
        $tx = PointTransaction::create(['customer_id' => $this->customer->id, 'type' => $type, 'points' => $points, 'description' => 'tes', 'reference_type' => $refType, 'reference_id' => $refId]);
        DB::table('point_transactions')->where('id', $tx->id)->update(['created_at' => $at]);
    }

    private function partnerTx(string $type, int $points, string $at, ?string $refType = null, ?int $refId = null): void
    {
        $tx = PartnerPointTransaction::create(['partner_id' => $this->partner->id, 'type' => $type, 'points' => $points, 'description' => 'tes', 'reference_type' => $refType, 'reference_id' => $refId]);
        DB::table('partner_point_transactions')->where('id', $tx->id)->update(['created_at' => $at]);
    }

    /**
     * 2 Okt: customer dapat 100 (booking 1.000.000) + 50 (garansi, tanpa Rp), partner dapat 200 (booking 500.000).
     * 3 Okt: customer tukar 30, partner tukar 40. Di luar rentang: 999 (30 Sep), 777 (partner, 1 Nov).
     */
    private function october(): void
    {
        $b1 = $this->booking(1000000);
        $b2 = $this->booking(500000);

        $this->customerTx('earn', 100, '2026-10-02 10:00:00', 'booking', $b1->id);
        $this->customerTx('earn', 50, '2026-10-02 15:00:00', 'warranty', 1);
        $this->partnerTx('earn', 200, '2026-10-02 18:00:00', 'booking', $b2->id);
        $this->customerTx('spend', 30, '2026-10-03 09:00:00');
        $this->partnerTx('spend', 40, '2026-10-03 11:00:00');
        $this->customerTx('earn', 999, '2026-09-30 23:59:00');
        $this->partnerTx('earn', 777, '2026-11-01 00:00:00');
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(PointReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    public function test_daily_rows_combine_customer_and_partner_points(): void
    {
        $this->october();

        $result = $this->report();
        $day2 = $result['rows']['2026-10-02'];
        $day3 = $result['rows']['2026-10-03'];

        $this->assertCount(31, $result['rows'], 'Satu baris per hari, termasuk hari kosong.');
        $this->assertSame([350, 3], [$day2['earned'], $day2['earnCount']]);
        $this->assertEqualsWithDelta(1500000.0, $day2['earnRp'], 0.001, 'Hanya poin dari booking yang membawa nilai Rp (1.000.000 + 500.000); garansi tidak.');
        $this->assertSame([0, 0], [$day2['spent'], $day2['spentCount']]);
        $this->assertSame([70, 2], [$day3['spent'], $day3['spentCount']]);
        $this->assertSame([0, 0, 0, 0], [$result['rows']['2026-10-10']['earned'], $result['rows']['2026-10-10']['earnCount'], $result['rows']['2026-10-10']['spent'], $result['rows']['2026-10-10']['spentCount']]);
        $this->assertSame(350, $result['totalEarned']);
        $this->assertSame(70, $result['totalSpent']);
        $this->assertFalse($result['rangeClamped']);
    }

    public function test_range_is_inclusive_and_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $this->customerTx('earn', 10, '2026-10-01 08:00:00');
        $this->customerTx('earn', 5, '2026-10-31 23:59:00');
        $this->customerTx('earn', 1000, '2026-09-30 23:59:59');

        $result = $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31']);

        $this->assertSame(15, $result['totalEarned'], 'Transaksi 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
        $this->assertSame(10, $result['rows']['2026-10-01']['earned']);
    }

    public function test_an_unknown_reference_booking_contributes_points_but_no_rupiah(): void
    {
        $this->customerTx('earn', 20, '2026-10-04 10:00:00', 'booking', 999999);

        $row = $this->report()['rows']['2026-10-04'];

        $this->assertSame(20, $row['earned']);
        $this->assertEquals(0, $row['earnRp']);
    }

    public function test_ranges_longer_than_two_years_are_cut_and_flagged(): void
    {
        $result = $this->report(['from' => '2020-01-01', 'to' => '2026-10-31']);

        $this->assertTrue($result['rangeClamped']);
        $this->assertCount(731, $result['rows']);
        $this->assertSame('2024-10-31', $result['from']->toDateString());

        $this->page(['from' => '2020-01-01', 'to' => '2026-10-31'])->assertSee('Rentang tanggal yang dipilih terlalu panjang');
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(PointReport::canAccess());

        $this->actingAs($this->user('kasir', $this->store, ['menu_access' => ['PointReport']]), 'web');
        $this->assertTrue(PointReport::canAccess());

        $this->actingAs($this->user('kasir', $this->store, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(PointReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => ''])->test(PointReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(PointReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_totals_rows_and_the_not_applicable_card(): void
    {
        $this->october();

        $this->page()
            ->assertSuccessful()
            ->assertSee('Total Poin Didapatkan')
            ->assertSee('+350')
            ->assertSee('-70')
            ->assertSee('Total Poin Dibatalkan')
            ->assertSee('Tidak berlaku')
            ->assertSee('02 Oct 2026')
            ->assertSee('Rp1.500.000', false);
    }

    public function test_excel_rows_are_aligned_and_rupiah_is_a_number(): void
    {
        $this->october();

        $export = new PointReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Tanggal', 'Poin Didapat', 'Transaksi Dapat Poin', 'Poin Didapat (Rp)', 'Poin Ditukar', 'Transaksi Tukar Poin'], $export->headings());
        $this->assertCount(31, $rows);
        $this->assertSame(['02 Oct 2026', 350, 3, 1500000.0, 0, 0], $rows[1]);
        $this->assertSame(['03 Oct 2026', 0, 0, 0.0, 70, 2], $rows[2]);
        $this->assertSame('#,##0;(#,##0);"-"', $export->columnFormats()['D']);
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->october();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page([], $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-poin-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['xlsx', 'pdf'], $logs->map(fn ($l) => $l->properties['format'])->all());
        $this->assertSame('point', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }

    public function test_a_booking_that_gives_points_to_a_customer_and_a_partner_is_counted_once_in_rupiah(): void
    {
        $booking = $this->booking(1000000);
        $this->customerTx('earn', 100, '2026-10-02 10:00:00', 'booking', $booking->id);
        $this->partnerTx('earn', 50, '2026-10-02 11:00:00', 'booking', $booking->id);

        $day = $this->report()['rows']['2026-10-02'];

        $this->assertSame([150, 2], [$day['earned'], $day['earnCount']], 'Poin dan jumlah transaksi poin tetap lengkap.');
        $this->assertEqualsWithDelta(1000000.0, $day['earnRp'], 0.001, 'Nilai booking yang sama hanya dihitung sekali.');
    }

}
