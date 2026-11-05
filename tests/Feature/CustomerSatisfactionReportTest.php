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

        $this->assertSame([6, 3, 1, 2], [$result['total'], $result['positive'], $result['neutral'], $result['negative']], 'Review 30 Sep tidak ikut.');
        $this->assertEqualsWithDelta(50.0, $result['positiveRate'], 0.001);
        $this->assertSame(1, $result['unfollowedNegativeCount']);
    }

    public function test_the_whole_first_day_counts_when_the_start_date_has_a_time(): void
    {
        $this->october();

        $this->assertSame(6, $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])['total'], 'Review 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
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
        $a = $byStore['Toko A'];
        $b = $byStore['Toko B'];
        $this->assertSame([5, 3, 0, 2, 1], [$a['total'], $a['positive'], $a['neutral'], $a['negative'], $a['unfollowed']]);
        $this->assertEqualsWithDelta(60.0, $a['positiveRate'], 0.001);
        $this->assertEqualsWithDelta(20.0, $a['net'], 0.001, '(3 positif - 2 negatif) / 5.');
        $this->assertSame([1, 0, 1, 0, 0], [$b['total'], $b['positive'], $b['neutral'], $b['negative'], $b['unfollowed']]);
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
        $this->page()->assertSee('Menampilkan 100 review terbaru dari 107 total');
    }

    public function test_admin_store_filter_and_staff_lock(): void
    {
        $this->october();

        $b = $this->report(['store_id' => $this->storeB->id]);
        $this->assertSame([1, 1], [$b['total'], $b['neutral']]);

        $staff = $this->user('kasir', $this->storeA);
        $own = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertSame(5, $own['total'], 'Staff tidak bisa melihat toko lain.');
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

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Belum ada review pada rentang ini.')->assertSee('Belum ada tag positif pada rentang ini.')->assertSee('Belum ada tag keluhan pada rentang ini.');
    }

    public function test_excel_has_summary_per_store_and_reviews_with_tag_labels(): void
    {
        $this->october();

        $rows = (new CustomerSatisfactionReportExport($this->report()))->array();
        $byFirst = collect($rows)->keyBy(0);

        $this->assertSame(['RINGKASAN'], $rows[0]);
        $this->assertSame(['Total Review', 6], $rows[1]);
        $this->assertSame(['Tingkat Positif (%)', 50.0], $rows[5]);
        $this->assertEquals(['Toko A', 5, 3, 0, 2, 60.0, 20.0, 1], $byFirst['Toko A']);
        $this->assertSame(['2026-10-02', 'Toko A', 'Siti', 'Positif', 'Hasil Rapi & Memuaskan, Pelayanan Ramah', 'Bagus'], array_slice($byFirst['2026-10-02'], 0, 6));
        $this->assertSame(['PPF', '-'], array_slice($byFirst['2026-10-02'], 7, 2), 'Layanan dari booking; positif tidak butuh tindak lanjut.');
        $this->assertStringStartsWith('BKG-TEST-', $byFirst['2026-10-02'][6]);
        $this->assertSame(['2026-10-03', 'Toko B', 'Pelanggan', 'Netral', '', '-'], array_slice($byFirst['2026-10-03'], 0, 6), 'Pelanggan yang dihapus tampil "Pelanggan"; tanpa tag kosong.');
        $this->assertSame('Belum', collect($rows)->first(fn ($r) => ($r[5] ?? null) === 'Lama sekali')[8], 'Negatif tanpa tindak lanjut.');
        $this->assertSame('Sudah', collect($rows)->first(fn ($r) => ($r[0] ?? null) === '2026-10-07')[8]);
        $this->assertContains('ASPEK YANG DISUKAI', array_column($rows, 0));
        $this->assertContains('TREN PER MINGGU', array_column($rows, 0));
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

    // ------------------------------------------------------------- skor, respons, tag per nada, tren, saringan daftar

    private function completedBooking(Store $store): void
    {
        $customer = Customer::create(['name' => 'Tanpa Review', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        Booking::create([
            'booking_number' => 'BKG-DONE-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-02', 'status' => 'completed', 'completed_at' => '2026-10-04 10:00:00',
        ]);
    }

    public function test_net_score_response_rate_and_follow_up_time(): void
    {
        $this->october();
        DB::table('bookings')->update(['completed_at' => '2026-10-04 10:00:00']);   // 7 booking selesai (6 punya ulasan dalam rentang + 1 bulan lalu)
        foreach (range(1, 3) as $_) {
            $this->completedBooking($this->storeA);
        }

        $result = $this->report();

        $this->assertEqualsWithDelta((3 - 2) / 6 * 100, $result['netScore'], 0.001);
        $this->assertSame(10, $result['completedBookings']);
        $this->assertEqualsWithDelta(60.0, $result['responseRate'], 0.001, '6 ulasan dari 10 booking selesai.');
        $this->assertEqualsWithDelta(24.0, $result['avgFollowUpHours'], 0.001, 'Ulasan 7 Okt 09:00 ditindaklanjuti 8 Okt 09:00.');
    }

    public function test_without_completed_bookings_there_is_no_response_rate_and_without_follow_ups_no_duration(): void
    {
        $this->review($this->storeA, 'negative', '2026-10-06 09:00:00');

        $result = $this->report();

        $this->assertNull($result['responseRate']);
        $this->assertNull($result['avgFollowUpHours']);
        $this->page()->assertSee('Belum ada booking selesai pada rentang ini.');
    }

    public function test_tags_are_split_into_liked_and_needs_work(): void
    {
        $this->october();

        $groups = $this->report()['tagGroups'];

        $this->assertSame(['hasil_rapi' => 2, 'pelayanan_ramah' => 1], $groups['positive']->all());
        $this->assertSame(['harga_kurang_sesuai' => 1, 'proses_lambat' => 1], $groups['negative']->all(), 'Seri diurutkan menurut kunci.');
    }

    public function test_the_trend_is_weekly_for_short_ranges_and_monthly_for_long_ones(): void
    {
        $this->october();

        $weekly = $this->report();
        $this->assertFalse($weekly['trendMonthly']);
        $this->assertSame(['Minggu 28 Sep', 'Minggu 05 Oct'], $weekly['trend']->pluck('label')->all());
        $this->assertSame([3, 3], $weekly['trend']->pluck('total')->all());
        $this->assertEqualsWithDelta(200 / 3, $weekly['trend'][0]['net'], 0.001, '2 positif + 1 netral dalam minggu 28 Sep.');
        $this->assertEqualsWithDelta(-100 / 3, $weekly['trend'][1]['net'], 0.001, '1 positif, 2 negatif.');

        $monthly = $this->report(['from' => '2026-08-01', 'to' => '2026-10-31']);
        $this->assertTrue($monthly['trendMonthly']);
        $this->assertSame(['Sep 2026', 'Oct 2026'], $monthly['trend']->pluck('label')->all(), 'Review 30 Sep ikut karena rentang mulai 1 Agustus.');
        $this->assertSame([1, 6], $monthly['trend']->pluck('total')->all());
        $this->page(['from' => '2026-08-01', 'to' => '2026-10-31'])->assertSee('Tren per Bulan');
    }

    public function test_the_list_filters_only_narrow_the_list_not_the_summary(): void
    {
        $this->october();

        $negative = $this->report(['sentiment' => 'negative']);
        $this->assertSame([2, 6], [$negative['listTotal'], $negative['total']]);
        $this->assertCount(2, $negative['displayReviews']);

        $pending = $this->report(['follow_up' => 'pending']);
        $this->assertSame(1, $pending['listTotal']);
        $this->assertSame('negative', $pending['displayReviews']->first()->sentiment);

        $done = $this->report(['follow_up' => 'done']);
        $this->assertSame(1, $done['listTotal']);

        $this->assertCount(6, $this->report(['sentiment' => 'negative'])['reviews'], 'Ekspor tetap lengkap.');
        $this->page(['sentiment' => 'negative'])->assertSee('2 dari 6 review cocok dengan saringan daftar.');
    }

    public function test_the_page_shows_the_new_cards_groups_and_booking_context(): void
    {
        $this->october();

        $this->page()
            ->assertSee('Skor Bersih')
            ->assertSee('Lama Tindak Lanjut')
            ->assertSee('24 jam')
            ->assertSee('Aspek yang Disukai')
            ->assertSee('Aspek yang Perlu Diperbaiki')
            ->assertSee('Tren per Minggu')
            ->assertSee('Belum Ditindaklanjuti')
            ->assertSee('BKG-TEST-')
            ->assertSee('(PPF)')
            ->assertDontSee('Majoo');
    }
}
