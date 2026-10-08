<?php

namespace Tests\Feature;

use App\Exports\CustomerSatisfactionReportExport;
use App\Filament\Pages\CustomerSatisfactionReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\StoreReview;
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
 * Kepuasan Pelanggan: agregasi ulasan toko (positif / netral / negatif) dalam rentang -- tingkat positif, negatif yang
 * belum ditindaklanjuti, tag aspek terbanyak (dengan label Indonesia), per toko, daftar ulasan (layar maksimal 100,
 * Excel/PDF lengkap); cakupan toko; sanitasi URL; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class CustomerSatisfactionReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private StoreReview $r1;

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

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function review(Store $store, string $sentiment, string $at, array $tags = [], ?string $comment = null, string $customerName = 'Pelanggan Test', bool $followedUp = false): StoreReview
    {
        $customer = Customer::create(['name' => $customerName, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-02', 'status' => 'completed',
        ]);
        $review = StoreReview::create([
            'booking_id' => $booking->id, 'customer_id' => $customer->id, 'store_id' => $store->id, 'sentiment' => $sentiment,
            'tags' => $tags ?: null, 'comment' => $comment, 'followed_up_at' => $followedUp ? '2026-10-08 09:00:00' : null,
        ]);
        DB::table('store_reviews')->where('id', $review->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $review->fresh();
    }

    /**
     * Toko A: r1 positif (hasil_rapi, pelayanan_ramah) 2 Okt, r2 positif (hasil_rapi) 5 Okt, r3 negatif (proses_lambat,
     * belum ditindaklanjuti) 6 Okt, r4 negatif (harga_kurang_sesuai, sudah) 7 Okt, r7 positif tanpa tag 1 Okt 08:00.
     * Toko B: r5 netral 3 Okt (pelanggannya kemudian dihapus). Di luar rentang: r6 positif 30 Sep 23:59.
     */
    private function october(): void
    {
        $this->r1 = $this->review($this->storeA, 'positive', '2026-10-02 09:00:00', ['hasil_rapi', 'pelayanan_ramah'], 'Bagus', 'Siti');
        $this->review($this->storeA, 'positive', '2026-10-05 09:00:00', ['hasil_rapi']);
        $this->review($this->storeA, 'negative', '2026-10-06 09:00:00', ['proses_lambat'], 'Lama sekali');
        $this->review($this->storeA, 'negative', '2026-10-07 09:00:00', ['harga_kurang_sesuai'], null, 'Pelanggan Test', true);
        $r5 = $this->review($this->storeB, 'neutral', '2026-10-03 09:00:00', [], null, 'Rudi Dihapus');
        $this->review($this->storeA, 'positive', '2026-09-30 23:59:00');
        $this->review($this->storeA, 'positive', '2026-10-01 08:00:00');
        Customer::find($r5->customer_id)->delete();
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(CustomerSatisfactionReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    public function test_counts_rates_and_unfollowed_negatives(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame([6, 3, 1, 2], [$result['total'], $result['positive'], $result['neutral'], $result['negative']], 'Ulasan 30 Sep tidak ikut.');
        $this->assertEqualsWithDelta(50.0, $result['positiveRate'], 0.001);
        $this->assertSame(1, $result['unfollowedNegativeCount']);
    }

    public function test_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $this->october();

        $this->assertSame(6, $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])['total'], 'Ulasan 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
    }

    public function test_tag_counts_are_ranked_with_a_stable_tie_order(): void
    {
        $this->october();

        $tags = $this->report()['tagCounts'];

        $this->assertSame(['hasil_rapi', 'harga_kurang_sesuai', 'pelayanan_ramah', 'proses_lambat'], $tags->keys()->all());
        $this->assertSame(2, $tags['hasil_rapi']);
    }

    public function test_tag_labels_are_indonesian_and_unknown_keys_are_tidied(): void
    {
        $this->assertSame('Hasil Rapi & Memuaskan', StoreReview::tagLabel('hasil_rapi'));
        $this->assertSame('Tag baru dari app', StoreReview::tagLabel('tag_baru_dari_app'));
        $this->assertSame(['Hasil Rapi & Memuaskan', 'Pelayanan Ramah'], $this->review($this->storeA, 'positive', '2026-10-02 09:00:00', ['hasil_rapi', 'pelayanan_ramah'])->tagLabels());
    }

    public function test_per_store_breakdown(): void
    {
        $this->october();

        $byStore = $this->report()['byStore'];

        $this->assertSame(['Toko A', 'Toko B'], $byStore->keys()->all(), 'Terbanyak dulu.');
        $this->assertSame(['total' => 5, 'positive' => 3, 'negative' => 2], $byStore['Toko A']);
        $this->assertSame(['total' => 1, 'positive' => 0, 'negative' => 0], $byStore['Toko B']);
    }

    public function test_screen_list_is_limited_to_100_but_the_full_set_is_kept_for_export(): void
    {
        $this->october();
        for ($i = 0; $i < 101; $i++) {
            $this->review($this->storeA, 'positive', '2026-10-04 10:00:00');
        }

        $result = $this->report();

        $this->assertSame(107, $result['total']);
        $this->assertCount(107, $result['reviews']);
        $this->assertCount(100, $result['displayReviews']);
        $this->assertTrue($result['displayReviewsTruncated']);
        $this->page()->assertSee('Menampilkan 100 ulasan terbaru dari 107 total');
    }

    public function test_admin_store_filter_and_staff_lock(): void
    {
        $this->october();

        $b = $this->report(['store_id' => $this->storeB->id]);
        $this->assertSame([1, 1], [$b['total'], $b['neutral']]);

        $staff = $this->user('kasir', $this->storeA);
        $own = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertSame(5, $own['total'], 'Staf tidak bisa melihat toko lain.');
        $this->assertSame(['Toko A'], $own['byStore']->keys()->all());
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertSame(0, $result['total']);
        $this->assertEquals(0, $result['positiveRate']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(CustomerSatisfactionReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['CustomerSatisfactionReport']]), 'web');
        $this->assertTrue(CustomerSatisfactionReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(CustomerSatisfactionReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(CustomerSatisfactionReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(CustomerSatisfactionReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertSame($this->storeA->id, Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(CustomerSatisfactionReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_cards_tag_labels_reviews_and_the_follow_up_link(): void
    {
        $this->october();

        $page = $this->page();
        $page->assertSuccessful()
            ->assertSee('Total Review')
            ->assertSee('50.0% dari total', false)
            ->assertSee('1 belum ditindaklanjuti')
            ->assertSee('Hasil Rapi & Memuaskan')
            ->assertSee('Proses Lambat')
            ->assertDontSee('hasil_rapi')
            ->assertSee('Siti')
            ->assertSee('Bagus')
            ->assertSee('Lama sekali')
            ->assertSee('(Tanpa komentar)')
            ->assertSee('Tindaklanjuti →', false)
            ->assertSee('Pelanggan');

        $this->assertStringContainsString((string) $this->r1->id, $page->instance()->reviewUrl($this->r1->id));

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Belum ada ulasan pada rentang ini.')->assertSee('Belum ada tag pada rentang ini.');
    }

    public function test_excel_has_summary_per_store_and_reviews_with_tag_labels(): void
    {
        $this->october();

        $rows = (new CustomerSatisfactionReportExport($this->report()))->array();
        $byFirst = collect($rows)->keyBy(0);

        $this->assertSame(['RINGKASAN'], $rows[0]);
        $this->assertSame(['Total Review', 6], $rows[1]);
        $this->assertSame(['Tingkat Positif (%)', 50.0], $rows[5]);
        $this->assertSame(['Toko A', 5, 3, 2], $byFirst['Toko A']);
        $this->assertSame(['2026-10-02', 'Toko A', 'Siti', 'Positif', 'Hasil Rapi & Memuaskan, Pelayanan Ramah', 'Bagus'], $byFirst['2026-10-02']);
        $this->assertSame(['2026-10-03', 'Toko B', 'Pelanggan', 'Netral', '', '-'], $byFirst['2026-10-03'], 'Pelanggan yang dihapus tampil "Pelanggan"; tanpa tag kosong.');
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
        $this->assertSame('customer_satisfaction', $logs->first()->properties['report']);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
