<?php

namespace Tests\Feature;

use App\Exports\SalesSummaryExport;
use App\Filament\Pages\SalesSummaryReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ringkasan Penjualan (melengkapi SalesSummaryReportTest): angka (penjualan kotor, PPN, voucher, refund, bersih),
 * pembatasan toko, kolom pembanding (termasuk nilai tanggal berjam dari DatePicker), sanitasi query string,
 * koreksi tanggal terbalik, isi ekspor Excel/PDF, dan log ekspor yang mencatat toko yang sebenarnya berlaku.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class SalesSummaryTest extends TestCase
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

    private function sale(float $amount, string $date, ?Store $store = null, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $date, 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $overrides));

        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking;
    }

    private function voucherUse(Booking $booking, float $discount, string $usedAt, string $status = 'used'): VoucherClaim
    {
        $voucher = Voucher::create(['name' => 'Diskon ' . uniqid(), 'discount_amount' => $discount, 'total_stock' => 10, 'claimed_count' => 1, 'is_active' => true]);

        return VoucherClaim::create([
            'voucher_id' => $voucher->id, 'walkin_name' => 'Tamu', 'code' => strtoupper(uniqid()), 'status' => $status,
            'booking_id' => $booking->id, 'used_at' => $usedAt,
        ]);
    }

    private function page(array $data = [])
    {
        $page = Livewire::test(SalesSummaryReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    /** Oktober 2026: A 1.000.000, A 500.000, B 300.000; PPN 99.000 + 49.500 + 29.700. */
    private function october(): array
    {
        $a1 = $this->sale(1000000, '2026-10-03', $this->storeA);
        $a2 = $this->sale(500000, '2026-10-08', $this->storeA);
        $b1 = $this->sale(300000, '2026-10-05', $this->storeB);
        // Booking baru menghitung PPN otomatis; tetapkan eksplisit supaya total tes pasti (178.200).
        DB::table('bookings')->where('id', $a1->id)->update(['ppn_amount' => 99000]);
        DB::table('bookings')->where('id', $a2->id)->update(['ppn_amount' => 49500]);
        DB::table('bookings')->where('id', $b1->id)->update(['ppn_amount' => 29700]);

        return [$a1, $a2, $b1];
    }

    // ------------------------------------------------------------- angka

    public function test_figures_gross_ppn_vouchers_refund_and_net(): void
    {
        [$a1, $a2, $b1] = $this->october();
        app(RefundService::class)->process($a1, 200000, 'Batal sebagian', null);
        $this->voucherUse($a1, 50000, '2026-10-03 11:00:00');
        $this->voucherUse($b1, 25000, '2026-10-05 11:00:00');
        $this->voucherUse($a2, 999000, '2026-10-08 11:00:00', 'active');      // belum terpakai: tidak ikut
        $this->voucherUse($a2, 888000, '2026-09-20 11:00:00');                // dipakai bulan lalu: tidak ikut
        $this->actingAs($this->admin(), 'web');

        $result = $this->page()->instance()->getResult();

        $this->assertEquals(1800000.0, $result['grossSales']);
        $this->assertEquals(178200.0, $result['ppnAmount']);
        $this->assertEquals(75000.0, $result['voucherDiscount']);
        $this->assertEquals(200000.0, $result['refund']);
        $this->assertEquals(1600000.0, $result['netSales']);
        $this->assertSame(3, $result['bookingCount']);
        $this->assertNull($result['compare']);
    }

    public function test_the_default_range_is_the_current_month_and_the_range_is_inclusive(): void
    {
        $this->sale(1, '2026-09-30', $this->storeA);
        $this->sale(10, '2026-10-01', $this->storeA);
        $this->sale(100, '2026-10-31', $this->storeA);
        $this->sale(1000, '2026-11-01', $this->storeA);
        $this->actingAs($this->admin(), 'web');

        $page = $this->page();
        $result = $page->instance()->getResult();

        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($page->get('data.from'))->toDateString(), Carbon::parse($page->get('data.to'))->toDateString()]);
        $this->assertEquals(110.0, $result['grossSales']);
        $this->assertSame(2, $result['bookingCount']);
    }

    public function test_voucher_discount_is_counted_on_the_day_it_was_used_and_scoped_to_the_store(): void
    {
        [$a1, , $b1] = $this->october();
        $this->voucherUse($a1, 50000, '2026-10-31 23:30:00');
        $this->voucherUse($b1, 25000, '2026-10-05 08:00:00');
        $this->actingAs($this->admin(), 'web');

        $this->assertEquals(75000.0, $this->page()->instance()->getResult()['voucherDiscount']);
        $this->assertEquals(50000.0, $this->page(['store_id' => $this->storeA->id])->instance()->getResult()['voucherDiscount']);
        $this->assertEquals(25000.0, $this->page(['store_id' => $this->storeB->id])->instance()->getResult()['voucherDiscount']);
        $this->assertEquals(0.0, $this->page(['from' => '2026-10-01', 'to' => '2026-10-30'])->instance()->getResult()['voucherDiscount'] - 25000.0, 'Voucher 31 Okt malam tidak masuk rentang sampai 30 Okt.');
    }

    public function test_pending_bookings_are_counted_regardless_of_the_date_range(): void
    {
        $this->october();
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '081399999999']);
        Booking::create(['booking_number' => 'BKG-PEND', 'customer_id' => $customer->id, 'store_id' => $this->storeA->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2025-01-01', 'status' => 'completed', 'transaction_amount' => 1000]);
        $this->actingAs($this->admin(), 'web');

        $this->assertSame(1, $this->page()->instance()->getResult()['pendingCount']);
        $this->assertSame(1, $this->page(['from' => '2024-01-01', 'to' => '2024-01-31'])->instance()->getResult()['pendingCount']);
    }

    // ------------------------------------------------------------- toko

    public function test_admin_chooses_the_store_while_staff_is_locked_and_the_log_names_the_effective_store(): void
    {
        $this->october();
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        $this->assertEquals(300000.0, $this->page(['store_id' => $this->storeB->id])->instance()->getResult()['grossSales']);
        $this->assertSame($this->storeB->id, $this->page(['store_id' => $this->storeB->id])->instance()->getResult()['storeId']);

        $staff = $this->user('kasir', $this->storeA);
        $this->actingAs($staff, 'web');
        $result = $this->page(['store_id' => $this->storeB->id])->instance()->getResult();

        $this->assertEquals(1500000.0, $result['grossSales'], 'Staf tidak bisa melihat toko lain.');
        $this->assertSame($this->storeA->id, $result['storeId']);
    }

    // ------------------------------------------------------------- pembanding

    public function test_comparison_for_a_full_month_a_free_range_and_the_previous_year(): void
    {
        $this->sale(400000, '2026-09-10', $this->storeA);
        $this->sale(100000, '2026-09-30', $this->storeA);
        $this->sale(700000, '2025-10-04', $this->storeA);
        $this->sale(1000000, '2026-10-03', $this->storeA);
        $this->actingAs($this->admin(), 'web');
        $page = $this->page(['compare' => 'prev_period']);

        $result = $page->instance()->getResult();
        $this->assertSame('01 Sep 2026 – 30 Sep 2026', $result['compareLabel']);
        $this->assertEquals(500000.0, $result['compare']['net']);
        $this->assertSame(2, $result['compare']['count']);

        $page->set('data.from', '2026-10-01')->set('data.to', '2026-10-10');
        $this->assertSame('21 Sep 2026 – 30 Sep 2026', $page->instance()->getResult()['compareLabel'], '10 hari mundur 10 hari.');

        $page->set('data.compare', 'prev_year')->set('data.from', '2026-10-01')->set('data.to', '2026-10-31');
        $this->assertSame('01 Oct 2025 – 31 Oct 2025', $page->instance()->getResult()['compareLabel']);
        $this->assertEquals(700000.0, $page->instance()->getResult()['compare']['net']);

        $page->set('data.compare', null);
        $this->assertNull($page->instance()->getResult()['compare']);
    }

    public function test_comparison_survives_a_start_date_that_carries_a_time(): void
    {
        $this->sale(500000, '2026-09-10', $this->storeA);
        $this->actingAs($this->admin(), 'web');

        $result = $this->page(['compare' => 'prev_period', 'from' => '2026-10-01 10:00:00', 'to' => '2026-10-31 10:00:00'])->instance()->getResult();

        $this->assertSame('01 Sep 2026 – 30 Sep 2026', $result['compareLabel']);
        $this->assertEquals(500000.0, $result['compare']['net']);
    }

    // ------------------------------------------------------------- akses, query string, tanggal

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(SalesSummaryReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SalesSummaryReport']]), 'web');
        $this->assertTrue(SalesSummaryReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(SalesSummaryReport::canAccess());
    }

    public function test_the_link_from_the_dashboard_prefills_the_range_and_bad_values_fall_back(): void
    {
        $this->actingAs($this->admin(), 'web');

        $linked = Livewire::withQueryParams(['from' => '2026-09-05', 'to' => '2026-09-20', 'cabang' => (string) $this->storeB->id])->test(SalesSummaryReport::class);
        $this->assertSame(['2026-09-05', '2026-09-20'], [Carbon::parse($linked->get('data.from'))->toDateString(), Carbon::parse($linked->get('data.to'))->toDateString()]);
        $this->assertEquals($this->storeB->id, $linked->get('data.store_id'));

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => ''])->test(SalesSummaryReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [Carbon::parse($bad->get('data.from'))->toDateString(), Carbon::parse($bad->get('data.to'))->toDateString()]);

        $reversed = Livewire::withQueryParams(['from' => '2026-09-30', 'to' => '2026-09-01'])->test(SalesSummaryReport::class);
        $this->assertSame('2026-09-30', Carbon::parse($reversed->get('data.to'))->toDateString(), 'Sampai < Dari dikoreksi.');
    }

    public function test_staff_cannot_choose_a_store_through_the_url(): void
    {
        $this->october();
        $this->actingAs($this->user('kasir', $this->storeA), 'web');

        $page = Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(SalesSummaryReport::class);

        $this->assertNull($page->get('storeId'));
        $this->assertEquals(1500000.0, $page->instance()->getResult()['grossSales']);
    }

    public function test_an_end_date_before_the_start_is_corrected_with_a_notice(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = $this->page();

        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');

        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());
        $this->assertSame('2026-10-20', Carbon::parse($page->get('to'))->toDateString(), 'Property URL ikut dikoreksi.');
    }

    public function test_quick_periods_fill_the_dates_and_mirror_to_the_url(): void
    {
        $this->actingAs($this->admin(), 'web');
        $page = $this->page();

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => [$from, $to]) {
            $page->set('data.preset', $preset);
            $this->assertSame($from, Carbon::parse($page->get('data.from'))->toDateString(), $preset);
            $this->assertSame($to, Carbon::parse($page->get('data.to'))->toDateString(), $preset);
        }
    }

    public function test_the_detail_link_carries_the_range_and_store(): void
    {
        $this->actingAs($this->admin(), 'web');

        $url = urldecode($this->page(['store_id' => $this->storeA->id, 'from' => '2026-10-01', 'to' => '2026-10-15'])->instance()->salesUrl());

        $this->assertStringContainsString('[entry_date][from]=2026-10-01', $url);
        $this->assertStringContainsString('[entry_date][until]=2026-10-15', $url);
        $this->assertStringContainsString('[store_id][value]=' . $this->storeA->id, $url);
    }

    // ------------------------------------------------------------- ekspor

    private function exportRows(array $result): array
    {
        return (new SalesSummaryExport($result))->array();
    }

    private function resultFor(array $data = []): array
    {
        return $this->page($data)->instance()->getResult();
    }

    public function test_excel_export_mirrors_the_page_rows(): void
    {
        [$a1] = $this->october();
        app(RefundService::class)->process($a1, 200000, null, null);
        $this->voucherUse($a1, 50000, '2026-10-03 11:00:00');
        $this->actingAs($this->admin(), 'web');

        $rows = $this->exportRows($this->resultFor());
        $by = collect($rows)->keyBy(fn ($r) => $r[0] ?? '');

        $this->assertSame(['Ringkasan Penjualan'], $rows[0]);
        $this->assertSame(['Periode', '01 Oct 2026 - 31 Oct 2026'], $rows[1]);
        $this->assertSame('1.800.000', $by['Penjualan Kotor'][1]);
        $this->assertSame('Tidak berlaku', $by['Ongkos Kirim'][1]);
        $this->assertSame('178.200', $by['Pajak (PPN 11%, sudah termasuk dalam Penjualan Kotor)'][1]);
        $this->assertSame('(50.000)', $by['Promo Voucher'][1]);
        $this->assertSame('(200.000)', $by['Pengembalian (Refund)'][1]);
        $this->assertSame('1.600.000', $by['Total Penjualan Bersih'][1]);
        $this->assertSame('Belum tersedia', $by['HPP (Harga Pokok Penjualan)'][1]);
        $this->assertSame(3, $by['Jumlah Transaksi'][1]);
    }

    public function test_excel_export_shows_a_dash_when_there_is_no_refund_and_its_section_rows_line_up_with_the_styles(): void
    {
        $this->october();
        $this->actingAs($this->admin(), 'web');
        $result = $this->resultFor();
        $export = new SalesSummaryExport($result);
        $rows = $export->array();

        $this->assertSame('-', collect($rows)->first(fn ($r) => ($r[0] ?? '') === 'Pengembalian (Refund)')[1]);

        // styles() memakai nomor baris tetap: pastikan baris-baris itu memang judul seksinya.
        $styles = $export->styles(new Worksheet());
        foreach ([4 => 'PENDAPATAN', 11 => 'BIAYA PROMOSI', 16 => 'PENJUALAN BERSIH', 21 => 'LABA KOTOR'] as $row => $title) {
            $this->assertSame([$title], $rows[$row - 1], "Baris {$row} harus judul {$title}");
            $this->assertArrayHasKey("A{$row}", $styles);
        }
    }

    // ------------------------------------------------------------- tampilan, PDF, log

    public function test_page_cards_and_the_comparison_line(): void
    {
        $this->october();
        $this->sale(750000, '2026-09-10', $this->storeA);
        $this->actingAs($this->admin(), 'web');

        $this->page(['compare' => 'prev_period'])
            ->assertSuccessful()
            ->assertSee('Total Penjualan Bersih')
            ->assertSee('Rp1.800.000')
            ->assertSee('Pembanding: 01 Sep 2026 – 30 Sep 2026')
            ->assertSee('vs Rp750.000')
            ->assertSee('+140,0%')
            ->assertSee('Tidak berlaku')
            ->assertSee('Belum tersedia');
    }

    public function test_page_empty_state_and_pending_banner(): void
    {
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '081388888888']);
        Booking::create(['booking_number' => 'BKG-PEND', 'customer_id' => $customer->id, 'store_id' => $this->storeA->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-02', 'status' => 'completed', 'transaction_amount' => 1000]);
        $this->actingAs($this->admin(), 'web');

        $this->page()->assertSee('Belum ada penjualan tercatat untuk periode ini.')->assertSee('sudah selesai tapi belum diproses ke pendapatan');
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        $this->actingAs($staff, 'web');
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id]);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('ringkasan-penjualan-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all(), 'Log mencatat toko yang berlaku (toko staf), bukan isi filter.');
        $this->assertSame(['pdf', 'xlsx'], $logs->map(fn ($l) => $l->properties['format'])->sort()->values()->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page(['compare' => 'prev_year'])->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
