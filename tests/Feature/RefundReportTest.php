<?php

namespace Tests\Feature;

use App\Exports\RefundReportExport;
use App\Filament\Pages\RefundReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\RefundService;
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
 * Laporan Refund: daftar refund menurut hari diproses (termasuk hari pertama berjam), total & rinciannya
 * (kas keluar vs pengurang piutang), "Metode Pembayaran" per baris (Tunai / Kurangi Piutang / gabungan) yang
 * HARUS sama di layar, Excel, dan PDF, nama pelanggan, cakupan toko, sanitasi URL, serta log ekspor.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class RefundReportTest extends TestCase
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

    private function sale(float $amount, ?Store $store = null, array $overrides = [], string $customerName = 'Budi'): Booking
    {
        $customer = Customer::create(['name' => $customerName, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-03', 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $overrides));

        app(BookingPostingService::class)->sync($booking);

        return $booking->fresh();
    }

    /** Proses refund resmi, lalu geser waktu diprosesnya ke $when. */
    private function refund(Booking $booking, float $amount, string $when = '2026-10-05 09:00:00', ?User $by = null, ?string $reason = 'Customer batal'): Refund
    {
        $refund = app(RefundService::class)->process($booking, $amount, $reason, $by?->id);
        DB::table('refunds')->where('id', $refund->id)->update(['created_at' => $when]);

        return $refund->fresh();
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->admin(), 'web');
        $page = Livewire::test(RefundReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    // ------------------------------------------------------------- daftar & total

    public function test_refunds_are_listed_newest_first_with_totals(): void
    {
        $first = $this->refund($this->sale(1000000), 100000, '2026-10-03 09:00:00');
        $second = $this->refund($this->sale(500000), 50000, '2026-10-06 15:30:00');

        $result = $this->report();

        $this->assertSame([$second->id, $first->id], $result['refunds']->pluck('id')->all());
        $this->assertSame(2, $result['totalCount']);
        $this->assertEquals(150000.0, $result['totalAmount']);
        $this->assertEquals(150000.0, $result['totalCash']);
        $this->assertEquals(0.0, $result['totalReceivableReduced']);
    }

    public function test_the_range_follows_the_day_processed_and_keeps_the_whole_first_day_when_the_start_has_a_time(): void
    {
        $early = $this->refund($this->sale(1000000), 1000, '2026-10-01 08:00:00');
        $last = $this->refund($this->sale(1000000), 2000, '2026-10-31 23:30:00');
        $this->refund($this->sale(1000000), 4000, '2026-09-30 23:59:00');
        $this->refund($this->sale(1000000), 8000, '2026-11-01 00:00:00');

        $ids = fn (array $r) => $r['refunds']->pluck('id')->sort()->values()->all();

        $this->assertSame([$early->id, $last->id], $ids($this->report(['from' => '2026-10-01', 'to' => '2026-10-31'])));
        $this->assertSame([$early->id, $last->id], $ids($this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])), 'Refund jam 08:00 di hari pertama ikut walau "Dari" berjam 10:00.');
    }

    // ------------------------------------------------------------- metode pembayaran

    public function test_payment_method_and_portions_for_cash_receivable_and_mixed_refunds(): void
    {
        $cashOnly = $this->refund($this->sale(1000000), 100000, '2026-10-03 09:00:00');
        // Piutang terbuka 400.000 (diterima 600.000 dari 1.000.000).
        $receivableOnly = $this->refund($this->sale(1000000, null, ['amount_received' => 600000]), 300000, '2026-10-04 09:00:00');
        $mixed = $this->refund($this->sale(1000000, null, ['amount_received' => 600000]), 500000, '2026-10-05 09:00:00');

        $rows = $this->report()['refunds']->keyBy('id');

        $this->assertSame('Tunai', $rows[$cashOnly->id]->payment_method_label);
        $this->assertEquals(100000.0, $rows[$cashOnly->id]->cash_portion);
        $this->assertSame('Kurangi Piutang', $rows[$receivableOnly->id]->payment_method_label);
        $this->assertEquals(0.0, $rows[$receivableOnly->id]->cash_portion);
        $this->assertEquals(300000.0, $rows[$receivableOnly->id]->receivable_portion);
        $this->assertSame('Tunai + Kurangi Piutang', $rows[$mixed->id]->payment_method_label);
        $this->assertEquals(100000.0, $rows[$mixed->id]->cash_portion);
        $this->assertEquals(400000.0, $rows[$mixed->id]->receivable_portion);

        $result = $this->report();
        $this->assertEquals(900000.0, $result['totalAmount']);
        $this->assertEquals(200000.0, $result['totalCash'], '100.000 + 0 + 100.000.');
        $this->assertEquals(700000.0, $result['totalReceivableReduced']);
    }

    // ------------------------------------------------------------- toko & akses

    public function test_admin_filters_by_store_and_staff_are_locked_to_their_own(): void
    {
        $a = $this->refund($this->sale(1000000, $this->storeA), 1000);
        $b = $this->refund($this->sale(1000000, $this->storeB), 2000);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->report()['refunds']->pluck('id')->all());
        $this->assertSame([$b->id], $this->report(['store_id' => $this->storeB->id])['refunds']->pluck('id')->all());

        $staff = $this->user('kasir', $this->storeA);
        $this->assertSame([$a->id], $this->report(['store_id' => $this->storeB->id], $staff)['refunds']->pluck('id')->all(), 'Staf tidak bisa melihat toko lain.');
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->refund($this->sale(1000000, $this->storeA), 1000);

        $this->assertSame(0, $this->report([], $this->user('kasir', null))['totalCount']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(RefundReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['RefundReport']]), 'web');
        $this->assertTrue(RefundReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(RefundReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_quick_periods_fill_the_dates(): void
    {
        $this->actingAs($this->admin(), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(RefundReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()]);
        $this->assertEquals($this->storeB->id, $bad->get('data.store_id'));

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(RefundReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString());

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(RefundReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31'], 'this_month' => ['2026-10-01', '2026-10-31']] as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    // ------------------------------------------------------------- nama pelanggan

    public function test_customer_names_come_from_the_app_account_a_walk_in_name_or_the_deleted_marker(): void
    {
        $viaApp = $this->refund($this->sale(1000000, null, [], 'Budi Aplikasi'), 1000, '2026-10-03 09:00:00');
        $walkIn = $this->refund($this->sale(1000000, null, ['customer_name' => 'Pak Walk-in'], 'Akun Lain'), 2000, '2026-10-04 09:00:00');
        $deletedBooking = $this->sale(1000000, null, [], 'Akan Dihapus');
        $deleted = $this->refund($deletedBooking, 3000, '2026-10-05 09:00:00');
        $deletedBooking->customer->delete();

        $names = $this->report()['refunds']->mapWithKeys(fn ($r) => [$r->id => $r->booking?->display_customer_name]);

        $this->assertSame('Budi Aplikasi', $names[$viaApp->id]);
        $this->assertSame('Pak Walk-in', $names[$walkIn->id]);
        $this->assertSame('Pelanggan Terhapus', $names[$deleted->id]);
    }

    // ------------------------------------------------------------- ekspor & tampilan

    public function test_excel_columns_line_up_and_the_method_matches_the_page(): void
    {
        $processor = $this->user('kasir', $this->storeA, ['name' => 'Rina Kasir']);
        $this->refund($this->sale(1000000), 100000, '2026-10-03 09:30:00', $processor, 'Customer batal');
        $this->refund($this->sale(1000000, null, ['amount_received' => 600000]), 300000, '2026-10-04 09:00:00', null, null);

        $result = $this->report();
        $export = new RefundReportExport($result);
        $rows = $export->array();

        $this->assertSame(['No. Refund', 'Tanggal', 'No. Booking', 'Pelanggan', 'Toko', 'Metode Pembayaran', 'Diproses Oleh', 'No. Jurnal', 'Alasan', 'Nominal'], $export->headings());
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertCount(count($export->headings()), $row);
        }

        $byAmount = collect($rows)->keyBy(fn ($r) => $r[9]);
        $this->assertSame('Tunai', $byAmount[100000.0][5]);
        $this->assertSame('Kurangi Piutang', $byAmount[300000.0][5], 'Dulu selalu "Tunai" di Excel.');
        $this->assertSame(['Budi', 'Toko A', 'Tunai', 'Rina Kasir'], [$byAmount[100000.0][3], $byAmount[100000.0][4], $byAmount[100000.0][5], $byAmount[100000.0][6]]);
        $this->assertStringStartsWith('JE-', $byAmount[100000.0][7]);
        $this->assertSame('Customer batal', $byAmount[100000.0][8]);
        $this->assertSame('-', $byAmount[300000.0][6], 'Tanpa pemroses.');
        $this->assertSame('-', $byAmount[300000.0][8], 'Tanpa alasan.');
        $this->assertSame('2026-10-03 09:30', $byAmount[100000.0][1]);
    }

    public function test_page_shows_totals_methods_and_links(): void
    {
        $booking = $this->sale(1000000, null, ['amount_received' => 600000], 'Budi Aplikasi');
        $refund = $this->refund($booking, 500000, '2026-10-05 09:00:00', null, 'Salah jadwal');

        $this->page()
            ->assertSuccessful()
            ->assertSee('Total Refund')
            ->assertSee('Rp500.000')
            ->assertSee('Refund Tunai (Kas Keluar)')
            ->assertSee('Rp100.000')
            ->assertSee('Pengurang Piutang')
            ->assertSee('Rp400.000')
            ->assertSee($refund->refund_number)
            ->assertSee('Budi Aplikasi')
            ->assertSee('Tunai + Kurangi Piutang')
            ->assertSee('Salah jadwal')
            ->assertSee($refund->journalEntry->entry_number);

        $this->assertStringContainsString('/bookings/' . $booking->id, $this->page()->instance()->bookingUrl($booking->id));
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Tidak ada refund pada rentang ini.');
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->refund($this->sale(1000000, $this->storeA), 1000);
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-refund-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->refund($this->sale(1000000, null, ['amount_received' => 600000]), 500000);
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
