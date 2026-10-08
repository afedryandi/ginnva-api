<?php

namespace Tests\Feature;

use App\Exports\ReservationUtilizationReportExport;
use App\Filament\Pages\ReservationUtilizationReport;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Customer;
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
 * Laporan Reservasi & Utilisasi: per toko aktif -- hari kerja (hari libur mingguan & tanggal diblokir dilewati),
 * kapasitas = hari kerja x kapasitas/hari (default 3), terpakai = booking confirmed yang menyentuh hari itu
 * (sama dengan Booking::confirmedOverlapCount), kosong, dibatalkan & tingkat pembatalan (semua status menurut
 * tanggal jadwal), rentang maksimal 62 hari. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class ReservationUtilizationReportTest extends TestCase
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
        // Toko A: kapasitas 2/hari, libur tiap Minggu. Toko B: kapasitas kosong (default 3), buka tiap hari.
        $this->storeA = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'install_capacity_per_day' => 2,
            'opening_hours' => [['days' => ['sun'], 'closed' => true]],
        ]);
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

    private function booking(string $status, string $preferred, int $days = 1, ?Store $store = null): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $preferred, 'duration_days' => $days, 'status' => $status,
            'transaction_amount' => 1000000,
        ]);
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(ReservationUtilizationReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    /**
     * Pekan 5–11 Okt 2026 (Senin–Minggu). Toko A: X (5–6), Y (6), Z (Jum 9 + 3 hari kerja = 9, 10, lalu Senin 12),
     * satu batal (7), satu pending (8), tanggal 8 diblokir; booking confirmed di luar rentang (20) tidak ikut.
     */
    private function week(): void
    {
        $this->booking('confirmed', '2026-10-05', 2);
        $this->booking('confirmed', '2026-10-06', 1);
        $this->booking('confirmed', '2026-10-09', 3);
        $this->booking('cancelled', '2026-10-07');
        $this->booking('pending', '2026-10-08');
        $this->booking('confirmed', '2026-10-20');
        BlockedDate::create(['store_id' => $this->storeA->id, 'date' => '2026-10-08', 'reason' => 'Libur lokal']);
    }

    private function row(array $result, Store $store): array
    {
        return collect($result['rows'])->first(fn ($r) => $r['store']->id === $store->id);
    }

    private const WEEK = ['from' => '2026-10-05', 'to' => '2026-10-11'];

    public function test_working_days_capacity_used_and_empty_slots_per_store(): void
    {
        $this->week();

        $a = $this->row($this->report(self::WEEK), $this->storeA);

        // Hari kerja: Sen 5, Sel 6, Rab 7, Jum 9, Sab 10 (Kamis 8 diblokir, Minggu 11 libur) = 5.
        $this->assertSame(5, $a['workingDays']);
        $this->assertSame(2, $a['capacityPerDay']);
        $this->assertSame(10, $a['totalCapacity']);
        // Terpakai: 5→1, 6→2, 9→1, 10→1 (Z sampai Senin 12, Minggu 11 libur tidak dihitung) = 5.
        $this->assertSame(5, $a['totalUsed']);
        $this->assertSame(5, $a['emptySlots']);
        $this->assertEqualsWithDelta(50.0, $a['utilizationPct'], 0.0001);
    }

    public function test_used_slots_equal_the_capacity_validation_count_day_by_day(): void
    {
        $this->week();

        $expected = 0;
        for ($d = Carbon::parse('2026-10-05'); $d->lte(Carbon::parse('2026-10-11')); $d->addDay()) {
            if (! $this->storeA->fresh()->isClosedOn($d)) {
                $expected += Booking::confirmedOverlapCount($this->storeA->id, $d->copy());
            }
        }

        $this->assertSame($expected, $this->row($this->report(self::WEEK), $this->storeA)['totalUsed']);
    }

    public function test_default_capacity_is_three_and_an_idle_store_has_zero_utilisation(): void
    {
        $this->week();

        $b = $this->row($this->report(self::WEEK), $this->storeB);

        $this->assertSame(7, $b['workingDays']);
        $this->assertSame(3, $b['capacityPerDay']);
        $this->assertSame(21, $b['totalCapacity']);
        $this->assertSame(0, $b['totalUsed']);
        $this->assertSame(21, $b['emptySlots']);
        $this->assertEquals(0, $b['utilizationPct']);
        $this->assertEquals(0, $b['cancellationRatePct']);
    }

    public function test_cancellations_and_the_rate_count_all_statuses_by_scheduled_date(): void
    {
        $this->week();

        $result = $this->report(self::WEEK);
        $a = $this->row($result, $this->storeA);

        $this->assertSame(1, $a['cancelledCount']);
        $this->assertSame(5, $a['totalBookingsCount'], 'X, Y, Z, batal, pending; yang jadwalnya tanggal 20 tidak ikut.');
        $this->assertEqualsWithDelta(20.0, $a['cancellationRatePct'], 0.0001);
        $this->assertEqualsWithDelta(20.0, $result['cancellationRatePct'], 0.0001, 'KPI seluruh cabang = total batal / total booking.');
    }

    public function test_utilisation_is_capped_at_one_hundred_percent_and_empty_slots_never_go_negative(): void
    {
        // Kapasitas 2/hari, tiga booking confirmed menyentuh Selasa 6 Okt (lebih dari kapasitas).
        $this->booking('confirmed', '2026-10-06');
        $this->booking('confirmed', '2026-10-06');
        $this->booking('confirmed', '2026-10-06');

        $a = $this->row($this->report(['from' => '2026-10-06', 'to' => '2026-10-06']), $this->storeA);

        $this->assertSame(1, $a['workingDays']);
        $this->assertSame(3, $a['totalUsed']);
        $this->assertSame(0, $a['emptySlots']);
        $this->assertEquals(100, $a['utilizationPct']);
    }

    public function test_a_closed_only_range_has_no_capacity_and_no_division_error(): void
    {
        $a = $this->row($this->report(['from' => '2026-10-11', 'to' => '2026-10-11']), $this->storeA);

        $this->assertSame(0, $a['workingDays']);
        $this->assertSame(0, $a['totalCapacity']);
        $this->assertEquals(0, $a['utilizationPct']);
    }

    public function test_rows_are_sorted_by_utilisation_and_inactive_stores_are_hidden(): void
    {
        $this->week();
        Store::create(['city' => 'Medan', 'address' => 'Jl. C', 'name' => 'Toko Tutup', 'is_active' => false]);

        $names = collect($this->report(self::WEEK)['rows'])->map(fn ($r) => $r['store']->name)->all();

        $this->assertSame(['Toko A', 'Toko B'], $names);
    }

    public function test_the_range_is_capped_at_62_days_and_flagged_when_cut(): void
    {
        $exact = $this->report(['from' => '2026-08-01', 'to' => '2026-10-01']);
        $this->assertFalse($exact['truncated']);
        $this->assertSame('2026-10-01', $exact['to']->toDateString());

        $cut = $this->report(['from' => '2026-08-01', 'to' => '2026-12-31']);
        $this->assertTrue($cut['truncated']);
        $this->assertSame('2026-10-01', $cut['to']->toDateString(), '62 hari inklusif: 1 Agustus s/d 1 Oktober.');

        $this->page(['from' => '2026-08-01', 'to' => '2026-12-31'])->assertSee('Rentang melebihi 62 hari');
    }

    public function test_first_day_with_a_time_in_the_start_date_is_still_counted(): void
    {
        $this->booking('confirmed', '2026-10-05');

        $a = $this->row($this->report(['from' => '2026-10-05 15:00:00', 'to' => '2026-10-05']), $this->storeA);

        $this->assertSame(1, $a['workingDays']);
        $this->assertSame(1, $a['totalUsed']);
    }

    // ------------------------------------------------------------- akses & toko

    public function test_staff_see_only_their_store_and_an_account_without_a_store_sees_none(): void
    {
        $this->week();

        $own = $this->report(self::WEEK, $this->user('kasir', $this->storeB));
        $this->assertSame(['Toko B'], collect($own['rows'])->map(fn ($r) => $r['store']->name)->all());
        $this->assertEquals(0, $own['cancellationRatePct']);

        $none = $this->report(self::WEEK, $this->user('kasir', null));
        $this->assertCount(0, $none['rows']);
        $this->page(self::WEEK, $this->user('kasir', null))->assertSee('Tidak ada toko yang bisa diakses.');
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(ReservationUtilizationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['ReservationUtilizationReport']]), 'web');
        $this->assertTrue(ReservationUtilizationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(ReservationUtilizationReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_reversed_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '2026-10-03'])->test(ReservationUtilizationReport::class);
        $this->assertSame('2026-10-01', $bad->get('from'));
        $this->assertSame('2026-10-03', $bad->get('to'));

        $empty = Livewire::withQueryParams(['from' => '', 'to' => ''])->test(ReservationUtilizationReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$empty->get('from'), $empty->get('to')]);

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(ReservationUtilizationReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());
    }

    public function test_quick_periods_fill_the_dates(): void
    {
        $page = $this->page();

        $page->set('data.preset', 'last_month');
        $this->assertSame(['2026-09-01', '2026-09-30'], [$page->get('data.from'), $page->get('data.to')]);

        $page->set('data.preset', 'this_month');
        $this->assertSame(['2026-10-01', '2026-10-31'], [$page->get('data.from'), $page->get('data.to')]);
    }

    // ------------------------------------------------------------- tampilan & ekspor

    public function test_page_shows_the_rows_the_approximation_note_and_the_drill_down_link(): void
    {
        $this->week();

        $page = $this->page(self::WEEK);
        $page->assertSuccessful()
            ->assertSee('Toko A')
            ->assertSee('Toko B')
            ->assertSee('Tingkat Pembatalan (Seluruh Cabang)')
            ->assertSee('20.0%', false)
            ->assertSee('50.0%', false)
            ->assertSee('BUKAN kapasitas persis', false);

        $url = $page->instance()->reservationUrl($this->storeA->id);
        $this->assertStringContainsString('cabang=' . $this->storeA->id, $url);
        $this->assertStringContainsString('from=2026-10-05', $url);
        $this->assertStringContainsString('to=2026-10-11', $url);
    }

    public function test_excel_rows_are_aligned_with_headings_and_percentages_are_numbers(): void
    {
        $this->week();

        $export = new ReservationUtilizationReportExport($this->report(self::WEEK));
        $rows = $export->array();

        $this->assertSame(['Toko', 'Hari Kerja', 'Kapasitas/Hari', 'Total Kapasitas', 'Terpakai', 'Kosong', 'Dibatalkan', 'Tingkat Pembatalan %', 'Utilisasi %'], $export->headings());
        $this->assertSame(['Toko A', 5, 2, 10, 5, 5, 1, 20.0, 50.0], $rows[0]);
        $this->assertEquals(['Toko B', 7, 3, 21, 0, 21, 0, 0, 0], $rows[1]);
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->week();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page(self::WEEK, $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('utilisasi-reservasi-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['xlsx', 'pdf'], $logs->map(fn ($l) => $l->properties['format'])->all());
        $this->assertSame('reservation_utilization', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty_or_cut(): void
    {
        $this->week();
        $this->page(self::WEEK)->callAction('exportPdf')->assertHasNoActionErrors();
        $this->page(['from' => '2026-08-01', 'to' => '2026-12-31'])->callAction('exportPdf')->assertHasNoActionErrors();
        $this->page(self::WEEK, $this->user('kasir', null))->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
