<?php

namespace Tests\Feature;

use App\Filament\Pages\SalesDashboard;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\BookingSaleLineService;
use App\Services\SalesKpiService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penjualan Fase 6 (2026-11-13): Indikator Penjualan di Dasbor Penjualan. Waktu dibekukan: Jumat 13 Nov 2026.
 */
class SalesKpiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private Customer $budi;

    private Customer $sari;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->budi = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);
        $this->sari = Customer::create(['name' => 'Sari', 'phone_number' => '081200000002']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Booking selesai yang sudah diproses ke jurnal pada tanggal $date; $lines opsional (rincian harga). */
    private function sale(Customer $customer, float $amount, string $date, array $flags, ?array $lines = null, array $overrides = [], ?Store $store = null): Booking
    {
        $booking = Booking::create(array_merge(
            ['product_ppf' => false, 'product_kaca_film' => false, 'product_detailing' => false, 'product_premium_wash' => false],
            $flags,
            [
                'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->store)->id,
                'service_type' => 'PPF', 'preferred_date' => $date, 'status' => 'completed',
                'transaction_amount' => $amount, 'amount_received' => $amount,
            ],
            $overrides
        ));

        if ($lines !== null) {
            $service = app(BookingSaleLineService::class);
            $service->replace($booking, $service->normalize($lines));
        }

        app(BookingPostingService::class)->sync($booking->fresh());
        DB::table('journal_entries')->where('id', $booking->fresh()->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    private function november(): void
    {
        // Oktober: Budi sudah pernah membeli (jadi pelanggan kembali).
        $this->sale($this->budi, 400000, '2026-10-10', ['product_ppf' => true]);

        // November
        $this->sale($this->budi, 1000000, '2026-11-03', ['product_ppf' => true, 'product_premium_wash' => true], [
            ['service_type' => 'ppf', 'unit_price' => 900000],
            ['service_type' => 'premium_wash', 'unit_price' => 100000],
        ]);
        $this->sale($this->sari, 500000, '2026-11-05', ['product_ppf' => true], null, ['voucher_discount' => 50000]);
        $this->sale($this->budi, 270000, '2026-11-08', ['product_kaca_film' => true], [
            ['service_type' => 'kaca_film', 'unit_price' => 300000],
        ]);
    }

    private function kpis(?int $storeId = null): array
    {
        return app(SalesKpiService::class)->kpis(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), $storeId);
    }

    public function test_totals_average_and_sales_by_service_type(): void
    {
        $this->november();

        $k = $this->kpis();

        $this->assertSame(3, $k['count']);
        $this->assertEquals(1770000.0, $k['revenue']);
        $this->assertEquals(590000.0, $k['avg_ticket']);
        $this->assertEquals(1400000.0, $k['by_type']['ppf']['revenue']);
        $this->assertSame(2, $k['by_type']['ppf']['count']);
        $this->assertEquals(100000.0, $k['by_type']['premium_wash']['revenue']);
        $this->assertEquals(270000.0, $k['by_type']['kaca_film']['revenue']);
        $this->assertEqualsWithDelta(100.0, collect($k['by_type'])->sum('pct'), 0.2, 'Persentase per jenis berjumlah 100%.');
        $this->assertSame('ppf', array_key_first($k['by_type']), 'Urut dari yang terbesar.');
    }

    public function test_multi_service_returning_customers_and_coverage(): void
    {
        $this->november();

        $k = $this->kpis();

        $this->assertSame(1, $k['multi_service']['count']);
        $this->assertEqualsWithDelta(33.3, $k['multi_service']['pct'], 0.05);
        $this->assertSame(2, $k['customers']['total']);
        $this->assertSame(1, $k['customers']['returning'], 'Hanya Budi yang punya pembelian sebelum November.');
        $this->assertEqualsWithDelta(50.0, $k['customers']['pct'], 0.05);
        $this->assertSame(2, $k['lines_coverage']['with_lines']);
        $this->assertEqualsWithDelta(66.7, $k['lines_coverage']['pct'], 0.05);
    }

    public function test_discounts_split_into_voucher_promo_and_line_or_negotiation(): void
    {
        $this->november();

        $d = $this->kpis()['discounts'];

        $this->assertEquals(50000.0, $d['voucher']);
        $this->assertEquals(0.0, $d['promo']);
        $this->assertEquals(30000.0, $d['other'], 'Rincian 300.000, nominal 270.000: selisih 30.000.');
        $this->assertEquals(80000.0, $d['total']);
        $this->assertEqualsWithDelta(4.3, $d['pct_of_gross'], 0.05, '80.000 dari 1.850.000 sebelum potongan.');
        $this->assertSame(1, $d['without_lines'], 'Booking tanpa rincian tidak punya data potongan baris/nego.');
    }

    public function test_store_filter_and_per_store_rows(): void
    {
        $this->november();
        $this->sale($this->sari, 700000, '2026-11-09', ['product_detailing' => true], null, [], $this->otherStore);

        $all = $this->kpis();
        $this->assertSame(4, $all['count']);
        $this->assertSame(['Toko A', 'Toko B'], collect($all['by_store'])->pluck('name')->sort()->values()->all());

        $onlyA = $this->kpis($this->store->id);
        $this->assertSame(3, $onlyA['count']);
        $this->assertCount(1, $onlyA['by_store']);
    }

    public function test_an_empty_period_has_no_division_errors(): void
    {
        $k = $this->kpis();

        $this->assertSame(0, $k['count']);
        $this->assertEquals(0.0, $k['avg_ticket']);
        $this->assertEquals(0.0, $k['multi_service']['pct']);
        $this->assertEquals(0.0, $k['customers']['pct']);
        $this->assertEquals(0.0, $k['discounts']['pct_of_gross']);
    }

    public function test_the_dashboard_shows_the_indicators(): void
    {
        $this->november();
        $admin = tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');

        Livewire::test(SalesDashboard::class)
            ->set('period', 'bulanan')
            ->assertSuccessful()
            ->assertSee('Indikator Penjualan')
            ->assertSee('Transaksi Multi-Layanan')
            ->assertSee('Pelanggan Kembali')
            ->assertSee('Penjualan per Jenis Layanan')
            ->assertSee('Rincian harga tercatat pada 2 dari 3 transaksi');
    }
}
