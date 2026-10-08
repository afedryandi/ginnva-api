<?php

namespace Tests\Feature;

use App\Filament\Pages\SalesDashboard;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\RefundService;
use App\Services\SalesSnapshotService;
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
 * Dashboard Penjualan: rentang & periode pembanding, definisi omzet (booking yang sudah dijurnal, dikurangi refund),
 * booking selesai yang belum diproses, akumulasi/proyeksi/pertumbuhan bulan berjalan, dan halamannya
 * (sanitasi query string, navigasi periode yang tidak bisa melewati hari ini, filter cabang yang dikunci untuk
 * staf toko, kartu angka, ranking teknisi, kondisi kosong).
 * "Hari ini" dibekukan di Kamis 8 Oktober 2026.
 */
class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private SalesSnapshotService $service;

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
        $this->service = app(SalesSnapshotService::class);
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

    /** Booking selesai yang sudah dijurnal; tanggal jurnal (acuan semua laporan penjualan) diatur ke $date. */
    private function sale(float $amount, string $date, ?Store $store = null, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => $date,
            'status' => 'completed',
            'transaction_amount' => $amount,
            'amount_received' => $amount,
        ], $overrides));

        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking;
    }

    /** Booking selesai yang belum diproses ke pendapatan (tanpa jurnal). */
    private function unprocessed(float $amount, ?Store $store = null, string $status = 'completed'): Booking
    {
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(10000000, 99999999)]);

        return Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-06', 'status' => $status, 'transaction_amount' => $amount,
        ]);
    }

    /** Oktober 2026: A 1.000.000 (terbayar 600.000, 2 produk), A 500.000 (amount_received kosong), B 300.000. */
    private function october(): array
    {
        return [
            $this->sale(1000000, '2026-10-03', $this->storeA, ['amount_received' => 600000, 'product_kaca_film' => true]),
            $this->sale(500000, '2026-10-08', $this->storeA, ['amount_received' => null]),
            $this->sale(300000, '2026-10-05', $this->storeB),
        ];
    }

    // ------------------------------------------------------------- rentang

    public function test_ranges_previous_ranges_and_shifts(): void
    {
        $ref = Carbon::parse('2026-10-08');

        $this->assertSame(['2026-10-08', '2026-10-08'], array_map(fn ($d) => $d->toDateString(), $this->service->range('harian', $ref)));
        $this->assertSame(['2026-10-05', '2026-10-11'], array_map(fn ($d) => $d->toDateString(), $this->service->range('mingguan', $ref)), 'Senin–Minggu.');
        $this->assertSame(['2026-10-01', '2026-10-31'], array_map(fn ($d) => $d->toDateString(), $this->service->range('bulanan', $ref)));

        $this->assertSame(['2026-10-07', '2026-10-07'], array_map(fn ($d) => $d->toDateString(), $this->service->previousRange('harian', $ref)));
        $this->assertSame(['2026-09-28', '2026-10-04'], array_map(fn ($d) => $d->toDateString(), $this->service->previousRange('mingguan', $ref)));
        $this->assertSame(['2026-09-01', '2026-09-30'], array_map(fn ($d) => $d->toDateString(), $this->service->previousRange('bulanan', $ref)));
        $this->assertSame(['2025-12-01', '2025-12-31'], array_map(fn ($d) => $d->toDateString(), $this->service->previousRange('bulanan', Carbon::parse('2026-01-15'))));

        $this->assertSame('2026-10-07', $this->service->shift($ref, 'harian', -1)->toDateString());
        $this->assertSame('2026-10-15', $this->service->shift($ref, 'mingguan', 1)->toDateString());
        $this->assertSame('2026-02-28', $this->service->shift(Carbon::parse('2026-01-31'), 'bulanan', 1)->toDateString(), 'Tanpa overflow.');
    }

    // ------------------------------------------------------------- angka

    public function test_summary_counts_only_processed_bookings_and_derives_received_and_outstanding(): void
    {
        $this->october();
        $this->sale(200000, '2026-09-30', $this->storeA);          // di luar rentang
        $this->unprocessed(777000);                                 // tanpa jurnal: tidak ikut

        $all = $this->service->summarize(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), null);

        $this->assertEquals(1800000.0, $all['revenue']);
        $this->assertSame(3, $all['count']);
        $this->assertEquals(1400000.0, $all['received'], 'amount_received kosong dianggap lunas penuh.');
        $this->assertEquals(400000.0, $all['outstanding']);
        $this->assertSame(4, $all['productsSold'], 'Booking 1 = PPF + Kaca Film (2), lainnya 1 produk.');
        $this->assertEquals(1800000.0, $all['net']);
    }

    public function test_summary_filters_by_store_and_has_inclusive_day_boundaries(): void
    {
        $this->october();

        $a = $this->service->summarize(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $this->storeA->id);
        $b = $this->service->summarize(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $this->storeB->id);
        $oneDay = $this->service->summarize(Carbon::parse('2026-10-03'), Carbon::parse('2026-10-03'), null);

        $this->assertEquals(1500000.0, $a['revenue']);
        $this->assertSame(2, $a['count']);
        $this->assertEquals(300000.0, $b['revenue']);
        $this->assertEquals(1000000.0, $oneDay['revenue']);
        $this->assertSame(1, $oneDay['count']);
    }

    public function test_refunds_reduce_net_by_the_day_they_were_processed_and_per_store(): void
    {
        [$first] = $this->october();
        app(RefundService::class)->process($first, 200000, 'Batal sebagian', null);

        $october = fn (?int $store) => $this->service->summarize(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $store);

        $this->assertEquals(200000.0, $october(null)['refund']);
        $this->assertEquals(1600000.0, $october(null)['net']);
        $this->assertEquals(200000.0, $october($this->storeA->id)['refund']);
        $this->assertEquals(0.0, $october($this->storeB->id)['refund']);
        $this->assertEquals(300000.0, $october($this->storeB->id)['net']);

        DB::table('refunds')->update(['created_at' => '2026-09-15 09:00:00']);
        $this->assertEquals(0.0, $october(null)['refund'], 'Refund dihitung di hari diprosesnya.');
        $this->assertEquals(200000.0, $this->service->summarize(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), null)['refund']);
    }

    public function test_pending_count_is_completed_bookings_without_a_journal(): void
    {
        $this->october();
        $this->unprocessed(100000, $this->storeA);
        $this->unprocessed(100000, $this->storeB);
        $this->unprocessed(100000, $this->storeA, 'pending');

        $this->assertSame(2, $this->service->pendingCount(null));
        $this->assertSame(1, $this->service->pendingCount($this->storeA->id));
        $this->assertSame(1, $this->service->pendingCount($this->storeB->id));
    }

    public function test_snapshot_month_to_date_projection_and_growth_against_the_same_days_last_month(): void
    {
        $this->sale(1000000, '2026-10-03', $this->storeA);
        $this->sale(500000, '2026-10-08', $this->storeA);
        $this->sale(400000, '2026-09-05', $this->storeA);
        $this->sale(999000, '2026-09-20', $this->storeA);   // setelah hari ke-8: tidak ikut pembanding

        $snapshot = $this->service->snapshot('harian', Carbon::parse('2026-10-08'), null);

        $this->assertEquals(500000.0, $snapshot['current']['revenue']);
        $this->assertEquals(0.0, $snapshot['previous']['revenue']);
        $this->assertEquals(1500000.0, $snapshot['monthToDateNet']);
        $this->assertEquals(1500000.0 / 8 * 31, $snapshot['projection']);
        $this->assertEquals(1100000.0, $snapshot['growthDelta']);
        $this->assertTrue($snapshot['growthHasComparison']);
        $this->assertSame(['from' => '2026-10-08', 'to' => '2026-10-08'], $snapshot['range']);
        $this->assertSame(['from' => '2026-10-07', 'to' => '2026-10-07'], $snapshot['previousRange']);
    }

    public function test_growth_has_no_comparison_without_last_month_sales(): void
    {
        $this->sale(1000000, '2026-10-03', $this->storeA);

        $snapshot = $this->service->snapshot('bulanan', Carbon::parse('2026-10-08'), null);

        $this->assertFalse($snapshot['growthHasComparison']);
        $this->assertEquals(0.0, $this->service->snapshot('harian', Carbon::parse('2026-01-01'), null)['current']['revenue']);
    }

    // ------------------------------------------------------------- akses & query string

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(SalesDashboard::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertTrue(SalesDashboard::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SalesDashboard']]), 'web');
        $this->assertTrue(SalesDashboard::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(SalesDashboard::canAccess());
    }

    public function test_the_query_string_is_sanitised_on_load(): void
    {
        $this->actingAs($this->admin(), 'web');

        $bad = Livewire::withQueryParams(['periode' => 'tahunan', 'tanggal' => '2027-01-01'])->test(SalesDashboard::class);
        $this->assertSame('harian', $bad->get('period'));
        $this->assertSame('2026-10-08', $bad->get('referenceDate'), 'Tanggal masa depan dijepit ke hari ini.');

        $garbage = Livewire::withQueryParams(['periode' => 'bulanan', 'tanggal' => 'bukan tanggal'])->test(SalesDashboard::class);
        $this->assertSame('bulanan', $garbage->get('period'));
        $this->assertSame('2026-10-08', $garbage->get('referenceDate'));

        $valid = Livewire::withQueryParams(['periode' => 'mingguan', 'tanggal' => '2026-09-10'])->test(SalesDashboard::class);
        $this->assertSame('mingguan', $valid->get('period'));
        $this->assertSame('2026-09-10', $valid->get('referenceDate'));

        $fresh = Livewire::test(SalesDashboard::class);
        $this->assertSame(['harian', '2026-10-08'], [$fresh->get('period'), $fresh->get('referenceDate')]);
    }

    // ------------------------------------------------------------- navigasi

    public function test_set_period_ignores_unknown_values_and_resets_to_today(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class)->call('goPrev')->call('setPeriod', 'tahunan');

        $this->assertSame('harian', $page->get('period'));
        $this->assertSame('2026-10-07', $page->get('referenceDate'));

        $page->call('setPeriod', 'mingguan');
        $this->assertSame(['mingguan', '2026-10-08'], [$page->get('period'), $page->get('referenceDate')]);
    }

    public function test_previous_and_next_never_pass_the_period_containing_today(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class);

        $this->assertFalse($page->instance()->canGoNext());
        $page->call('goNext');
        $this->assertSame('2026-10-08', $page->get('referenceDate'));

        $page->call('goPrev');
        $this->assertSame('2026-10-07', $page->get('referenceDate'));
        $this->assertTrue($page->instance()->canGoNext());
        $page->call('goNext');
        $this->assertSame('2026-10-08', $page->get('referenceDate'));

        $page->call('setPeriod', 'bulanan')->call('goPrev');
        $this->assertSame('2026-09-08', $page->get('referenceDate'));
        $this->assertSame('01 Sep 2026 - 30 Sep 2026', $page->instance()->getRangeLabel());
    }

    public function test_next_reaches_the_current_month_even_when_the_reference_day_is_later_than_today(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class)->call('setPeriod', 'bulanan')->set('referenceDate', '2026-09-30');

        $this->assertTrue($page->instance()->canGoNext(), 'Oktober sudah dimulai, jadi boleh dibuka.');
        $page->call('goNext');

        $this->assertSame('2026-10-08', $page->get('referenceDate'), 'Acuan dijepit ke hari ini.');
        $this->assertSame('01 Okt 2026 - 31 Okt 2026', $page->instance()->getRangeLabel());
        $this->assertFalse($page->instance()->canGoNext());
    }

    public function test_next_reaches_the_current_week_even_when_the_reference_weekday_is_later_than_today(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class)->call('setPeriod', 'mingguan')->set('referenceDate', '2026-10-02');   // Jumat minggu lalu

        $this->assertTrue($page->instance()->canGoNext());
        $page->call('goNext');

        $this->assertSame('2026-10-08', $page->get('referenceDate'));
        [$start, $end] = $page->instance()->currentRange();
        $this->assertSame(['2026-10-05', '2026-10-11'], [$start->toDateString(), $end->toDateString()]);
        $this->assertFalse($page->instance()->canGoNext());
    }

    public function test_the_jump_to_date_input_is_clamped_and_survives_garbage(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class);

        $page->set('referenceDate', '2026-09-10');
        $this->assertSame('2026-09-10', $page->get('referenceDate'));

        $page->set('referenceDate', '2030-01-01');
        $this->assertSame('2026-10-08', $page->get('referenceDate'));

        $page->set('referenceDate', 'ngawur');
        $this->assertSame('2026-10-08', $page->get('referenceDate'));
    }

    // ------------------------------------------------------------- cabang

    public function test_admins_choose_the_store_while_store_staff_are_locked_to_their_own(): void
    {
        $this->october();
        $inactive = Store::create(['city' => 'Medan', 'address' => 'Jl. C', 'name' => 'Toko Tutup', 'is_active' => false]);

        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class)->call('setPeriod', 'bulanan');
        $this->assertNull($page->instance()->effectiveStoreId());
        $this->assertEquals(1800000.0, $page->instance()->getResult()['current']['revenue']);
        $page->set('storeId', $this->storeB->id);
        $this->assertSame($this->storeB->id, $page->instance()->effectiveStoreId());
        $this->assertEquals(300000.0, $page->instance()->getResult()['current']['revenue']);
        $this->assertSame([$this->storeA->id => 'Toko A', $this->storeB->id => 'Toko B'], $page->instance()->getStoreOptions(), 'Toko nonaktif tidak ditawarkan.');
        $this->assertArrayNotHasKey($inactive->id, $page->instance()->getStoreOptions());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $staff = Livewire::withQueryParams(['periode' => 'bulanan', 'cabang' => (string) $this->storeB->id])->test(SalesDashboard::class);
        $this->assertSame($this->storeA->id, $staff->instance()->effectiveStoreId(), 'Parameter cabang diabaikan.');
        $this->assertEquals(1500000.0, $staff->instance()->getResult()['current']['revenue']);
        $staff->set('storeId', $this->storeB->id);
        $this->assertEquals(1500000.0, $staff->instance()->getResult()['current']['revenue']);
    }

    // ------------------------------------------------------------- tampilan

    public function test_cards_show_net_sales_refund_receivable_and_projection(): void
    {
        [$first] = $this->october();
        app(RefundService::class)->process($first, 200000, 'Batal sebagian', null);
        $this->actingAs($this->admin(), 'web');

        Livewire::test(SalesDashboard::class)->call('setPeriod', 'bulanan')
            ->assertSuccessful()
            ->assertSee('Rp1.600.000')
            ->assertSee('setelah pengembalian Rp200.000')
            ->assertSee('kotor Rp1.800.000')
            ->assertSee('Rp400.000')
            ->assertSee('Rp1.400.000')
            ->assertSee('01 Okt 2026 - 31 Okt 2026');
    }

    public function test_the_pending_banner_the_empty_state_and_the_growth_banner(): void
    {
        $this->sale(400000, '2026-09-05', $this->storeA);
        $this->sale(1000000, '2026-10-03', $this->storeA);
        $this->unprocessed(100000);
        $this->actingAs($this->admin(), 'web');

        $page = Livewire::test(SalesDashboard::class)->call('setPeriod', 'bulanan');
        $page->assertSee('sudah selesai tapi belum diproses ke pendapatan')->assertSee('meningkat senilai')->assertSee('Rp600.000');

        $page->set('referenceDate', '2026-01-10')->call('setPeriod', 'harian')->set('referenceDate', '2026-01-10');
        $page->assertSee('Belum ada penjualan tercatat untuk')->assertDontSee('Total Penjualan');
    }

    public function test_technician_ranking_is_ordered_by_sales_and_scoped_to_the_stores_technicians(): void
    {
        [$a1, $a2, $b1] = $this->october();
        $budi = $this->user('kasir', $this->storeA, ['name' => 'Budi Teknisi']);
        $andi = $this->user('kasir', $this->storeB, ['name' => 'Andi Teknisi']);
        Technician::create(['store_id' => $this->storeA->id, 'user_id' => $budi->id, 'name' => 'Budi Teknisi', 'status' => 'active']);
        Technician::create(['store_id' => $this->storeB->id, 'user_id' => $andi->id, 'name' => 'Andi Teknisi', 'status' => 'active']);
        $a1->installers()->attach($budi->id);
        $a2->installers()->attach($budi->id);
        $b1->installers()->attach($andi->id);
        $a1->installers()->attach($andi->id);          // booking tim: kredit penuh untuk keduanya

        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class)->call('setPeriod', 'bulanan');

        $all = $page->instance()->getTechnicianRanking();
        $this->assertSame(['Budi Teknisi', 'Andi Teknisi'], array_column($all, 'name'));
        $this->assertSame([2, 2], array_column($all, 'jobCount'));
        $this->assertSame([1500000.0, 1300000.0], array_column($all, 'salesTotal'));

        $page->set('storeId', $this->storeB->id);
        $this->assertSame(['Andi Teknisi'], array_column($page->instance()->getTechnicianRanking(), 'name'), 'Filter cabang membatasi teknisinya.');
        $page->assertSee('Ranking Teknisi')->assertSee('Andi Teknisi');
    }

    public function test_the_ranking_is_empty_without_technicians_or_sales_in_the_period(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = Livewire::test(SalesDashboard::class)->call('setPeriod', 'bulanan');
        $this->assertSame([], $page->instance()->getTechnicianRanking());

        $user = $this->user('kasir', $this->storeA);
        Technician::create(['store_id' => $this->storeA->id, 'user_id' => $user->id, 'name' => 'Tanpa Job', 'status' => 'active']);
        $this->assertSame([], $page->instance()->getTechnicianRanking());
        $page->assertDontSee('Ranking Teknisi');
    }

    public function test_the_page_renders_for_store_staff_with_the_charts(): void
    {
        $this->october();
        $this->actingAs($this->user('kasir', $this->storeA), 'web');

        Livewire::test(SalesDashboard::class)->call('setPeriod', 'bulanan')->assertSuccessful()->assertSee('Rp1.500.000');
    }
}
