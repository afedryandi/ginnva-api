<?php

namespace Tests\Feature;

use App\Exports\CustomerReportExport;
use App\Filament\Pages\CustomerReport;
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
 * Laporan Pelanggan: pelanggan baru (menurut tanggal daftar), pelanggan repeat (>1 booking berbayar sepanjang waktu,
 * company-wide), dan Top 20 pelanggan menurut belanja periode (booking berbayar = ada jurnal pendapatan, nominal > 0)
 * lengkap dengan total sepanjang waktu, kunjungan terakhir, rata-rata per bulan; cakupan toko; sanitasi URL; Excel/PDF
 * + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class CustomerReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private Customer $siti;
    private Customer $andi;

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

    private function customer(string $name, string $registeredAt, ?string $email = null): Customer
    {
        $customer = Customer::create(['name' => $name, 'phone_number' => '0812' . random_int(10000000, 99999999), 'email' => $email]);
        DB::table('customers')->where('id', $customer->id)->update(['created_at' => $registeredAt]);

        return $customer->fresh();
    }

    /** Booking berbayar: nominal terposting ke jurnal pendapatan bertanggal $date. */
    private function sale(Customer $customer, float $amount, string $date, Store $store): Booking
    {
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => $date, 'status' => 'completed',
            'transaction_amount' => $amount, 'amount_received' => $amount,
        ]);
        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    /**
     * Siti (daftar Apr): A 1.000.000 (3 Okt), A 500.000 (15 Sep), B 300.000 (5 Okt). Andi (daftar 2 Okt): A 2.000.000
     * (4 Okt) + satu booking belum dibayar (tanpa jurnal). Rina: hanya September. Dewi: dua booking Agustus (repeat).
     */
    private function october(): void
    {
        $this->siti = $this->customer('Siti', '2026-04-08 10:00:00', 'siti@test.local');
        $this->andi = $this->customer('Andi', '2026-10-02 09:00:00');
        $rina = $this->customer('Rina', '2026-06-01 09:00:00');
        $dewi = $this->customer('Dewi', '2026-05-01 09:00:00');

        $this->sale($this->siti, 1000000, '2026-10-03', $this->storeA);
        $this->sale($this->siti, 500000, '2026-09-15', $this->storeA);
        $this->sale($this->siti, 300000, '2026-10-05', $this->storeB);
        $this->sale($this->andi, 2000000, '2026-10-04', $this->storeA);
        Booking::create([
            'booking_number' => 'BKG-UNPAID', 'customer_id' => $this->andi->id, 'store_id' => $this->storeA->id, 'service_type' => 'PPF',
            'product_ppf' => true, 'preferred_date' => '2026-10-06', 'status' => 'pending', 'transaction_amount' => 750000,
        ]);
        $this->sale($rina, 800000, '2026-09-20', $this->storeA);
        $this->sale($dewi, 100000, '2026-08-03', $this->storeA);
        $this->sale($dewi, 100000, '2026-08-17', $this->storeB);
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(CustomerReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    private function top(array $result)
    {
        return collect($result['topCustomers'])->keyBy('name');
    }

    // ------------------------------------------------------------- ringkasan & top pelanggan

    public function test_new_and_repeat_customers(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertSame(1, $result['newCustomers'], 'Hanya Andi yang mendaftar di Oktober.');
        $this->assertSame(2, $result['repeatCount'], 'Siti (3 booking) dan Dewi (2); Andi hanya 1 yang berbayar.');
    }

    public function test_the_whole_first_day_counts_for_new_customers_when_the_start_date_has_a_time(): void
    {
        $this->customer('Pagi', '2026-10-01 08:00:00');
        $this->customer('Sebelum', '2026-09-30 23:59:00');

        $this->assertSame(1, $this->report(['from' => '2026-10-01 10:00:00', 'to' => '2026-10-31'])['newCustomers']);
    }

    public function test_top_customers_are_ranked_by_period_spend_with_all_time_totals(): void
    {
        $this->october();

        $result = $this->report();
        $top = $this->top($result);

        $this->assertSame(['Andi', 'Siti'], $result['topCustomers']->pluck('name')->all(), 'Rina (hanya September) dan Dewi (Agustus) tidak punya belanja di periode.');
        $this->assertSame([1, 2000000.0, 1, 2000000.0], [(int) $top['Andi']->bookings_in_period, (float) $top['Andi']->spend_in_period, (int) $top['Andi']->bookings_all_time, (float) $top['Andi']->spend_all_time], 'Booking belum dibayar tidak dihitung.');
        $this->assertSame([2, 1300000.0, 3, 1800000.0], [(int) $top['Siti']->bookings_in_period, (float) $top['Siti']->spend_in_period, (int) $top['Siti']->bookings_all_time, (float) $top['Siti']->spend_all_time]);
        $this->assertSame('2026-10-05', Carbon::parse($top['Siti']->last_visit)->toDateString());
    }

    public function test_monthly_averages_use_the_account_age_with_a_minimum_of_one_month(): void
    {
        $this->october();

        $top = $this->top($this->report());

        // Siti terdaftar tepat 6 bulan lalu: 3 booking / 6 bulan, Rp1.800.000 / 6 bulan.
        $this->assertEqualsWithDelta(0.5, $top['Siti']->avg_bookings_per_month, 0.0001);
        $this->assertEqualsWithDelta(300000.0, $top['Siti']->avg_spend_per_month, 0.5);
        // Andi baru 6 hari: pembagi minimal 1 bulan.
        $this->assertEqualsWithDelta(1.0, $top['Andi']->avg_bookings_per_month, 0.0001);
        $this->assertEqualsWithDelta(2000000.0, $top['Andi']->avg_spend_per_month, 0.5);
    }

    public function test_only_the_top_twenty_customers_are_listed(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->sale($this->customer("Pelanggan {$i}", '2026-01-01 09:00:00'), 100000 + $i * 1000, '2026-10-03', $this->storeA);
        }

        $result = $this->report();

        $this->assertCount(20, $result['topCustomers']);
        $this->assertSame('Pelanggan 22', $result['topCustomers']->first()->name);
        $this->assertFalse($result['topCustomers']->pluck('name')->contains('Pelanggan 1'));
    }

    // ------------------------------------------------------------- toko & akses

    public function test_admin_store_filter_narrows_the_table_but_not_new_or_repeat_counts(): void
    {
        $this->october();

        $b = $this->report(['store_id' => $this->storeB->id]);
        $top = $this->top($b);

        $this->assertSame(['Siti'], $b['topCustomers']->pluck('name')->all());
        $this->assertSame([1, 300000.0, 1, 300000.0], [(int) $top['Siti']->bookings_in_period, (float) $top['Siti']->spend_in_period, (int) $top['Siti']->bookings_all_time, (float) $top['Siti']->spend_all_time], 'Hanya booking di toko B.');
        $this->assertSame(1, $b['newCustomers']);
        $this->assertSame(2, $b['repeatCount']);
    }

    public function test_staff_see_their_store_in_the_table_while_new_and_repeat_stay_company_wide(): void
    {
        $this->october();

        $staff = $this->user('kasir', $this->storeA);
        $result = $this->report(['store_id' => $this->storeB->id], $staff);
        $top = $this->top($result);

        $this->assertSame(['Andi', 'Siti'], $result['topCustomers']->pluck('name')->all());
        $this->assertSame([1, 1000000.0, 2, 1500000.0], [(int) $top['Siti']->bookings_in_period, (float) $top['Siti']->spend_in_period, (int) $top['Siti']->bookings_all_time, (float) $top['Siti']->spend_all_time], 'Booking Siti di toko B tidak ikut untuk staf toko A.');
        $this->assertSame(2, $result['repeatCount'], 'Repeat = headcount lintas-cabang, tidak ikut terpotong toko staf.');
        $this->assertSame(1, $result['newCustomers']);
    }

    public function test_an_account_without_a_store_sees_no_ranking(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertCount(0, $result['topCustomers']);
        $this->assertSame(2, $result['repeatCount']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(CustomerReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['CustomerReport']]), 'web');
        $this->assertTrue(CustomerReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(CustomerReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(CustomerReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(CustomerReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(CustomerReport::class)->get('storeId'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    // ------------------------------------------------------------- tampilan & ekspor

    public function test_page_shows_the_cards_the_ranking_the_link_and_the_empty_state(): void
    {
        $this->october();

        $page = $this->page();
        $page->assertSuccessful()
            ->assertSee('Pelanggan Baru Daftar')
            ->assertSee('Pelanggan Repeat')
            ->assertSee('Andi')
            ->assertSee('Siti')
            ->assertSee($this->siti->phone_number)
            ->assertSee('Rp2.000.000', false)
            ->assertSee('Rp1.300.000', false)
            ->assertSee('Rp300.000', false)
            ->assertSee('05 Oct 2026');

        $this->assertStringContainsString((string) $this->siti->id, $page->instance()->customerUrl($this->siti->id));

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertSee('Belum ada pelanggan dengan booking berbayar pada rentang ini.');
    }

    public function test_excel_rows_are_aligned_with_headings_and_amounts_are_numbers(): void
    {
        $this->october();

        $export = new CustomerReportExport($this->report());
        $rows = $export->array();

        $this->assertCount(10, $export->headings());
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertCount(10, $row);
        }
        $this->assertSame('Andi', $rows[0][0]);
        $this->assertSame(['Siti', $this->siti->phone_number, '2026-04-08', 2, 1300000.0, 3, 1800000.0, '2026-10-05'], array_slice($rows[1], 0, 8));
        $this->assertSame(0.5, (float) $rows[1][8]);
        $this->assertIsFloat($rows[1][9]);
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-pelanggan-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
        $this->assertSame('customer', $logs->first()->properties['report']);
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
