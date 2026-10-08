<?php

namespace Tests\Feature;

use App\Exports\JobDurationExport;
use App\Filament\Pages\JobDurationReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Proses Order (durasi pengerjaan per job/SPK): hanya SPK dengan waktu masuk DAN keluar, dipilih menurut
 * waktu masuk, terbaru dulu; durasi dalam menit; jenis layanan dari 4 flag produk booking; teknisi dari booking;
 * cakupan toko; sanitasi URL; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class JobDurationReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

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

    /** @param array<string,bool> $flags */
    private function job(?string $in, ?string $out, array $flags = ['product_ppf' => true], ?Store $store = null, string $customerName = 'Budi', array $installers = []): Spk
    {
        $store ??= $this->storeA;
        $customer = Customer::create(['name' => $customerName, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'preferred_date' => '2026-10-05', 'status' => 'completed',
        ], $flags));
        if ($installers) {
            $booking->installers()->attach(collect($installers)->pluck('id')->all());
        }

        return Spk::create([
            'spk_number' => 'SPK-TEST-' . strtoupper(uniqid()), 'store_id' => $store->id, 'booking_id' => $booking->id,
            'customer_name' => $customerName, 'checked_in_at' => $in, 'checked_out_at' => $out,
        ]);
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(JobDurationReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function jobs(array $data = [], ?User $as = null)
    {
        return $this->page($data, $as)->instance()->getJobs();
    }

    // ------------------------------------------------------------- daftar & durasi

    public function test_only_jobs_with_both_check_in_and_out_in_range_are_listed_newest_first(): void
    {
        $old = $this->job('2026-10-02 09:00:00', '2026-10-02 12:00:00');
        $new = $this->job('2026-10-06 09:00:00', '2026-10-06 10:30:00');
        $this->job('2026-10-04 09:00:00', null);                      // masih di bengkel
        $this->job(null, '2026-10-04 12:00:00');                      // data tidak lengkap
        $this->job('2026-09-30 23:59:00', '2026-10-01 03:00:00');     // masuk sebelum rentang
        $this->job('2026-11-01 00:00:00', '2026-11-01 04:00:00');     // setelah rentang

        $jobs = $this->jobs();

        $this->assertSame([$new->id, $old->id], $jobs->pluck('spk_id')->all());
        $this->assertSame([90, 180], $jobs->pluck('minutes')->all());
    }

    public function test_the_range_is_inclusive_of_the_whole_first_and_last_day(): void
    {
        $first = $this->job('2026-10-01 00:05:00', '2026-10-01 01:00:00');
        $last = $this->job('2026-10-31 23:50:00', '2026-11-01 02:00:00');

        $ids = $this->jobs(['from' => '2026-10-01 12:00:00', 'to' => '2026-10-31'])->pluck('spk_id')->all();

        $this->assertEqualsCanonicalizing([$first->id, $last->id], $ids, 'Job masuk 00:05 di hari pertama ikut walau "Dari" berjam 12:00; job masuk 23:50 hari terakhir ikut.');
    }

    public function test_reversed_check_times_still_give_a_positive_duration(): void
    {
        $this->job('2026-10-06 12:00:00', '2026-10-06 10:00:00');

        $this->assertSame(120, $this->jobs()->first()['minutes']);
    }

    public function test_service_labels_come_from_the_four_product_flags(): void
    {
        $all = $this->job('2026-10-02 09:00:00', '2026-10-02 10:00:00', ['product_ppf' => true, 'product_kaca_film' => true, 'product_detailing' => true, 'product_premium_wash' => true]);
        $none = $this->job('2026-10-03 09:00:00', '2026-10-03 10:00:00', []);
        $wash = $this->job('2026-10-04 09:00:00', '2026-10-04 10:00:00', ['product_premium_wash' => true]);

        $byId = $this->jobs()->keyBy('spk_id');

        $this->assertSame(['PPF', 'Kaca Film', 'Detailing', 'Premium Wash'], $byId[$all->id]['services']);
        $this->assertSame(['Tidak ditandai'], $byId[$none->id]['services']);
        $this->assertSame(['Premium Wash'], $byId[$wash->id]['services']);
    }

    public function test_technicians_come_from_the_booking_installers(): void
    {
        $rina = $this->user('kasir', $this->storeA, ['name' => 'Rina Teknisi']);
        $andi = $this->user('kasir', $this->storeA, ['name' => 'Andi Teknisi']);
        $with = $this->job('2026-10-02 09:00:00', '2026-10-02 10:00:00', ['product_ppf' => true], null, 'Budi', [$rina, $andi]);
        $without = $this->job('2026-10-03 09:00:00', '2026-10-03 10:00:00');

        $byId = $this->jobs()->keyBy('spk_id');

        $this->assertEqualsCanonicalizing(['Rina Teknisi', 'Andi Teknisi'], $byId[$with->id]['technicians']);
        $this->assertSame([], $byId[$without->id]['technicians']);
    }

    // ------------------------------------------------------------- toko & akses

    public function test_admin_filters_by_store_and_staff_are_locked_to_their_own(): void
    {
        $a = $this->job('2026-10-02 09:00:00', '2026-10-02 10:00:00', ['product_ppf' => true], $this->storeA);
        $b = $this->job('2026-10-03 09:00:00', '2026-10-03 10:00:00', ['product_ppf' => true], $this->storeB);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->jobs()->pluck('spk_id')->all());
        $this->assertSame([$b->id], $this->jobs(['store_id' => $this->storeB->id])->pluck('spk_id')->all());

        $staff = $this->user('kasir', $this->storeA);
        $this->assertSame([$a->id], $this->jobs(['store_id' => $this->storeB->id], $staff)->pluck('spk_id')->all(), 'Staf tidak bisa melihat toko lain.');
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->job('2026-10-02 09:00:00', '2026-10-02 10:00:00');

        $this->assertCount(0, $this->jobs([], $this->user('kasir', null)));
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(JobDurationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['JobDurationReport']]), 'web');
        $this->assertTrue(JobDurationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SpkResource']]), 'web');
        $this->assertFalse(JobDurationReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(JobDurationReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(JobDurationReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertSame($this->storeA->id, Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(JobDurationReport::class)->get('storeId'), 'URL tidak bisa memindahkan staf ke toko lain.');

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());
    }

    public function test_quick_periods_fill_the_dates(): void
    {
        $page = $this->page();
        $expected = [
            'last_month' => ['2026-09-01', '2026-09-30'],
            'this_quarter' => ['2026-10-01', '2026-12-31'],
            'ytd' => ['2026-01-01', '2026-10-08'],
            'last_year' => ['2025-01-01', '2025-12-31'],
            'this_month' => ['2026-10-01', '2026-10-31'],
        ];

        foreach ($expected as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    // ------------------------------------------------------------- tampilan & ekspor

    public function test_page_shows_rows_duration_format_average_and_link(): void
    {
        $spk = $this->job('2026-10-02 09:00:00', '2026-10-02 10:30:00', ['product_ppf' => true, 'product_detailing' => true], null, 'Budi Santoso');
        $this->job('2026-10-03 09:00:00', '2026-10-03 11:30:00');

        $page = $this->page();
        $page->assertSuccessful()
            ->assertSee('2 Job')
            ->assertSee($spk->spk_number)
            ->assertSee('Budi Santoso')
            ->assertSee('Toko A')
            ->assertSee('PPF')
            ->assertSee('Detailing')
            ->assertSee('1j 30m')
            ->assertSee('2j 30m')
            ->assertSee('Rata-rata durasi: 2,0 jam');

        $this->assertStringContainsString((string) $spk->id, $page->instance()->spkUrl($spk->id));
    }

    public function test_page_shows_the_empty_state(): void
    {
        $this->page()->assertSee('Belum ada job dengan waktu masuk-keluar tercatat pada rentang ini.');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $rina = $this->user('kasir', $this->storeA, ['name' => 'Rina Teknisi']);
        $spk = $this->job('2026-10-02 09:00:00', '2026-10-02 10:30:00', ['product_ppf' => true, 'product_premium_wash' => true], null, 'Budi', [$rina]);
        $plain = $this->job('2026-10-01 09:00:00', '2026-10-01 09:45:00', ['product_kaca_film' => true]);

        $page = $this->page()->instance();
        $export = new JobDurationExport(['from' => Carbon::parse('2026-10-01'), 'to' => Carbon::parse('2026-10-31'), 'jobs' => $page->getJobs()]);
        $rows = $export->array();

        $this->assertSame(['Tanggal', 'No. SPK', 'Cabang', 'Customer', 'Jenis Layanan', 'Teknisi', 'Durasi (menit)'], $export->headings());
        $this->assertSame(['2026-10-02 09:00', $spk->spk_number, 'Toko A', 'Budi', 'PPF, Premium Wash', 'Rina Teknisi', 90], $rows[0]);
        $this->assertSame(['2026-10-01 09:00', $plain->spk_number, 'Toko A', 'Budi', 'Kaca Film', '-', 45], $rows[1]);
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->job('2026-10-02 09:00:00', '2026-10-02 10:00:00');
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-proses-order-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
        $this->assertSame('job_duration', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->job('2026-10-02 09:00:00', '2026-10-02 10:00:00');
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
