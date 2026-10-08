<?php

namespace Tests\Feature;

use App\Exports\VoidReportExport;
use App\Filament\Pages\VoidReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
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
 * Laporan Void (booking yang dibatalkan): sumber data (log perubahan status ke "cancelled"), rentang tanggal
 * inklusif termasuk hari pertama berjam, cakupan toko, label "dibatalkan oleh" (customer / staf / sistem /
 * data lama), nama pelanggan & produk, potensi pendapatan hilang, isi Excel (judul kolom sejajar dengan data),
 * PDF, tampilan halaman, dan log ekspor. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class VoidReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

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

    private function booking(?Store $store = null, array $overrides = [], string $customerName = 'Budi'): Booking
    {
        $customer = Customer::create(['name' => $customerName, 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-20', 'status' => 'pending',
            'transaction_amount' => 1000000,
        ], $overrides));
    }

    /** Batalkan lewat jalur resmi pada waktu tertentu; $by dipakai sebagai pelaku yang login (sumber causer log). */
    private function cancel(Booking $booking, string $when, string $byType = 'staff', ?User $by = null, ?string $reason = 'Customer berubah pikiran'): Booking
    {
        $by ??= $this->admin();
        $this->actingAs($by, 'web');
        Carbon::setTestNow($when);
        $booking->cancelWith($byType, $byType === 'staff' ? $by->id : null, $reason);
        Carbon::setTestNow('2026-10-08 10:00:00');

        return $booking->fresh();
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->admin(), 'web');
        $page = Livewire::test(VoidReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    // ------------------------------------------------------------- sumber & angka

    public function test_only_status_changes_to_cancelled_are_listed_newest_first_with_the_lost_revenue(): void
    {
        $first = $this->cancel($this->booking(null, ['transaction_amount' => 1000000]), '2026-10-03 09:00:00');
        $second = $this->cancel($this->booking(null, ['transaction_amount' => 500000]), '2026-10-06 15:30:00');
        $confirmed = $this->booking(null, ['transaction_amount' => 999]);
        $this->actingAs($this->admin(), 'web');
        $confirmed->update(['status' => 'confirmed']);
        $this->booking();   // masih pending

        $result = $this->report();

        $this->assertSame([$second->id, $first->id], $result['events']->pluck('subject.id')->all(), 'Terbaru dulu.');
        $this->assertSame(2, $result['totalCount']);
        $this->assertEquals(1500000.0, $result['totalLostRevenue']);
    }

    public function test_a_cancelled_booking_without_a_transaction_amount_counts_as_zero_lost(): void
    {
        $this->cancel($this->booking(null, ['transaction_amount' => null]), '2026-10-03 09:00:00');

        $result = $this->report();

        $this->assertSame(1, $result['totalCount']);
        $this->assertEquals(0.0, $result['totalLostRevenue']);
    }

    public function test_the_range_is_inclusive_and_a_start_date_with_a_time_keeps_the_whole_first_day(): void
    {
        $early = $this->cancel($this->booking(), '2026-10-01 08:00:00');
        $last = $this->cancel($this->booking(), '2026-10-31 23:30:00');
        $this->cancel($this->booking(), '2026-09-30 23:59:00');
        $this->cancel($this->booking(), '2026-11-01 00:00:00');

        $ids = fn (array $r) => $r['events']->pluck('subject.id')->sort()->values()->all();

        $this->assertSame([$early->id, $last->id], $ids($this->report(['from' => '2026-10-01', 'to' => '2026-10-31'])));
        $this->assertSame([$early->id, $last->id], $ids($this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])), 'Pembatalan jam 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
    }

    public function test_a_deleted_booking_is_skipped_without_breaking_the_report(): void
    {
        $gone = $this->cancel($this->booking(), '2026-10-03 09:00:00');
        $kept = $this->cancel($this->booking(), '2026-10-04 09:00:00');
        DB::table('bookings')->where('id', $gone->id)->delete();

        $result = $this->report();

        $this->assertSame([$kept->id], $result['events']->pluck('subject.id')->all());
    }

    // ------------------------------------------------------------- cakupan toko

    public function test_admin_filters_by_store_and_staff_are_locked_to_their_own(): void
    {
        $a = $this->cancel($this->booking($this->storeA), '2026-10-03 09:00:00');
        $b = $this->cancel($this->booking($this->storeB), '2026-10-04 09:00:00');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->report()['events']->pluck('subject.id')->all());
        $this->assertSame([$b->id], $this->report(['store_id' => $this->storeB->id])['events']->pluck('subject.id')->all());

        $staff = $this->user('kasir', $this->storeA);
        $this->assertSame([$a->id], $this->report(['store_id' => $this->storeB->id], $staff)['events']->pluck('subject.id')->all(), 'Staf tidak bisa melihat toko lain.');
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->cancel($this->booking($this->storeA), '2026-10-03 09:00:00');

        $this->assertSame(0, $this->report([], $this->user('kasir', null))['totalCount']);
    }

    // ------------------------------------------------------------- label & nama

    public function test_the_cancelled_by_label_for_customer_staff_system_and_legacy_data(): void
    {
        $admin = $this->admin();
        $staffMember = $this->user('kasir', $this->storeA, ['name' => 'Rina Kasir']);

        $byCustomer = $this->cancel($this->booking(), '2026-10-03 09:00:00', 'customer', $admin);
        $byStaff = $this->cancel($this->booking(), '2026-10-04 09:00:00', 'staff', $staffMember);
        $bySystem = $this->cancel($this->booking(), '2026-10-05 09:00:00', 'system', $admin);
        $legacy = $this->booking();
        $this->actingAs($admin, 'web');
        Carbon::setTestNow('2026-10-06 09:00:00');
        $legacy->update(['status' => 'cancelled']);   // jalur lama: tanpa kolom cancelled_by_*
        Carbon::setTestNow('2026-10-08 10:00:00');

        $labels = $this->report()['events']->mapWithKeys(fn ($e) => [$e->subject->id => $e->subject->cancelledByLabel($e->causer?->name)]);

        $this->assertSame('Customer', $labels[$byCustomer->id]);
        $this->assertSame('Rina Kasir', $labels[$byStaff->id]);
        $this->assertSame('Sistem (otomatis)', $labels[$bySystem->id]);
        $this->assertSame($admin->name, $labels[$legacy->id], 'Data lama: pelaku dari log aktivitas.');
    }

    public function test_customer_names_and_product_labels_cover_app_accounts_and_every_service_type(): void
    {
        $viaApp = $this->cancel($this->booking(null, ['product_detailing' => true], 'Budi Aplikasi'), '2026-10-03 09:00:00');
        $walkIn = $this->cancel($this->booking(null, ['customer_name' => 'Pak Walk-in', 'product_ppf' => false, 'product_premium_wash' => true]), '2026-10-04 09:00:00');
        $deleted = $this->cancel($this->booking(null, [], 'Akan Dihapus'), '2026-10-05 09:00:00');
        $deleted->customer->delete();

        $rows = $this->report()['events']->mapWithKeys(fn ($e) => [$e->subject->id => [$e->subject->display_customer_name, $e->subject->salesProductLabel()]]);

        $this->assertSame(['Budi Aplikasi', 'PPF + Detailing'], $rows[$viaApp->id]);
        $this->assertSame(['Pak Walk-in', 'Premium Wash'], $rows[$walkIn->id]);
        $this->assertSame('Pelanggan Terhapus', $rows[$deleted->id][0]);
    }

    // ------------------------------------------------------------- akses & URL

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(VoidReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['VoidReport']]), 'web');
        $this->assertTrue(VoidReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(VoidReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_staff_cannot_pick_a_store_through_it(): void
    {
        $this->actingAs($this->admin(), 'web');

        $fresh = Livewire::test(VoidReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($fresh->get('data.from'))->toDateString(), Carbon::parse($fresh->get('data.to'))->toDateString()]);

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(VoidReport::class);
        $this->assertSame('2026-10-01', Carbon::parse($bad->get('data.from'))->toDateString());
        $this->assertEquals($this->storeB->id, $bad->get('data.store_id'));

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(VoidReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(VoidReport::class)->get('storeId'));
    }

    public function test_corrections_and_quick_periods_on_the_form(): void
    {
        $page = $this->page();

        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31'], 'this_month' => ['2026-10-01', '2026-10-31']] as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    // ------------------------------------------------------------- ekspor & tampilan

    public function test_excel_headings_line_up_with_the_data_columns(): void
    {
        $this->cancel($this->booking(null, ['transaction_amount' => 750000, 'product_detailing' => true], 'Budi Aplikasi'), '2026-10-03 09:30:00', 'staff', null, 'Salah jadwal');
        $export = new VoidReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['No. Booking', 'Tanggal Order', 'Tanggal Dibatalkan', 'Pelanggan', 'Toko', 'Layanan', 'Dibatalkan Oleh', 'Alasan', 'Nilai Transaksi'], $export->headings());
        $this->assertCount(count($export->headings()), $rows[0], 'Tiap baris harus sebanyak judul kolom (dulu judul "Alasan" hilang).');
        $this->assertSame('2026-10-03 09:30', $rows[0][2]);
        $this->assertSame(['Budi Aplikasi', 'Toko A', 'PPF + Detailing'], array_slice($rows[0], 3, 3));
        $this->assertSame(['Salah jadwal', 750000.0], array_slice($rows[0], 7, 2));
    }

    public function test_excel_uses_a_dash_for_a_missing_reason(): void
    {
        $this->cancel($this->booking(), '2026-10-03 09:00:00', 'customer', null, null);

        $this->assertSame('-', (new VoidReportExport($this->report()))->array()[0][7]);
    }

    public function test_page_shows_the_rows_totals_links_and_the_empty_state(): void
    {
        $booking = $this->cancel($this->booking(null, ['transaction_amount' => 1250000], 'Budi Aplikasi'), '2026-10-03 09:00:00', 'staff', null, 'Salah jadwal');

        $this->page()
            ->assertSuccessful()
            ->assertSee($booking->booking_number)
            ->assertSee('Budi Aplikasi')
            ->assertSee('Salah jadwal')
            ->assertSee('Rp1.250.000')
            ->assertSee('Potensi Pendapatan Hilang')
            ->assertSee('03 Oct 2026 09:00');

        $this->assertStringContainsString('/bookings/' . $booking->id, $this->page()->instance()->bookingUrl($booking->id));
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Tidak ada booking dibatalkan pada rentang ini.');
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->cancel($this->booking($this->storeA), '2026-10-03 09:00:00');
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-void-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->cancel($this->booking(null, ['product_premium_wash' => true]), '2026-10-03 09:00:00');
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
