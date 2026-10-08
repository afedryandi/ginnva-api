<?php

namespace Tests\Feature;

use App\Exports\JobDurationByServiceExport;
use App\Filament\Pages\JobDurationByServiceReport;
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
 * Laporan Proses Produk (durasi per jenis layanan): rata-rata, tercepat, terlama per layanan; job kombo
 * menyumbang durasi penuh ke tiap layanan; urut jumlah job; cakupan toko; sanitasi URL; Excel/PDF + log.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class JobDurationByServiceReportTest extends TestCase
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

    private function job(string $in, int $minutes, array $flags = ['product_ppf' => true], ?Store $store = null): Spk
    {
        $store ??= $this->storeA;
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'preferred_date' => '2026-10-05', 'status' => 'completed',
        ], $flags));

        return Spk::create([
            'spk_number' => 'SPK-TEST-' . strtoupper(uniqid()), 'store_id' => $store->id, 'booking_id' => $booking->id,
            'customer_name' => 'Budi', 'checked_in_at' => $in, 'checked_out_at' => Carbon::parse($in)->addMinutes($minutes),
        ]);
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(JobDurationByServiceReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function rows(array $data = [], ?User $as = null)
    {
        return $this->page($data, $as)->instance()->getRows()->keyBy('service');
    }

    /** PPF+Detailing 120m, PPF 60m, Kaca Film 30m, tanpa jenis 45m -- semua Oktober, toko A. */
    private function october(): void
    {
        $this->job('2026-10-02 09:00:00', 120, ['product_ppf' => true, 'product_detailing' => true]);
        $this->job('2026-10-03 09:00:00', 60, ['product_ppf' => true]);
        $this->job('2026-10-04 09:00:00', 30, ['product_kaca_film' => true]);
        $this->job('2026-10-05 09:00:00', 45, []);
    }

    public function test_average_fastest_and_slowest_per_service_with_full_credit_for_combo_jobs(): void
    {
        $this->october();

        $rows = $this->rows();

        $this->assertSame(2, $rows['PPF']['jobCount']);
        $this->assertEqualsWithDelta(90.0, $rows['PPF']['avgMinutes'], 0.0001);
        $this->assertSame([60, 120], [$rows['PPF']['minMinutes'], $rows['PPF']['maxMinutes']]);
        $this->assertSame(1, $rows['Detailing']['jobCount']);
        $this->assertEqualsWithDelta(120.0, $rows['Detailing']['avgMinutes'], 0.0001);
        $this->assertSame([30, 30], [$rows['Kaca Film']['minMinutes'], $rows['Kaca Film']['maxMinutes']]);
        $this->assertSame(1, $rows['Tidak ditandai']['jobCount']);
    }

    public function test_rows_are_sorted_by_job_count_and_premium_wash_is_its_own_service(): void
    {
        $this->october();
        $this->job('2026-10-06 09:00:00', 50, ['product_premium_wash' => true]);

        $rows = $this->page()->instance()->getRows();

        $this->assertSame('PPF', $rows->first()['service']);
        $this->assertTrue($rows->pluck('service')->contains('Premium Wash'));
    }

    public function test_range_store_scope_and_unfinished_jobs(): void
    {
        $this->october();
        $this->job('2026-10-06 09:00:00', 200, ['product_kaca_film' => true], $this->storeB);
        $this->job('2026-09-20 09:00:00', 500, ['product_ppf' => true]);                       // di luar rentang
        $this->job('2026-10-07 09:00:00', 90, ['product_ppf' => true])->update(['checked_out_at' => null]);   // belum keluar

        $all = $this->rows();
        $this->assertSame(2, $all['Kaca Film']['jobCount']);
        $this->assertSame(2, $all['PPF']['jobCount'], 'Job September tidak ikut.');

        $onlyB = $this->rows(['store_id' => $this->storeB->id]);
        $this->assertSame(['Kaca Film'], $onlyB->keys()->all());

        $staff = $this->user('kasir', $this->storeA);
        $this->assertSame(1, $this->rows(['store_id' => $this->storeB->id], $staff)['Kaca Film']['jobCount'], 'Staf tidak bisa melihat toko lain.');
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $this->assertCount(0, $this->rows([], $this->user('kasir', null)));
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(JobDurationByServiceReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['JobDurationByServiceReport']]), 'web');
        $this->assertTrue(JobDurationByServiceReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SpkResource']]), 'web');
        $this->assertFalse(JobDurationByServiceReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(JobDurationByServiceReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(JobDurationByServiceReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertSame($this->storeA->id, Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(JobDurationByServiceReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        $page->set('data.preset', 'last_month');
        $this->assertSame(['2026-09-01', '2026-09-30'], [$page->get('data.from'), $page->get('data.to')]);
    }

    public function test_page_shows_rows_in_hours_and_the_empty_state(): void
    {
        $this->october();

        $this->page()->assertSuccessful()
            ->assertSee('PPF')
            ->assertSee('Detailing')
            ->assertSee('1,5 jam')
            ->assertSee('1,0 jam')
            ->assertSee('2,0 jam')
            ->assertSee('Tidak ditandai');

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Belum ada job dengan waktu masuk-keluar tercatat pada rentang ini.');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $this->october();

        $page = $this->page()->instance();
        $export = new JobDurationByServiceExport(['from' => Carbon::parse('2026-10-01'), 'to' => Carbon::parse('2026-10-31'), 'rows' => $page->getRows()]);
        $rows = collect($export->array())->keyBy(0);

        $this->assertSame(['Jenis Layanan', 'Jumlah Job', 'Rata-rata (menit)', 'Tercepat (menit)', 'Terlama (menit)'], $export->headings());
        $this->assertSame(['PPF', 2, 90.0, 60, 120], $rows['PPF']);
        $this->assertSame(['Detailing', 1, 120.0, 120, 120], $rows['Detailing']);
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-proses-produk-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
        $this->assertSame('job_duration_by_service', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
