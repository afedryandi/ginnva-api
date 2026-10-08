<?php

namespace Tests\Feature;

use App\Exports\TechnicianCommissionReportExport;
use App\Filament\Pages\FixedCommissionReport;
use App\Filament\Pages\TechnicianCommissionReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Komisi Teknisi & Komisi Tetap (satu logika, dua halaman): komisi per teknisi dari booking berbayar yang
 * dikerjakannya (tarif flat per pekerjaan atau tarif per layanan, booking tim dihitung penuh per teknisi), teknisi
 * tanpa tarif ditandai "Belum diatur" (bukan Rp0) sedangkan teknisi bertarif tanpa pekerjaan = Rp0; hanya untuk
 * full-access; filter toko; sanitasi URL; Excel/PDF (judul & nama file mengikuti halaman) + log.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class TechnicianCommissionReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private Technician $rudi;
    private Technician $sari;
    private Technician $tono;
    private Technician $dewi;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('direksi', 'web');
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

    public static function pages(): array
    {
        return [
            'komisi teknisi' => [TechnicianCommissionReport::class, 'Laporan Komisi Teknisi', 'laporan-komisi-teknisi'],
            'komisi tetap' => [FixedCommissionReport::class, 'Komisi Tetap', 'komisi-tetap'],
        ];
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function technician(string $name, Store $store, ?float $flat, bool $withAccount = true): Technician
    {
        $user = $withAccount ? $this->user('kasir', $store, ['name' => $name]) : null;

        return Technician::create(['store_id' => $store->id, 'user_id' => $user?->id, 'name' => $name, 'commission_amount' => $flat, 'status' => 'active']);
    }

    /** @param list<Technician> $team */
    private function job(float $amount, string $date, Store $store, array $team, array $flags = ['product_ppf' => true]): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'preferred_date' => $date, 'status' => 'completed', 'transaction_amount' => $amount, 'amount_received' => $amount,
        ], $flags));
        $booking->installers()->attach(collect($team)->pluck('user_id')->filter()->all());
        app(BookingPostingService::class)->sync($booking);
        $booking->refresh();
        DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date]);

        return $booking->fresh();
    }

    /**
     * Rudi (A, flat 100.000): PPF 1.000.000 (3 Okt) + kaca film 500.000 (5 Okt, tim dengan Sari); September 400.000 tidak ikut.
     * Sari (B, tarif per layanan PPF 150.000 + Detailing 50.000): kaca film 500.000 (tarif belum ada) + PPF+Detailing 2.000.000.
     * Tono (A, tarif belum diatur): PPF 300.000. Dewi (B, flat 80.000): tanpa pekerjaan. Tanpa Akun: tidak muncul.
     */
    private function october(): void
    {
        $this->rudi = $this->technician('Rudi', $this->storeA, 100000);
        $this->sari = $this->technician('Sari', $this->storeB, null);
        $this->sari->serviceRates()->create(['service_type' => 'ppf', 'commission_amount' => 150000]);
        $this->sari->serviceRates()->create(['service_type' => 'detailing', 'commission_amount' => 50000]);
        $this->tono = $this->technician('Tono', $this->storeA, null);
        $this->dewi = $this->technician('Dewi', $this->storeB, 80000);
        $this->technician('Tanpa Akun', $this->storeA, 100000, false);

        $this->job(1000000, '2026-10-03', $this->storeA, [$this->rudi]);
        $this->job(500000, '2026-10-05', $this->storeA, [$this->rudi, $this->sari], ['product_kaca_film' => true]);
        $this->job(2000000, '2026-10-06', $this->storeB, [$this->sari], ['product_ppf' => true, 'product_detailing' => true]);
        $this->job(300000, '2026-10-07', $this->storeA, [$this->tono]);
        $this->job(400000, '2026-09-20', $this->storeA, [$this->rudi]);
    }

    private function page(string $class, array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test($class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null, string $class = TechnicianCommissionReport::class): array
    {
        return $this->page($class, $data, $as)->instance()->getResult();
    }

    private function rows(array $result)
    {
        return collect($result['rows'])->keyBy(fn ($r) => $r['technician']->name);
    }

    // ------------------------------------------------------------- perhitungan

    public function test_flat_per_service_and_unrated_technicians_are_computed_separately(): void
    {
        $this->october();

        $rows = $this->rows($this->report());

        $this->assertEqualsCanonicalizing(['Rudi', 'Sari', 'Tono', 'Dewi'], $rows->keys()->all(), '"Tanpa Akun" tidak muncul.');

        $rudi = $rows['Rudi'];
        $this->assertSame(2, $rudi['jobCount']);
        $this->assertEqualsWithDelta(1500000.0, $rudi['salesTotal'], 0.001);
        $this->assertEqualsWithDelta(200000.0, $rudi['totalCommission'], 0.001);
        $this->assertEqualsWithDelta(100000.0, $rudi['rate'], 0.001);
        $this->assertFalse($rudi['usesServiceRates']);
        $this->assertFalse($rudi['hasUnratedJob']);
    }

    public function test_a_team_booking_pays_each_technician_in_full_and_per_service_rates_add_up(): void
    {
        $this->october();

        $sari = $this->rows($this->report())['Sari'];

        $this->assertSame(2, $sari['jobCount'], 'Booking tim (kaca film) + PPF/Detailing.');
        $this->assertEqualsWithDelta(2500000.0, $sari['salesTotal'], 0.001, 'Penjualan booking tim dihitung penuh untuk tiap teknisi.');
        $this->assertTrue($sari['usesServiceRates']);
        $this->assertNull($sari['rate']);
        $this->assertEqualsWithDelta(200000.0, $sari['totalCommission'], 0.001, '150.000 + 50.000; kaca film tidak punya tarif jadi belum disumkan.');
        $this->assertTrue($sari['hasUnratedJob']);
    }

    public function test_technician_without_a_rate_is_not_reported_as_zero_but_a_rated_idle_one_is(): void
    {
        $this->october();

        $rows = $this->rows($this->report());

        $this->assertSame(1, $rows['Tono']['jobCount']);
        $this->assertNull($rows['Tono']['totalCommission'], 'Belum diatur, bukan Rp0.');
        $this->assertTrue($rows['Tono']['hasUnratedJob']);
        $this->assertSame(0, $rows['Dewi']['jobCount']);
        $this->assertSame(0.0, $rows['Dewi']['totalCommission'], 'Bertarif tapi belum ada pekerjaan = Rp0, bukan "Belum diatur".');
    }

    public function test_totals_unrated_count_and_ordering(): void
    {
        $this->october();

        $result = $this->report();
        $names = collect($result['rows'])->map(fn ($r) => $r['technician']->name)->all();

        $this->assertEqualsWithDelta(400000.0, $result['totalCommission'], 0.001, 'Rudi 200.000 + Sari 200.000 + Dewi 0.');
        $this->assertSame(2, $result['unratedCount'], 'Sari (sebagian) dan Tono (seluruhnya); Dewi tanpa pekerjaan tidak dihitung.');
        $this->assertEqualsCanonicalizing(['Rudi', 'Sari'], array_slice($names, 0, 2));
        $this->assertSame(['Dewi', 'Tono'], array_slice($names, 2), 'Rp0 sebelum "Belum diatur".');
    }

    public function test_unpaid_bookings_and_technicians_without_an_account_are_ignored(): void
    {
        $this->october();
        Booking::create([
            'booking_number' => 'BKG-UNPAID', 'customer_id' => Customer::create(['name' => 'X', 'phone_number' => '081211112222'])->id, 'store_id' => $this->storeA->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-04', 'status' => 'pending', 'transaction_amount' => 900000,
        ])->installers()->attach($this->rudi->user_id);

        $rows = $this->rows($this->report());

        $this->assertSame(2, $rows['Rudi']['jobCount'], 'Booking tanpa jurnal pendapatan tidak menghasilkan komisi.');
        $this->assertFalse($rows->has('Tanpa Akun'));
    }

    // ------------------------------------------------------------- toko & akses

    public function test_store_filter_narrows_by_the_technician_store(): void
    {
        $this->october();

        $b = $this->report(['store_id' => $this->storeB->id]);

        $this->assertEqualsCanonicalizing(['Sari', 'Dewi'], collect($b['rows'])->map(fn ($r) => $r['technician']->name)->all());
        $this->assertEqualsWithDelta(200000.0, $b['totalCommission'], 0.001);
    }

    #[DataProvider('pages')]
    public function test_only_full_access_accounts_can_open_the_report(string $class): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue($class::canAccess());

        $this->actingAs($this->user('direksi'), 'web');
        $this->assertTrue($class::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => [class_basename($class)]]), 'web');
        $this->assertFalse($class::canAccess(), 'Komisi = data kompensasi: kotak menu saja tidak cukup.');
    }

    public function test_only_komisi_tetap_registers_its_own_menu_entry(): void
    {
        $this->assertFalse(TechnicianCommissionReport::shouldRegisterNavigation());
        $this->assertTrue(FixedCommissionReport::shouldRegisterNavigation());
        $this->assertSame('Komisi Tetap', FixedCommissionReport::getNavigationLabel());
        $this->assertSame('Laporan Komisi Teknisi', TechnicianCommissionReport::getNavigationLabel());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(TechnicianCommissionReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeId'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(TechnicianCommissionReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $page = $this->page(TechnicianCommissionReport::class);
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    // ------------------------------------------------------------- tampilan & ekspor

    #[DataProvider('pages')]
    public function test_page_shows_cards_scheme_labels_and_the_unrated_warning(string $class, string $title): void
    {
        $this->october();

        $page = $this->page($class);
        $page->assertSuccessful()
            ->assertSee('Total Komisi Periode Ini')
            ->assertSee('Rp400.000', false)
            ->assertSee('Rudi')
            ->assertSee('Flat Rp100.000/job', false)
            ->assertSee('Per Layanan')
            ->assertSee('Belum diatur')
            ->assertSee('Rp1.500.000', false)
            ->assertSee('Ada 2 teknisi yang punya pekerjaan di periode ini');

        $this->assertStringContainsString((string) $this->rudi->id, $page->instance()->technicianUrl($this->rudi->id));
        $this->assertSame($title, $class::getNavigationLabel());
    }

    public function test_page_shows_the_empty_state(): void
    {
        $this->page(TechnicianCommissionReport::class)->assertSee('Belum ada data teknisi bertaut akun installer.');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $this->october();

        $export = new TechnicianCommissionReportExport($this->report(), 'Laporan Komisi Teknisi');
        $rows = collect($export->array())->keyBy(0);

        $this->assertSame(['Teknisi', 'Toko', 'Jumlah Pekerjaan', 'Penjualan', 'Skema Tarif', 'Total Komisi'], $export->headings());
        $this->assertCount(4, $rows);
        $this->assertSame(['Rudi', 'Toko A', 2, 1500000.0, 'Flat Rp100.000/job', 200000.0], $rows['Rudi']);
        $this->assertSame(['Sari', 'Toko B', 2, 2500000.0, 'Per Layanan *', 200000.0], $rows['Sari']);
        $this->assertSame(['Tono', 'Toko A', 1, 300000.0, 'Belum diatur *', 'Belum diatur'], $rows['Tono']);
        $this->assertSame(['Dewi', 'Toko B', 0, 0.0, 'Flat Rp80.000/job', 0.0], $rows['Dewi']);
        $this->assertSame('#,##0;(#,##0);"-"', $export->columnFormats()['F']);
    }

    #[DataProvider('pages')]
    public function test_exports_download_with_the_page_name_and_are_logged(string $class, string $title, string $slug): void
    {
        $this->october();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page($class, [], $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded($slug . '-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$slug, $slug], $logs->map(fn ($l) => $l->properties['report'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(TechnicianCommissionReport::class)->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page(FixedCommissionReport::class)->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
