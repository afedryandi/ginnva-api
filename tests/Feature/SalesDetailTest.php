<?php

namespace Tests\Feature;

use App\Exports\SalesExport;
use App\Filament\Resources\BookingResource;
use App\Filament\Resources\SalesResource;
use App\Filament\Resources\SalesResource\Pages\ListSales;
use App\Filament\Widgets\SalesDetailStatsWidget;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingPostingService;
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
 * Detail Penjualan (melengkapi SalesResourceFilterTest): hanya booking yang sudah dijurnal, staf dikunci ke
 * tokonya, nama pelanggan dari relasi aplikasi / walk-in / "Pelanggan Terhapus", label produk lengkap (termasuk
 * Detailing & Premium Wash), status pembayaran (Lunas / Belum Lunas / Void), pencarian, semua filter, kartu
 * ringkasan yang ikut filter, aksi (lihat booking, cetak invoice), serta Excel/PDF + log ekspor.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class SalesDetailTest extends TestCase
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

    private function sale(float $amount, string $date = '2026-10-03', ?Store $store = null, array $overrides = [], string $customerName = 'Budi'): Booking
    {
        $customer = Customer::create(['name' => $customerName, 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => ($store ?? $this->storeA)->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $date, 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $overrides));

        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    private function list(?User $as = null)
    {
        $this->actingAs($as ?? $this->admin(), 'web');

        return Livewire::test(ListSales::class);
    }

    // ------------------------------------------------------------- cakupan data

    public function test_only_bookings_with_a_journal_appear_and_staff_see_their_own_store_only(): void
    {
        $a = $this->sale(100000, '2026-10-03', $this->storeA);
        $b = $this->sale(200000, '2026-10-04', $this->storeB);
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '081377777777']);
        $unprocessed = Booking::create(['booking_number' => 'BKG-PEND', 'customer_id' => $customer->id, 'store_id' => $this->storeA->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-02', 'status' => 'completed', 'transaction_amount' => 50000]);

        $this->list()->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$unprocessed]);
        $this->list($this->user('kasir', $this->storeA))->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $unprocessed]);
    }

    public function test_default_order_is_newest_payment_date_first(): void
    {
        $old = $this->sale(1, '2026-09-01');
        $new = $this->sale(2, '2026-10-07');
        $mid = $this->sale(3, '2026-09-20');

        $this->list()->assertCanSeeTableRecords([$new, $mid, $old], inOrder: true);
    }

    // ------------------------------------------------------------- kolom

    public function test_customer_names_come_from_the_app_account_a_walk_in_name_or_the_deleted_marker(): void
    {
        $viaApp = $this->sale(1000, customerName: 'Budi Aplikasi');
        $walkIn = $this->sale(1000, overrides: ['customer_name' => 'Pak Walk-in'], customerName: 'Akun Lain');
        $deleted = $this->sale(1000, customerName: 'Akan Dihapus');
        $deleted->customer->delete();

        $this->list()
            ->assertTableColumnStateSet('customer_name', 'Budi Aplikasi', $viaApp)
            ->assertTableColumnStateSet('customer_name', 'Pak Walk-in', $walkIn)
            ->assertTableColumnStateSet('customer_name', 'Pelanggan Terhapus', Booking::find($deleted->id));
    }

    public function test_the_product_label_covers_every_service_flag(): void
    {
        $cases = [
            'PPF' => ['product_ppf' => true],
            'Kaca Film' => ['product_ppf' => false, 'product_kaca_film' => true],
            'Kaca Film + PPF' => ['product_kaca_film' => true],
            'PPF + Detailing' => ['product_detailing' => true],
            'Detailing' => ['product_ppf' => false, 'product_detailing' => true],
            'Premium Wash' => ['product_ppf' => false, 'product_premium_wash' => true],
            'Kaca Film + PPF + Detailing + Premium Wash' => ['product_kaca_film' => true, 'product_detailing' => true, 'product_premium_wash' => true],
        ];

        $page = $this->list();
        foreach ($cases as $label => $flags) {
            $booking = $this->sale(1000, overrides: $flags);
            $this->assertSame($label, $booking->salesProductLabel(), $label);
            $page->assertTableColumnStateSet('product', $label, $booking);
        }

        $none = $this->sale(1000, overrides: ['product_ppf' => false]);
        $this->assertNull($none->salesProductLabel());
        $page->assertTableColumnStateSet('product', '—', $none);
    }

    public function test_amounts_outstanding_and_the_payment_status_badge(): void
    {
        $paid = $this->sale(1000000);
        $unpaid = $this->sale(1000000, overrides: ['amount_received' => 400000]);
        $assumedPaid = $this->sale(500000, overrides: ['amount_received' => null]);
        $void = $this->sale(300000);
        DB::table('bookings')->where('id', $void->id)->update(['status' => 'cancelled']);

        $page = $this->list();
        $page->assertTableColumnStateSet('payment_status', 'Lunas', $paid)
            ->assertTableColumnStateSet('payment_status', 'Belum Lunas', $unpaid)
            ->assertTableColumnStateSet('payment_status', 'Lunas', $assumedPaid)
            ->assertTableColumnStateSet('payment_status', 'Void', Booking::find($void->id))
            ->assertTableColumnStateSet('outstanding', 600000.0, $unpaid)
            ->assertTableColumnStateSet('outstanding', 0.0, $assumedPaid)
            ->assertTableColumnFormattedStateSet('outstanding', 'Rp600.000', $unpaid)
            ->assertTableColumnFormattedStateSet('outstanding', '—', $paid)
            ->assertTableColumnFormattedStateSet('amount_received', 'Rp500.000', $assumedPaid, )
            ->assertTableColumnStateSet('invoice_number', 'INV/' . $paid->booking_number, $paid)
            ->assertTableColumnStateSet('journalEntry.entry_number', $paid->journalEntry->entry_number, $paid);
    }

    // ------------------------------------------------------------- pencarian

    public function test_search_by_invoice_booking_number_and_customer_including_the_app_account(): void
    {
        $budi = $this->sale(1000, customerName: 'Budi Santoso');
        $siti = $this->sale(1000, overrides: ['customer_name' => 'Siti Walkin'], customerName: 'Akun Siti');
        $other = $this->sale(1000, customerName: 'Zaenal');

        $this->list()->searchTable('Santoso')->assertCanSeeTableRecords([$budi])->assertCanNotSeeTableRecords([$siti, $other]);
        $this->list()->searchTable('Siti Walkin')->assertCanSeeTableRecords([$siti])->assertCanNotSeeTableRecords([$budi, $other]);
        $this->list()->searchTable($other->booking_number)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$budi, $siti]);
        $this->list()->searchTable('INV/' . substr($budi->booking_number, 0, 12))->assertCanSeeTableRecords([$budi]);
    }

    // ------------------------------------------------------------- filter

    public function test_payment_status_filter_with_the_rounding_tolerance_and_void(): void
    {
        $paid = $this->sale(1000000);
        $unpaid = $this->sale(1000000, overrides: ['amount_received' => 400000]);
        $tiny = $this->sale(1000000, overrides: ['amount_received' => 999999.995]);
        $void = $this->sale(300000);
        DB::table('bookings')->where('id', $void->id)->update(['status' => 'cancelled']);

        $this->list()->filterTable('payment_status', 'lunas')->assertCanSeeTableRecords([$paid, $tiny])->assertCanNotSeeTableRecords([$unpaid, $void]);
        $this->list()->filterTable('payment_status', 'belum_lunas')->assertCanSeeTableRecords([$unpaid])->assertCanNotSeeTableRecords([$paid, $tiny, $void]);
        $this->list()->filterTable('payment_status', 'void')->assertCanSeeTableRecords([$void])->assertCanNotSeeTableRecords([$paid, $unpaid]);
    }

    public function test_quick_period_presets(): void
    {
        $today = $this->sale(1, '2026-10-08');
        $thisMonth = $this->sale(2, '2026-10-03');
        $lastMonth = $this->sale(3, '2026-09-20');
        $thisYear = $this->sale(4, '2026-03-15');
        $lastYear = $this->sale(5, '2025-12-01');

        $this->list()->filterTable('period_preset', 'today')->assertCanSeeTableRecords([$today])->assertCanNotSeeTableRecords([$thisMonth, $lastMonth, $thisYear, $lastYear]);
        $this->list()->filterTable('period_preset', 'this_month')->assertCanSeeTableRecords([$today, $thisMonth])->assertCanNotSeeTableRecords([$lastMonth, $thisYear]);
        $this->list()->filterTable('period_preset', 'last_month')->assertCanSeeTableRecords([$lastMonth])->assertCanNotSeeTableRecords([$today, $thisMonth]);
        $this->list()->filterTable('period_preset', 'this_quarter')->assertCanSeeTableRecords([$today, $thisMonth])->assertCanNotSeeTableRecords([$lastMonth]);
        $this->list()->filterTable('period_preset', 'ytd')->assertCanSeeTableRecords([$today, $thisMonth, $lastMonth, $thisYear])->assertCanNotSeeTableRecords([$lastYear]);
    }

    public function test_date_range_filter_is_inclusive_and_a_reversed_range_is_swapped(): void
    {
        $first = $this->sale(1, '2026-10-01');
        $mid = $this->sale(2, '2026-10-05');
        $last = $this->sale(3, '2026-10-10');
        $outside = $this->sale(4, '2026-09-30');

        $this->list()->filterTable('entry_date', ['from' => '2026-10-01', 'until' => '2026-10-05'])
            ->assertCanSeeTableRecords([$first, $mid])->assertCanNotSeeTableRecords([$last, $outside]);
        $this->list()->filterTable('entry_date', ['from' => '2026-10-10', 'until' => '2026-10-05'])
            ->assertCanSeeTableRecords([$mid, $last])->assertCanNotSeeTableRecords([$first, $outside]);
        $this->list()->filterTable('entry_date', ['from' => '2026-10-05'])->assertCanSeeTableRecords([$mid, $last])->assertCanNotSeeTableRecords([$first]);
    }

    public function test_store_filter_is_for_admins_only(): void
    {
        $a = $this->sale(1, '2026-10-03', $this->storeA);
        $b = $this->sale(2, '2026-10-04', $this->storeB);

        $this->list()->assertTableFilterExists('store_id')->filterTable('store_id', $this->storeB->id)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        $this->list($this->user('kasir', $this->storeA))->assertTableFilterHidden('store_id');
    }

    // ------------------------------------------------------------- kartu ringkasan

    public function test_summary_cards_aggregate_the_filtered_rows(): void
    {
        $this->sale(1000000);
        $this->sale(1000000, overrides: ['amount_received' => 400000]);
        $this->sale(1000000, overrides: ['amount_received' => 999999.995]);
        $this->sale(500000, overrides: ['amount_received' => null]);
        $void = $this->sale(300000);
        DB::table('bookings')->where('id', $void->id)->update(['status' => 'cancelled']);
        $this->sale(250000, '2026-09-10');

        $all = SalesDetailStatsWidget::aggregate(SalesResource::getEloquentQuery());
        $this->assertSame(6, $all['total_count']);
        $this->assertEquals(4050000.0, $all['total_revenue']);
        $this->assertSame(1, $all['void_count']);
        $this->assertEquals(300000.0, $all['void_amount']);
        $this->assertSame(1, $all['belum_lunas_count']);
        $this->assertEquals(600000.0, $all['belum_lunas_amount']);
        $this->assertSame(4, $all['lunas_count']);
        $this->assertEquals(2750000.0, $all['lunas_amount']);

        $page = $this->list()->filterTable('period_preset', 'this_month');
        $filtered = SalesDetailStatsWidget::aggregate($page->instance()->getFilteredTableQuery());
        $this->assertSame(5, $filtered['total_count'], 'Kartu mengikuti filter, bukan total seluruh data.');
        $this->assertEquals(3800000.0, $filtered['total_revenue']);
        $page->assertSee('Total Invoice')->assertSee('Rp3.800.000')->assertSee('Belum Lunas')->assertSee('Rp600.000');
    }

    // ------------------------------------------------------------- aksi

    public function test_row_actions_view_booking_and_print_invoice_rules(): void
    {
        $completed = $this->sale(100000);
        $zero = $this->sale(50000);
        DB::table('bookings')->where('id', $zero->id)->update(['transaction_amount' => 0]);
        $void = $this->sale(70000);
        DB::table('bookings')->where('id', $void->id)->update(['status' => 'cancelled']);

        $this->list()
            ->assertTableActionHasUrl('viewBooking', BookingResource::getUrl('view', ['record' => $completed]), $completed)
            ->assertTableActionVisible('printInvoice', $completed)
            ->assertTableActionHidden('printInvoice', Booking::find($zero->id))
            ->assertTableActionHidden('printInvoice', Booking::find($void->id))
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_print_invoice_downloads_a_pdf(): void
    {
        $booking = $this->sale(100000, overrides: ['amount_received' => 40000]);

        $this->list()->callTableAction('printInvoice', $booking)->assertFileDownloaded('Invoice-' . $booking->booking_number . '.pdf');
    }

    // ------------------------------------------------------------- ekspor

    public function test_excel_export_follows_the_active_filter_and_is_logged(): void
    {
        $a = $this->sale(100000, '2026-10-03', $this->storeA);
        $this->sale(200000, '2026-10-04', $this->storeB);
        $admin = $this->admin();
        Excel::fake();

        $this->list($admin)->filterTable('store_id', $this->storeA->id)->callTableAction('exportExcel');

        Excel::assertDownloaded('penjualan-20261008-100000.xlsx', fn (SalesExport $export) => $export->query()->pluck('bookings.id')->all() === [$a->id]);
        $log = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->firstOrFail();
        $this->assertSame(['report' => 'sales_detail', 'format' => 'xlsx'], $log->properties->toArray());
    }

    public function test_excel_rows_use_full_product_labels_the_customer_display_name_and_derived_status(): void
    {
        $booking = $this->sale(1000000, overrides: ['amount_received' => 400000, 'product_detailing' => true, 'spend_promo_discount' => 50000], customerName: 'Budi Aplikasi');
        $export = new SalesExport(SalesResource::getEloquentQuery());
        $row = $export->query()->first();

        $mapped = $export->map($row);

        $this->assertSame('INV/' . $booking->booking_number, $mapped[0]);
        $this->assertSame('Budi Aplikasi', $mapped[2]);
        $this->assertSame('Toko A', $mapped[3]);
        $this->assertSame('PPF + Detailing', $mapped[4]);
        $this->assertSame([1000000.0, 50000.0, 400000.0, 600000.0, 'Belum Lunas'], array_slice($mapped, 5, 5));
        $this->assertSame('2026-10-03', $mapped[11]);
        $this->assertSame($booking->journalEntry->entry_number, $mapped[12]);
        $this->assertSame(['No. Invoice', 'No. Booking', 'Pelanggan', 'Toko', 'Produk', 'Nilai Transaksi', 'Potongan Promo', 'Diterima', 'Sisa Tagihan', 'Status', 'Waktu Order', 'Waktu Bayar', 'No. Jurnal'], $export->headings());
    }

    public function test_pdf_export_downloads_logs_and_renders_empty_results(): void
    {
        $this->sale(100000, overrides: ['product_detailing' => true]);
        $admin = $this->admin();

        $page = $this->list($admin);
        $page->callTableAction('exportPdf')->assertHasNoTableActionErrors();
        $page->filterTable('period_preset', 'last_month')->callTableAction('exportPdf')->assertHasNoTableActionErrors();

        $formats = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get()->map(fn ($l) => $l->properties['format'])->all();
        $this->assertSame(['pdf', 'pdf'], $formats);
    }

    // ------------------------------------------------------------- akses

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->assertTrue((bool) SalesResource::canViewAny());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['SalesResource']]), 'web');
        $this->assertTrue((bool) SalesResource::canViewAny());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse((bool) SalesResource::canViewAny());
    }
}
