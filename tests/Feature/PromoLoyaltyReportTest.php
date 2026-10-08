<?php

namespace Tests\Feature;

use App\Exports\PromoLoyaltyReportExport;
use App\Filament\Pages\CouponReport;
use App\Filament\Pages\PromoLoyaltyReport;
use App\Filament\Pages\SalesSummaryReport;
use App\Filament\ReportWidgets\PromoValueChart;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\SpendPromo;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Promo / Laporan Kupon (kelas yang sama): transaksi voucher terpakai (tanggal pakai, per toko, nilai =
 * snapshot potongan di booking, bukan nominal katalog sekarang), Promo Total Pembelian, performa voucher & reward,
 * poin company-wide, grafik, Excel berupa angka, PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PromoLoyaltyReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private Voucher $v1;
    private Voucher $v2;
    private Booking $bookingB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
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
            'Laporan Promo' => [PromoLoyaltyReport::class, 'Laporan Promo', 'laporan-promo'],
            'Laporan Kupon' => [CouponReport::class, 'Laporan Kupon', 'laporan-kupon'],
        ];
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function booking(Store $store, float $amount, array $extra = [], ?string $createdAt = null): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-05', 'status' => 'completed', 'transaction_amount' => $amount,
        ], $extra));
        if ($createdAt) {
            DB::table('bookings')->where('id', $booking->id)->update(['created_at' => $createdAt]);
        }

        return $booking->fresh();
    }

    private function claim(Voucher $voucher, ?Booking $booking, ?string $usedAt, string $createdAt, string $status = 'used'): VoucherClaim
    {
        $claim = VoucherClaim::create([
            'voucher_id' => $voucher->id, 'walkin_name' => 'Walk-in', 'walkin_phone' => '0811', 'code' => strtoupper(uniqid()),
            'status' => $status, 'booking_id' => $booking?->id, 'used_at' => $usedAt,
        ]);
        DB::table('voucher_claims')->where('id', $claim->id)->update(['created_at' => $createdAt]);

        return $claim->fresh();
    }

    /**
     * V1 "Diskon 50rb" (katalog 50.000), V2 "Diskon 100rb" (katalog sekarang 100.000, tapi booking-nya mencatat 80.000).
     * Terpakai di Okt: klaim1 (V1, toko A, 2 Okt, 1.000.000), klaim2 (V2, toko A, 3 Okt, 2.000.000, snapshot 80.000),
     * klaim3 (V1, toko B, 5 Okt, 500.000). Di luar rentang: terpakai 30 Sep. Satu klaim V1 aktif (belum dipakai).
     * Promo Total Pembelian: toko A 4 Okt 25.000, toko B 6 Okt 15.000, toko A 29 Sep 99.000 (di luar rentang).
     */
    private function october(): void
    {
        $this->v1 = Voucher::create(['name' => 'Diskon 50rb', 'discount_amount' => 50000, 'total_stock' => 10, 'claimed_count' => 3, 'is_active' => true]);
        $this->v2 = Voucher::create(['name' => 'Diskon 100rb', 'discount_amount' => 100000, 'total_stock' => 5, 'claimed_count' => 1, 'is_active' => false]);

        $b1 = $this->booking($this->storeA, 1000000);
        $b2 = $this->booking($this->storeA, 2000000, ['voucher_discount' => 80000]);
        $this->bookingB = $this->booking($this->storeB, 500000);
        $old = $this->booking($this->storeA, 700000);

        $this->claim($this->v1, $b1, '2026-10-02 10:00:00', '2026-10-01 09:00:00');
        $this->claim($this->v2, $b2, '2026-10-03 10:00:00', '2026-10-02 09:00:00');
        $this->claim($this->v1, $this->bookingB, '2026-10-05 10:00:00', '2026-10-04 09:00:00');
        $this->claim($this->v1, $old, '2026-09-30 10:00:00', '2026-09-20 09:00:00');
        $this->claim($this->v1, null, null, '2026-10-06 09:00:00', 'active');

        $promo = SpendPromo::create(['name' => 'Belanja 1jt', 'min_purchase_amount' => 1000000, 'discount_amount' => 25000, 'is_active' => true]);
        $this->booking($this->storeA, 900000, ['spend_promo_id' => $promo->id, 'spend_promo_discount' => 25000], '2026-10-04 11:00:00');
        $this->booking($this->storeB, 800000, ['spend_promo_id' => $promo->id, 'spend_promo_discount' => 15000], '2026-10-06 11:00:00');
        $this->booking($this->storeA, 600000, ['spend_promo_id' => $promo->id, 'spend_promo_discount' => 99000], '2026-09-29 11:00:00');
    }

    /** Poin & reward: company-wide. */
    private function loyalty(): void
    {
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '081300000001']);
        foreach ([['earn', 100, '2026-10-03'], ['earn', 50, '2026-10-04'], ['spend', 30, '2026-10-05'], ['earn', 999, '2026-09-30']] as [$type, $points, $date]) {
            $tx = PointTransaction::create(['customer_id' => $customer->id, 'type' => $type, 'points' => $points, 'description' => 'tes']);
            DB::table('point_transactions')->where('id', $tx->id)->update(['created_at' => $date . ' 12:00:00']);
        }

        $partner = Partner::create(['user_id' => $this->user('kasir')->id, 'business_name' => 'Bengkel Mitra', 'referral_code' => 'MITRA1', 'status' => 'active']);
        foreach ([['earn', 200, '2026-10-04'], ['spend', 40, '2026-10-06'], ['earn', 777, '2026-08-01']] as [$type, $points, $date]) {
            $tx = PartnerPointTransaction::create(['partner_id' => $partner->id, 'type' => $type, 'points' => $points, 'description' => 'tes']);
            DB::table('partner_point_transactions')->where('id', $tx->id)->update(['created_at' => $date . ' 12:00:00']);
        }

        $r1 = Reward::create(['name' => 'Voucher Cuci', 'points_cost' => 500, 'stock' => 5, 'is_active' => true]);
        $r2 = Reward::create(['name' => 'Kaos Ginnva', 'points_cost' => 200, 'stock' => null, 'is_active' => true]);
        foreach ([[$r1, 'customer', 'fulfilled', 500, '2026-10-04'], [$r1, 'customer', 'pending', 300, '2026-10-05'], [$r2, 'partner', 'fulfilled', 200, '2026-10-06'], [$r1, 'customer', 'fulfilled', 500, '2026-09-29']] as [$reward, $type, $status, $points, $date]) {
            $red = RewardRedemption::create(['redeemer_type' => $type, 'redeemer_id' => $type === 'partner' ? $partner->id : $customer->id, 'reward_id' => $reward->id, 'points_spent' => $points, 'status' => $status]);
            DB::table('reward_redemptions')->where('id', $red->id)->update(['created_at' => $date . ' 12:00:00']);
        }
    }

    private function page(string $class, array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test($class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null, string $class = PromoLoyaltyReport::class): array
    {
        return $this->page($class, $data, $as)->instance()->getResult();
    }

    // ------------------------------------------------------------- transaksi voucher

    public function test_voucher_transactions_use_the_used_date_and_the_booking_snapshot_value(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(3, $result['promoTransactionCount']);
        // 50.000 (katalog, belum ada snapshot) + 80.000 (snapshot booking, BUKAN 100.000 katalog sekarang) + 50.000.
        $this->assertEqualsWithDelta(180000.0, $result['promoValue'], 0.001);
        $this->assertEqualsWithDelta(3500000.0, $result['promoSalesTotal'], 0.001);
        $this->assertSame(['2026-10-05', '2026-10-03', '2026-10-02'], $result['usedClaims']->map(fn ($c) => $c->used_at->toDateString())->all(), 'Terbaru dulu; klaim terpakai 30 Sep tidak ikut.');
    }

    public function test_changing_the_catalog_amount_later_does_not_rewrite_history(): void
    {
        $this->october();
        $this->v2->update(['discount_amount' => 999000]);

        $this->assertEqualsWithDelta(180000.0, $this->report()['promoValue'], 0.001);
    }

    public function test_the_promo_value_matches_promo_voucher_in_the_sales_summary(): void
    {
        $this->october();
        $this->actingAs($this->user('super_admin'), 'web');
        $summary = Livewire::test(SalesSummaryReport::class)->set('data.from', '2026-10-01')->set('data.to', '2026-10-31')->instance()->getResult();

        $this->assertEqualsWithDelta($this->report()['promoValue'], $summary['voucherDiscount'], 0.001);
    }

    public function test_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $this->october();
        $b = $this->booking($this->storeA, 100000, [], '2026-10-01 00:00:00');
        $this->claim($this->v1, $b, '2026-10-01 08:00:00', '2026-10-01 07:00:00');
        $promo = SpendPromo::first();
        $this->booking($this->storeA, 100000, ['spend_promo_id' => $promo->id, 'spend_promo_discount' => 5000], '2026-10-01 08:30:00');

        $result = $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31']);

        $this->assertSame(4, $result['promoTransactionCount']);
        $this->assertSame(3, $result['spendPromoTransactionCount']);
    }

    // ------------------------------------------------------------- promo total pembelian

    public function test_spend_promo_transactions_by_booking_creation_date(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(2, $result['spendPromoTransactionCount']);
        $this->assertEqualsWithDelta(40000.0, $result['spendPromoDiscountTotal'], 0.001);
        $this->assertSame([15000.0, 25000.0], $result['spendPromoBookings']->map(fn ($b) => (float) $b->spend_promo_discount)->all(), 'Terbaru dulu; yang dibuat 29 Sep tidak ikut.');
    }

    // ------------------------------------------------------------- performa voucher, reward, poin

    public function test_voucher_performance_counts_claims_by_claim_date_and_use_by_use_date(): void
    {
        $this->october();

        $vouchers = $this->report()['vouchers']->keyBy('name');

        $this->assertSame(['Diskon 50rb', 'Diskon 100rb'], $vouchers->keys()->all(), 'Urut klaim terbanyak.');
        $this->assertSame([3, 2], [$vouchers['Diskon 50rb']->claimed_in_period, $vouchers['Diskon 50rb']->used_in_period], 'Klaim dibuat 1/4/6 Okt; dipakai 2 dan 5 Okt (yang dipakai 30 Sep tidak).');
        $this->assertSame([1, 1], [$vouchers['Diskon 100rb']->claimed_in_period, $vouchers['Diskon 100rb']->used_in_period]);
        $this->assertSame(7, $vouchers['Diskon 50rb']->remainingStock());
    }

    public function test_reward_performance_and_points_are_company_wide_and_in_range(): void
    {
        $this->loyalty();

        $result = $this->report();
        $rewards = $result['rewards']->keyBy('name');

        $this->assertSame(['Voucher Cuci', 'Kaos Ginnva'], $rewards->keys()->all());
        $this->assertSame([2, 1, 500], [$rewards['Voucher Cuci']->redeemed_in_period, $rewards['Voucher Cuci']->fulfilled_in_period, (int) $rewards['Voucher Cuci']->points_spent_in_period], 'Pending ikut "Ditukar", tidak ikut "Terpenuhi"/poin; penukaran 29 Sep tidak ikut.');
        $this->assertSame([1, 1, 200], [$rewards['Kaos Ginnva']->redeemed_in_period, $rewards['Kaos Ginnva']->fulfilled_in_period, (int) $rewards['Kaos Ginnva']->points_spent_in_period]);
        $this->assertSame(3, $result['totalRedemptions']);
        $this->assertSame(['issued_customer' => 150, 'spent_customer' => 30, 'issued_partner' => 200, 'spent_partner' => 40], $result['points']);
    }

    // ------------------------------------------------------------- toko & akses

    public function test_store_filter_scopes_transactions_but_not_points_or_catalogues(): void
    {
        $this->october();
        $this->loyalty();

        $b = $this->report(['store_id' => $this->storeB->id]);

        $this->assertSame(1, $b['promoTransactionCount']);
        $this->assertEqualsWithDelta(50000.0, $b['promoValue'], 0.001);
        $this->assertSame(1, $b['spendPromoTransactionCount']);
        $this->assertSame(150, $b['points']['issued_customer'], 'Poin tetap company-wide.');
        $this->assertCount(2, $b['vouchers']);

        $staff = $this->user('kasir', $this->storeA);
        $own = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertSame(2, $own['promoTransactionCount'], 'Staf tidak bisa melihat toko lain.');
        $this->assertEqualsWithDelta(130000.0, $own['promoValue'], 0.001);
        $this->assertSame(1, $own['spendPromoTransactionCount']);
        $this->assertSame($this->storeA->id, $own['storeId']);
    }

    public function test_an_account_without_a_store_sees_no_transactions(): void
    {
        $this->october();
        $this->loyalty();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertSame(0, $result['promoTransactionCount']);
        $this->assertSame(0, $result['spendPromoTransactionCount']);
        $this->assertSame(-1, $result['storeId']);
        $this->assertSame(150, $result['points']['issued_customer']);
    }

    #[DataProvider('pages')]
    public function test_access_follows_staff_area_and_the_menu_checkbox(string $class): void
    {
        $short = class_basename($class);
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue($class::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => [$short]]), 'web');
        $this->assertTrue($class::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse($class::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(PromoLoyaltyReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(PromoLoyaltyReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(PromoLoyaltyReport::class)->get('storeId'));

        $page = $this->page(PromoLoyaltyReport::class);
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    // ------------------------------------------------------------- tampilan, grafik, ekspor

    #[DataProvider('pages')]
    public function test_page_shows_summary_tables_points_and_the_empty_state(string $class, string $title): void
    {
        $this->october();
        $this->loyalty();

        $this->page($class)
            ->assertSuccessful()
            ->assertSee('Total Transaksi dengan Promo')
            ->assertSee('(Rp180.000)', false)
            ->assertSee('Rp3.500.000', false)
            ->assertSee('(Rp80.000)', false)
            ->assertSee('Diskon 50rb')
            ->assertSee('66.7%', false)
            ->assertSee('Belanja 1jt')
            ->assertSee('Voucher Cuci')
            ->assertSee('+150')
            ->assertSee('-40');

        $this->page($class, ['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertSee('Tidak ada promo dipakai pada rentang ini.')
            ->assertSee('Tidak ada transaksi Promo Total Pembelian pada rentang ini.');
    }

    private function chartData(array $params): array
    {
        $chart = Livewire::test(PromoValueChart::class, $params)->instance();
        $method = new \ReflectionMethod($chart, 'getData');
        $method->setAccessible(true);

        return $method->invoke($chart);
    }

    public function test_chart_sums_voucher_and_spend_promo_value_per_day_and_follows_the_store(): void
    {
        $this->october();
        $this->actingAs($this->user('super_admin'), 'web');

        $all = $this->chartData(['from' => '2026-10-01', 'to' => '2026-10-31', 'storeId' => null]);
        $data = $all['datasets'][0]['data'];
        $this->assertCount(31, $all['labels']);
        $this->assertEquals([1 => 50000, 2 => 80000, 3 => 25000, 4 => 50000, 5 => 15000], collect($data)->filter()->mapWithKeys(fn ($v, $i) => [$i => $v])->all());
        $this->assertEqualsWithDelta(220000.0, array_sum($data), 0.001);

        $onlyA = $this->chartData(['from' => '2026-10-01', 'to' => '2026-10-31', 'storeId' => $this->storeA->id]);
        $this->assertEqualsWithDelta(155000.0, array_sum($onlyA['datasets'][0]['data']), 0.001, 'Toko A: 50.000 + 80.000 + 25.000.');
    }

    public function test_excel_has_real_numbers_aligned_rows_and_formatted_ranges(): void
    {
        $this->october();
        $this->loyalty();

        $export = new PromoLoyaltyReportExport($this->report(), 'Laporan Promo');
        $rows = $export->array();

        $this->assertSame(['Laporan Promo'], $rows[0]);
        $this->assertSame(['Total Transaksi dengan Promo', 3], $rows[4]);
        $this->assertSame(['Nilai Promo', -180000.0], $rows[5]);
        $this->assertSame(['Total Penjualan dengan Promo', 3500000.0], $rows[6]);
        $this->assertSame(['Poin Customer Diterbitkan', 150], $rows[9]);
        $this->assertSame(['2026-10-05', 'Diskon 50rb', $this->bookingB->booking_number, 'Toko B', -50000.0], $rows[17]);
        $this->assertSame(-80000.0, $rows[18][4], 'Snapshot booking, bukan katalog.');
        $this->assertSame(['Total Transaksi', 2], $rows[22]);
        $this->assertSame(['Total Potongan', -40000.0], $rows[23]);

        $styles = $export->styles(new Worksheet());
        foreach (['B6:B7', 'E18:E20', 'B24', 'E28:E29'] as $range) {
            $this->assertSame('#,##0;(#,##0);"-"', $styles[$range]['numberFormat']['formatCode'], $range);
        }
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
        $this->page(PromoLoyaltyReport::class)->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->loyalty();
        $this->page(PromoLoyaltyReport::class)->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
