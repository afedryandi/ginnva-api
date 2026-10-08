<?php

namespace Tests\Feature;

use App\Exports\BayZoneUtilizationExport;
use App\Filament\Pages\BayZoneUtilizationReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BayZoneUtilizationService;
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
 * Laporan Utilisasi Zona/Bay: 2 zona fisik per toko (Detailing & Persiapan, Instalasi & QC) dengan jumlah slot sendiri;
 * jam terpakai direkonstruksi dari log perubahan tahap booking (activity_log), jam tersedia = jam buka toko x slot
 * (hanya sampai sekarang untuk periode yang masih berjalan); satu toko per laporan (admin memilih, staf terkunci);
 * toko tanpa slot tidak bisa diekspor; Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class BayZoneUtilizationReportTest extends TestCase
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
        // Toko A: zona detailing 2 slot, instalasi/QC 1 slot, buka 24 jam. Toko B: belum diisi slot.
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'detailing_slot_count' => 2, 'instalasi_qc_slot_count' => 1]);
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

    private function booking(Store $store, string $status = 'completed', ?string $updatedAt = null): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-09-02', 'status' => $status,
        ]);
        DB::table('bookings')->where('id', $booking->id)->update(['created_at' => '2026-09-01 07:00:00', 'updated_at' => $updatedAt ?? '2026-09-10 00:00:00']);

        return $booking->fresh();
    }

    /** Catatan perubahan tahap seperti yang ditulis LogsActivity: properties.attributes.current_stage. */
    private function stage(Booking $booking, string $stage, string $at): void
    {
        $log = Activity::create([
            'log_name' => 'default', 'description' => 'updated', 'subject_type' => Booking::class, 'subject_id' => $booking->id,
            'properties' => ['attributes' => ['current_stage' => $stage], 'old' => []],
        ]);
        DB::table('activity_log')->where('id', $log->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /**
     * 1–7 September 2026. X: cuci 2 Sep 08:00 -> instalasi 14:00 -> QC 3 Sep 14:00 -> selesai 4 Sep 08:00.
     * Y: detailing 2 Sep 10:00 -> instalasi 16:00 -> selesai 3 Sep 16:00. Toko A (24 jam buka): tersedia 168 jam/slot.
     * Detailing: X 6 jam + Y 6 jam = 12 jam (peak 2). Instalasi/QC: X 24 + 18 jam, Y 24 jam = 66 jam (peak 2).
     */
    private function september(): array
    {
        $x = $this->booking($this->storeA, 'completed', '2026-09-04 08:00:00');
        $this->stage($x, 'ppf_washing', '2026-09-02 08:00:00');
        $this->stage($x, 'ppf_installation', '2026-09-02 14:00:00');
        $this->stage($x, 'qc', '2026-09-03 14:00:00');
        $this->stage($x, 'completed', '2026-09-04 08:00:00');

        $y = $this->booking($this->storeA, 'completed', '2026-09-03 16:00:00');
        $this->stage($y, 'ppf_detailing', '2026-09-02 10:00:00');
        $this->stage($y, 'ppf_installation', '2026-09-02 16:00:00');
        $this->stage($y, 'completed', '2026-09-03 16:00:00');

        $other = $this->booking($this->storeB, 'completed', '2026-09-04 08:00:00');
        $this->stage($other, 'ppf_washing', '2026-09-02 08:00:00');
        $this->stage($other, 'completed', '2026-09-03 08:00:00');

        $old = $this->booking($this->storeA, 'completed', '2026-08-04 08:00:00');
        $this->stage($old, 'ppf_washing', '2026-08-02 08:00:00');
        $this->stage($old, 'completed', '2026-08-03 08:00:00');

        return compact('x', 'y');
    }

    private function page(array $data = [], ?User $as = null, array $query = [])
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = $query ? Livewire::withQueryParams($query)->test(BayZoneUtilizationReport::class) : Livewire::test(BayZoneUtilizationReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null, array $query = []): array
    {
        return $this->page($data, $as, $query)->instance()->getResult();
    }

    private const WEEK = ['from' => '2026-09-01', 'to' => '2026-09-07', 'store_id' => null];

    private function week(?Store $store = null): array
    {
        return ['from' => '2026-09-01', 'to' => '2026-09-07', 'store_id' => ($store ?? $this->storeA)->id];
    }

    // ------------------------------------------------------------- perhitungan

    public function test_occupied_hours_peak_and_utilisation_are_rebuilt_from_stage_changes(): void
    {
        $this->september();

        $zones = $this->report($this->week())['zones'];
        $detailing = $zones[BayZoneUtilizationService::ZONE_DETAILING];
        $instalasi = $zones[BayZoneUtilizationService::ZONE_INSTALASI_QC];

        $this->assertTrue($detailing['configured']);
        $this->assertSame([2, 2], [$detailing['slotCount'], $detailing['bookingCount']]);
        $this->assertEqualsWithDelta(336.0, $detailing['availableHours'], 0.01, '7 hari x 24 jam x 2 slot.');
        $this->assertEqualsWithDelta(12.0, $detailing['occupiedHours'], 0.01);
        $this->assertEqualsWithDelta(12 / 336 * 100, $detailing['utilizationPct'], 0.01);
        $this->assertSame(2, $detailing['peakConcurrent'], 'X dan Y sama-sama di zona detailing jam 10:00–14:00.');

        $this->assertSame([1, 2], [$instalasi['slotCount'], $instalasi['bookingCount']]);
        $this->assertEqualsWithDelta(168.0, $instalasi['availableHours'], 0.01);
        $this->assertEqualsWithDelta(66.0, $instalasi['occupiedHours'], 0.01, 'X: 24 jam instalasi + 18 jam QC; Y: 24 jam instalasi.');
        $this->assertEqualsWithDelta(66 / 168 * 100, $instalasi['utilizationPct'], 0.01);
        $this->assertSame(2, $instalasi['peakConcurrent'], 'Perpindahan instalasi -> QC milik X yang bersambung tepat tidak dihitung ganda.');
    }

    public function test_intervals_are_clipped_to_the_report_range_and_other_stores_are_ignored(): void
    {
        $this->september();

        $oneDay = $this->report(['from' => '2026-09-02', 'to' => '2026-09-02', 'store_id' => $this->storeA->id])['zones'];

        // 2 Sep saja: detailing X 08–14 (6) + Y 10–16 (6, tapi Y masuk instalasi jam 16:00 -> 6); instalasi X 14–24 (10) + Y 16–24 (8).
        $this->assertEqualsWithDelta(12.0, $oneDay['detailing']['occupiedHours'], 0.01);
        $this->assertEqualsWithDelta(18.0, $oneDay['instalasi_qc']['occupiedHours'], 0.01);
        $this->assertEqualsWithDelta(48.0, $oneDay['detailing']['availableHours'], 0.01);

        $august = $this->report(['from' => '2026-08-01', 'to' => '2026-08-31', 'store_id' => $this->storeA->id])['zones'];
        $this->assertEqualsWithDelta(24.0, $august['detailing']['occupiedHours'], 0.01, 'Hanya booking Agustus toko A (02 Agu 08:00 -> 03 Agu 08:00).');
        $this->assertSame(1, $august['detailing']['bookingCount']);
    }

    public function test_available_hours_follow_opening_hours_and_skip_closed_days(): void
    {
        $store = Store::create([
            'city' => 'Surabaya', 'address' => 'Jl. C', 'name' => 'Toko C', 'is_active' => true, 'detailing_slot_count' => 1, 'instalasi_qc_slot_count' => 3,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '08:00', 'close' => '20:00'], ['days' => ['sun'], 'closed' => true]],
        ]);

        $zones = $this->report(['from' => '2026-09-01', 'to' => '2026-09-07', 'store_id' => $store->id])['zones'];

        // 1–7 Sep 2026 = Selasa–Senin; Minggu 6 Sep tutup -> 6 hari x 12 jam = 72 jam per slot.
        $this->assertEqualsWithDelta(72.0, $zones['detailing']['availableHours'], 0.01);
        $this->assertEqualsWithDelta(216.0, $zones['instalasi_qc']['availableHours'], 0.01, '72 jam x 3 slot.');
        $this->assertEquals(0, $zones['detailing']['utilizationPct']);
    }

    public function test_a_running_period_only_counts_available_hours_up_to_now(): void
    {
        $zones = $this->report(['from' => '2026-10-01', 'to' => '2026-10-31', 'store_id' => $this->storeA->id])['zones'];

        // 1 Okt 00:00 s.d. 8 Okt 10:00 = 7 x 24 + 10 = 178 jam per slot -- bukan 31 hari penuh.
        $this->assertEqualsWithDelta(178.0 * 2, $zones['detailing']['availableHours'], 0.01);
        $this->assertEqualsWithDelta(178.0, $zones['instalasi_qc']['availableHours'], 0.01);

        $future = $this->report(['from' => '2026-11-01', 'to' => '2026-11-30', 'store_id' => $this->storeA->id])['zones'];
        $this->assertEquals(0, $future['detailing']['availableHours']);
        $this->assertEquals(0, $future['detailing']['utilizationPct'], 'Periode di masa depan: tidak ada pembagian dengan nol.');
    }

    public function test_a_zone_without_slots_is_not_configured_and_an_unconfigured_store_is_flagged(): void
    {
        $this->storeA->update(['instalasi_qc_slot_count' => null]);
        $this->september();

        $zones = $this->report($this->week())['zones'];
        $this->assertTrue($zones['detailing']['configured']);
        $this->assertFalse($zones['instalasi_qc']['configured']);
        $this->assertNull($zones['instalasi_qc']['slotCount']);

        $result = $this->report($this->week($this->storeB));
        $this->assertFalse($result['configured']);
        $this->assertSame([false, false], collect($result['zones'])->pluck('configured')->all());
    }

    // ------------------------------------------------------------- toko & akses

    public function test_admin_defaults_to_the_first_active_store_and_can_pick_one_by_url(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $default = Livewire::test(BayZoneUtilizationReport::class);
        $this->assertSame($this->storeA->id, $default->get('storeId'), 'Toko aktif pertama menurut nama.');

        $picked = Livewire::withQueryParams(['toko' => (string) $this->storeB->id])->test(BayZoneUtilizationReport::class);
        $this->assertSame($this->storeB->id, $picked->get('storeId'));
    }

    public function test_staff_are_locked_to_their_own_store_and_accounts_without_a_store_see_nothing(): void
    {
        $this->september();
        $staff = $this->user('kasir', $this->storeA);

        $own = $this->report(array_merge($this->week($this->storeB)), $staff, ['toko' => (string) $this->storeB->id]);
        $this->assertSame($this->storeA->id, $own['store']->id, 'Pilihan toko lain diabaikan.');
        $this->assertEqualsWithDelta(12.0, $own['zones']['detailing']['occupiedHours'], 0.01);

        $none = $this->report($this->week(), $this->user('kasir', null));
        $this->assertNull($none['store']);
        $this->assertSame([], $none['zones']);
        $this->page($this->week(), $this->user('kasir', null))->assertSee('Pilih toko terlebih dahulu.');
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(BayZoneUtilizationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BayZoneUtilizationReport']]), 'web');
        $this->assertTrue(BayZoneUtilizationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(BayZoneUtilizationReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => ''])->test(BayZoneUtilizationReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(BayZoneUtilizationReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    // ------------------------------------------------------------- tampilan & ekspor

    public function test_page_shows_both_zones_figures_and_the_unconfigured_notice(): void
    {
        $this->september();

        $this->page($this->week())
            ->assertSuccessful()
            ->assertSee('Zona Detailing & Persiapan', false)
            ->assertSee('Zona Instalasi & QC', false)
            ->assertSee('12,0 jam')
            ->assertSee('66,0 jam')
            ->assertSee('3,6%', false)
            ->assertSee('39,3%', false)
            ->assertSee('Peak Bersamaan');

        $this->page($this->week($this->storeB))->assertSee('Toko ini belum diisi kapasitas slot');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $this->september();

        $export = new BayZoneUtilizationExport($this->report($this->week()));
        $rows = $export->array();

        $this->assertSame(['Zona', 'Kapasitas (Slot)', 'Jumlah Booking Lewat Zona Ini', 'Jam Tersedia', 'Jam Terpakai', 'Utilisasi %', 'Peak Bersamaan'], $export->headings());
        $this->assertSame(['Zona Detailing & Persiapan', 2, 2, 336.0, 12.0, 3.6, 2], $rows[0]);
        $this->assertSame(['Zona Instalasi & QC', 1, 2, 168.0, 66.0, 39.3, 2], $rows[1]);

        $this->storeA->update(['instalasi_qc_slot_count' => null]);
        $partial = (new BayZoneUtilizationExport($this->report($this->week())))->array();
        $this->assertSame(['Zona Instalasi & QC', 'Belum dikonfigurasi', '-', '-', '-', '-', '-'], $partial[1]);
    }

    public function test_exports_download_with_a_log_and_an_unconfigured_store_is_refused(): void
    {
        $this->september();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page($this->week(), $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('utilisasi-zona-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('bay_zone_utilization', $logs->first()->properties['report']);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());

        $before = Activity::where('log_name', 'report_export')->count();
        $blocked = $this->page($this->week($this->storeB), $admin);
        $blocked->callAction('exportExcel')->assertHasNoActionErrors();
        $blocked->callAction('exportPdf')->assertHasNoActionErrors();
        $this->assertSame($before, Activity::where('log_name', 'report_export')->count(), 'Toko tanpa slot tidak diekspor dan tidak dicatat.');
    }

    public function test_pdf_renders_with_data(): void
    {
        $this->september();

        $this->page($this->week())->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
