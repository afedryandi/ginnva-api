<?php

namespace Tests\Feature;

use App\Exports\ReservationReportExport;
use App\Filament\Pages\ReservationReport;
use App\Models\Booking;
use App\Models\Customer;
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
 * Laporan Reservasi (jadwal instalasi, bukan pendapatan): hanya status "terkonfirmasi" dan "menunggu approval"
 * menurut tanggal jadwal, urut tanggal; statistik Dibuat/Selesai/Dibatalkan/Tingkat Pembatalan dihitung dari waktu
 * booking diajukan (semua status, termasuk hari pertama berjam); filter status & toko; nama pelanggan dan label
 * layanan lengkap; cakupan toko; sanitasi URL; Excel/PDF + log ekspor. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class ReservationReportTest extends TestCase
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

    private function admin(): User
    {
        return $this->user('super_admin');
    }

    private function reservation(string $status, string $preferred, string $created, ?Store $store = null, array $overrides = [], string $customerName = 'Budi'): Booking
    {
        $customer = Customer::create(['name' => $customerName, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $preferred, 'duration_days' => 2, 'status' => $status,
            'transaction_amount' => 1000000,
        ], $overrides));
        DB::table('bookings')->where('id', $booking->id)->update(['created_at' => $created]);

        return $booking->fresh();
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->admin(), 'web');
        $page = Livewire::test(ReservationReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    /** Oktober 2026 -- lihat komentar tiap baris untuk peran tiap booking. */
    private function october(): array
    {
        return [
            'confirmed' => $this->reservation('confirmed', '2026-10-10', '2026-10-02 09:00:00', $this->storeA, ['product_detailing' => true], 'Budi Aplikasi'),
            'pending' => $this->reservation('pending', '2026-10-12', '2026-10-03 09:00:00', $this->storeB, ['product_ppf' => false, 'product_kaca_film' => true]),
            'completed' => $this->reservation('completed', '2026-10-05', '2026-10-04 09:00:00'),              // bukan reservasi aktif, tapi dihitung "selesai"
            'cancelled' => $this->reservation('cancelled', '2026-10-15', '2026-10-05 09:00:00'),              // dihitung "dibatalkan"
            'later' => $this->reservation('confirmed', '2026-11-02', '2026-10-06 09:00:00'),                  // jadwal di luar rentang, tapi dibuat di rentang
            'olderCreated' => $this->reservation('pending', '2026-10-20', '2026-09-30 09:00:00'),            // jadwal di rentang, dibuat sebelum rentang
        ];
    }

    // ------------------------------------------------------------- tabel

    public function test_only_confirmed_and_pending_bookings_scheduled_in_the_range_are_listed_in_date_order(): void
    {
        $b = $this->october();

        $result = $this->report();

        $this->assertSame([$b['confirmed']->id, $b['pending']->id, $b['olderCreated']->id], $result['bookings']->pluck('id')->all());
        $this->assertSame(3, $result['totalCount']);
        $this->assertSame(1, $result['confirmedCount']);
        $this->assertSame(2, $result['pendingCount']);
    }

    public function test_the_status_filter_narrows_the_table_but_not_the_created_statistics(): void
    {
        $b = $this->october();

        $confirmed = $this->report(['status' => 'confirmed']);
        $pending = $this->report(['status' => 'pending']);

        $this->assertSame([$b['confirmed']->id], $confirmed['bookings']->pluck('id')->all());
        $this->assertSame([$b['pending']->id, $b['olderCreated']->id], $pending['bookings']->pluck('id')->all());
        $this->assertSame(5, $confirmed['totalCreated'], 'Statistik "Dibuat" tidak mengikuti filter status.');
    }

    public function test_the_schedule_range_is_inclusive(): void
    {
        $first = $this->reservation('confirmed', '2026-10-01', '2026-10-01 08:00:00');
        $last = $this->reservation('pending', '2026-10-31', '2026-10-01 08:00:00');
        $this->reservation('confirmed', '2026-09-30', '2026-10-01 08:00:00');
        $this->reservation('confirmed', '2026-11-01', '2026-10-01 08:00:00');

        $this->assertSame([$first->id, $last->id], $this->report()['bookings']->pluck('id')->all());
    }

    // ------------------------------------------------------------- statistik

    public function test_created_completed_cancelled_and_the_cancellation_rate(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(5, $result['totalCreated'], 'Dibuat 2–6 Okt; yang dibuat 30 Sep tidak ikut.');
        $this->assertSame(1, $result['totalCompleted']);
        $this->assertSame(1, $result['totalCancelled']);
        $this->assertEqualsWithDelta(20.0, $result['cancellationRate'], 0.0001);
    }

    public function test_the_statistics_keep_the_whole_first_day_when_the_start_date_has_a_time(): void
    {
        $this->reservation('cancelled', '2026-10-20', '2026-10-01 08:00:00');
        $this->reservation('pending', '2026-10-21', '2026-10-01 12:00:00');

        $result = $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31']);

        $this->assertSame(2, $result['totalCreated'], 'Booking dibuat jam 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
        $this->assertSame(1, $result['totalCancelled']);
        $this->assertEqualsWithDelta(50.0, $result['cancellationRate'], 0.0001);
    }

    public function test_an_empty_period_has_a_zero_rate_instead_of_an_error(): void
    {
        $result = $this->report(['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertSame(0, $result['totalCreated']);
        $this->assertSame(0, $result['totalCount']);
        $this->assertEquals(0.0, $result['cancellationRate']);
    }

    // ------------------------------------------------------------- toko & akses

    public function test_admin_filters_by_store_and_staff_are_locked_to_their_own(): void
    {
        $b = $this->october();

        $onlyB = $this->report(['store_id' => $this->storeB->id]);
        $this->assertSame([$b['pending']->id], $onlyB['bookings']->pluck('id')->all());
        $this->assertSame(1, $onlyB['totalCreated']);

        $staff = $this->user('kasir', $this->storeA);
        $result = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertSame([$b['confirmed']->id, $b['olderCreated']->id], $result['bookings']->pluck('id')->all(), 'Staf tidak bisa melihat toko lain.');
        $this->assertSame($this->storeA->id, $result['storeId']);
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertSame(0, $result['totalCount']);
        $this->assertSame(0, $result['totalCreated']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(ReservationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['ReservationReport']]), 'web');
        $this->assertTrue(ReservationReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(ReservationReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->admin(), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'status' => 'completed', 'cabang' => (string) $this->storeB->id])->test(ReservationReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()]);
        $this->assertNull($bad->get('data.status') ?: null, 'Status selain confirmed/pending diabaikan.');
        $this->assertEquals($this->storeB->id, $bad->get('data.store_id'));

        $valid = Livewire::withQueryParams(['status' => 'confirmed'])->test(ReservationReport::class);
        $this->assertSame('confirmed', $valid->get('data.status'));

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(ReservationReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(ReservationReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());
    }

    // ------------------------------------------------------------- ekspor & tampilan

    public function test_excel_rows_use_display_names_full_service_labels_installers_and_numeric_amounts(): void
    {
        $b = $this->october();
        $tech = $this->user('kasir', $this->storeA, ['name' => 'Rina Teknisi']);
        $other = $this->user('kasir', $this->storeA, ['name' => 'Andi Teknisi']);
        $b['confirmed']->installers()->attach([$tech->id, $other->id]);

        $export = new ReservationReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['No. Booking', 'Tanggal Buat', 'Tanggal Diinginkan', 'Durasi (hari)', 'Pelanggan', 'Toko', 'Layanan', 'Teknisi', 'Total Tagihan', 'Status'], $export->headings());
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertCount(count($export->headings()), $row);
        }
        $this->assertSame([$b['confirmed']->booking_number, '2026-10-02', '2026-10-10', 2, 'Budi Aplikasi', 'Toko A', 'PPF + Detailing'], array_slice($rows[0], 0, 7));
        $this->assertSame(['Rina Teknisi, Andi Teknisi', 1000000.0, 'Terkonfirmasi'], array_slice($rows[0], 7, 3));
        $this->assertSame(['Kaca Film', '-', 'Menunggu Approval'], [$rows[1][6], $rows[1][7], $rows[1][9]]);
    }

    public function test_page_shows_statistics_rows_and_the_empty_state(): void
    {
        $b = $this->october();

        $this->page()
            ->assertSuccessful()
            ->assertSee('Total Reservasi Dibuat')
            ->assertSee('Tingkat Pembatalan')
            ->assertSee('20.0%', false)
            ->assertSee($b['confirmed']->booking_number)
            ->assertSee('Budi Aplikasi')
            ->assertSee('PPF + Detailing')
            ->assertSee('Terkonfirmasi')
            ->assertSee('Menunggu Approval');

        $this->assertStringContainsString('/bookings/' . $b['confirmed']->id, $this->page()->instance()->bookingUrl($b['confirmed']->id));
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Tidak ada reservasi pada rentang ini.');
    }

    public function test_exports_download_and_the_log_records_the_effective_store_and_status(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id, 'status' => 'confirmed'], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-reservasi-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
        $this->assertSame(['confirmed', 'confirmed'], $logs->map(fn ($l) => $l->properties['status'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
