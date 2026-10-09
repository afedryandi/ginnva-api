<?php

namespace Tests\Feature;

use App\Exports\TechnicianUtilizationExport;
use App\Filament\Pages\TechnicianUtilizationReport;
use App\Models\Attendance;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman Laporan Utilisasi Teknisi: akses menu, rentang tanggal (default
 * bulan ini, nilai ngawur jatuh ke default), pembatasan cabang per peran,
 * urutan, tampilan, serta isi ekspor Excel dan unduhan PDF.
 */
class TechnicianUtilizationReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['kasir', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $hours = [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']];
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'opening_hours' => $hours]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true, 'opening_hours' => $hours]);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    /** Teknisi dengan akun, jam hadir 9 jam (7 Sep) dan job selesai sejumlah $jobs hari. */
    private function technician(string $name, ?Store $store = null, int $jobDays = 0, bool $present = true): Technician
    {
        $store ??= $this->store;
        $user = $this->user('kasir', $store, ['name' => $name]);
        $technician = Technician::create(['store_id' => $store->id, 'user_id' => $user->id, 'name' => $name, 'level' => 'intermediate', 'status' => 'active']);

        if ($present) {
            Attendance::create(['user_id' => $user->id, 'store_id' => $store->id, 'date' => '2026-09-07', 'entry_type' => 'clock', 'clock_in_at' => '2026-09-07 09:00:00', 'clock_out_at' => '2026-09-07 18:00:00']);
        }

        if ($jobDays > 0) {
            $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
            $booking = Booking::create([
                'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $store->id, 'service_type' => 'Pelindung Cat (PPF)',
                'product_ppf' => true, 'preferred_date' => '2026-09-07', 'duration_days' => $jobDays, 'status' => 'completed',
            ]);
            $booking->installers()->attach($user->id);
        }

        return $technician;
    }

    private function page(User $viewer)
    {
        $this->actingAs($viewer, 'web');

        return Livewire::test(TechnicianUtilizationReport::class)->set('from', '2026-09-07')->set('to', '2026-09-13');
    }

    private function names($component): array
    {
        return $component->instance()->getRows()->pluck('name')->all();
    }

    // ------------------------------------------------------------- akses & default

    public function test_access_follows_menu_access(): void
    {
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(TechnicianUtilizationReport::canAccess());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(TechnicianUtilizationReport::canAccess());
    }

    public function test_default_range_is_the_current_month_and_garbage_dates_fall_back_to_it(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $default = Livewire::test(TechnicianUtilizationReport::class)->instance();
        $this->assertSame(now()->startOfMonth()->toDateString(), $default->from);
        $this->assertSame(now()->endOfMonth()->toDateString(), $default->to);

        $garbage = Livewire::test(TechnicianUtilizationReport::class, ['from' => 'bukan-tanggal', 'to' => '???'])->instance();
        $this->assertSame(now()->startOfMonth()->toDateString(), $garbage->from);
        $this->assertSame(now()->endOfMonth()->toDateString(), $garbage->to);
    }

    // ------------------------------------------------------------- cabang

    public function test_full_access_sees_every_store_and_can_narrow_to_one(): void
    {
        $mine = $this->technician('Andi');
        $theirs = $this->technician('Budi', $this->otherStore);

        $page = $this->page($this->user('super_admin'));
        $this->assertEqualsCanonicalizing(['Andi', 'Budi'], $this->names($page));

        $page->set('storeId', $this->otherStore->id);
        $this->assertSame(['Budi'], $this->names($page));
        $this->assertNotNull($mine);
        $this->assertNotNull($theirs);
    }

    public function test_a_store_manager_only_ever_sees_their_own_store_even_with_a_tampered_filter(): void
    {
        $this->technician('Andi');
        $this->technician('Budi', $this->otherStore);

        $page = $this->page($this->user('store_manager'));
        $this->assertSame(['Andi'], $this->names($page));

        $page->set('storeId', $this->otherStore->id);
        $this->assertSame(['Andi'], $this->names($page), 'Filter cabang tidak berlaku untuk non full-access.');
    }

    // ------------------------------------------------------------- urutan & tampilan

    public function test_sorting_by_utilization_puts_technicians_without_a_percentage_last(): void
    {
        $this->technician('Charlie', null, 1);              // 9/9 = 100%
        $this->technician('Alpha', null, 0);                // 0%
        $this->technician('Bravo', null, 0, false);         // tanpa jam hadir -> tidak ada persen
        $page = $this->page($this->user('super_admin'));

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names($page), 'Default: nama A-Z.');

        $page->set('sort', 'utilization_desc');
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $this->names($page));

        $page->set('sort', 'utilization_asc');
        $this->assertSame(['Alpha', 'Charlie', 'Bravo'], $this->names($page));
    }

    public function test_the_page_renders_rows_percentages_and_the_account_placeholder(): void
    {
        $this->technician('Andi', null, 1);
        $noAccount = Technician::create(['store_id' => $this->store->id, 'name' => 'Tanpa Akun', 'level' => 'intermediate', 'status' => 'active']);
        $this->page($this->user('super_admin'))
            ->assertSuccessful()
            ->assertSee('Andi')
            ->assertSee('100,0%')
            ->assertSee('Tanpa Akun')
            ->assertSee('Belum ada akun');
        $this->assertNotNull($noAccount);
    }

    public function test_the_empty_state_is_shown_when_no_technician_matches(): void
    {
        $this->page($this->user('super_admin'))->assertSee('Belum ada teknisi aktif untuk cabang ini.');
    }

    // ------------------------------------------------------------- ekspor

    public function test_excel_export_mirrors_the_screen_including_the_account_placeholder(): void
    {
        $this->technician('Andi', null, 1);
        Technician::create(['store_id' => $this->store->id, 'name' => 'Tanpa Akun', 'level' => 'intermediate', 'status' => 'active']);
        $rows = $this->page($this->user('super_admin'))->instance()->getRows();

        $export = new TechnicianUtilizationExport(['from' => Carbon::parse('2026-09-07'), 'to' => Carbon::parse('2026-09-13'), 'rows' => $rows]);

        $this->assertSame(['Teknisi', 'Cabang', 'Jam Hadir', 'Jam Job (Estimasi)', 'Jam Idle', 'Utilisasi (%)'], $export->headings());
        $data = collect($export->array())->keyBy(0);
        $this->assertEquals(['Andi', 'Toko A', 9.0, 9.0, 0.0, 100.0], $data['Andi']);
        $this->assertSame('Belum ada akun', $data['Tanpa Akun'][2]);
        $this->assertSame('-', $data['Tanpa Akun'][4]);
        $this->assertSame('-', $data['Tanpa Akun'][5]);
    }

    public function test_pdf_export_downloads_a_file(): void
    {
        $this->technician('Andi', null, 1);

        $this->page($this->user('super_admin'))
            ->callAction('exportPdf')
            ->assertFileDownloaded();
    }

    // ------------------------------------------------------------- perbaikan audit

    public function test_staff_without_a_store_sees_no_technicians(): void
    {
        $this->technician('Andi');
        $viewer = $this->user('store_manager');
        $viewer->forceFill(['store_id' => null])->save();

        $this->assertSame([], $this->names($this->page($viewer->fresh())));
    }

    public function test_to_before_from_is_corrected_with_a_warning(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $page = Livewire::test(TechnicianUtilizationReport::class, ['from' => '2026-09-20', 'to' => '2026-09-01']);
        $this->assertSame('2026-09-20', $page->instance()->to);

        $page = Livewire::test(TechnicianUtilizationReport::class)->set('from', '2026-09-07')->set('to', '2026-09-13');
        $page->set('data.to', '2026-09-01')
            ->assertNotified('Tanggal "Sampai" tidak boleh sebelum "Dari"');
        $this->assertSame('2026-09-07', $page->instance()->to);
    }

    public function test_exports_are_logged(): void
    {
        $this->technician('Andi', null, 1);
        \Maatwebsite\Excel\Facades\Excel::fake();

        $this->page($this->user('super_admin'))->callAction('exportExcel');

        $this->assertDatabaseHas('activity_log', ['log_name' => 'report_export', 'description' => 'Ekspor Utilisasi Teknisi (xlsx)']);
    }
}
