<?php

namespace Tests\Feature;

use App\Exports\EmployeeReportExport;
use App\Filament\Pages\EmployeeReport;
use App\Models\Payroll;
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
 * Laporan Karyawan: rekap Penggajian satu bulan (hari kerja, telat, alpha, gaji bersih, status) langsung dari baris
 * Payroll, hanya untuk full-access (gaji = data paling sensitif); filter toko; bulan dari URL divalidasi (12 bulan
 * terakhir); status lengkap (Draft / Menunggu Persetujuan Direksi / Sudah Dibayar); Excel/PDF + log.
 * "Hari ini" dibekukan di 8 Oktober 2026 (bulan default = September 2026).
 */
class EmployeeReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
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

    private function payroll(string $name, Store $store, string $month, float $net, int $late, int $alpha, string $status = 'draft', int $workDays = 26): Payroll
    {
        $employee = $this->user('kasir', $store, ['name' => $name]);

        return Payroll::create([
            'user_id' => $employee->id, 'store_id' => $store->id, 'period_month' => $month, 'base_salary' => $net, 'working_days_in_month' => $workDays,
            'prorated_base_salary' => $net, 'total_late_minutes' => $late, 'late_violation_days' => 0, 'alpha_days' => $alpha, 'alpha_deduction' => 0,
            'total_commission' => 0, 'deduction_per_violation' => 0, 'total_deduction' => 0, 'net_pay' => $net, 'status' => $status,
        ]);
    }

    /** September: Andi (A, paid), Budi (B, menunggu persetujuan), Citra (A, draft). Oktober: Andi lagi (bukan bulan default). */
    private function september(): void
    {
        $this->payroll('Andi Teknisi', $this->storeA, '2026-09-01', 5000000, 30, 1, 'paid');
        $this->payroll('Budi Kasir', $this->storeB, '2026-09-01', 3000000, 0, 0, 'pending_approval', 24);
        $this->payroll('Citra Admin', $this->storeA, '2026-09-01', 4000000, 120, 2, 'draft');
        $this->payroll('Andi Oktober', $this->storeA, '2026-10-01', 9000000, 999, 9, 'draft');
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(EmployeeReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    public function test_the_default_month_is_last_month_and_rows_are_ordered_by_net_pay(): void
    {
        $this->september();

        $result = $this->report();

        $this->assertSame('2026-09-01', $result['month']->toDateString());
        $this->assertSame(['Andi Teknisi', 'Citra Admin', 'Budi Kasir'], $result['payrolls']->map(fn ($p) => $p->user->name)->all());
        $this->assertEqualsWithDelta(12000000.0, (float) $result['totalNetPay'], 0.001);
        $this->assertSame(3, (int) $result['totalAlphaDays']);
        $this->assertSame(150, (int) $result['totalLateMinutes']);
    }

    public function test_another_month_and_the_store_filter(): void
    {
        $this->september();

        $october = $this->report(['month' => '2026-10-01']);
        $this->assertSame(['Andi Oktober'], $october['payrolls']->map(fn ($p) => $p->user->name)->all());

        $storeB = $this->report(['store_id' => $this->storeB->id]);
        $this->assertSame(['Budi Kasir'], $storeB['payrolls']->map(fn ($p) => $p->user->name)->all());
        $this->assertEqualsWithDelta(3000000.0, (float) $storeB['totalNetPay'], 0.001);

        $empty = $this->report(['month' => '2026-07-01']);
        $this->assertCount(0, $empty['payrolls']);
        $this->assertEquals(0, $empty['totalNetPay']);
    }

    public function test_only_full_access_accounts_can_open_the_report(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(EmployeeReport::canAccess());

        $this->actingAs($this->user('direksi'), 'web');
        $this->assertTrue(EmployeeReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['EmployeeReport']]), 'web');
        $this->assertFalse(EmployeeReport::canAccess(), 'Gaji sensitif: kotak menu saja tidak cukup.');
    }

    public function test_the_month_from_the_url_is_validated(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $ok = Livewire::withQueryParams(['bulan' => '2026-08-01'])->test(EmployeeReport::class);
        $this->assertSame('2026-08-01', $ok->get('month'));

        foreach (['bukan-tanggal', '2026-08-15', '2025-01-01', '2027-01-01'] as $bad) {
            $this->assertSame('2026-09-01', Livewire::withQueryParams(['bulan' => $bad])->test(EmployeeReport::class)->get('month'), $bad);
        }

        $this->assertSame($this->storeB->id, Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(EmployeeReport::class)->get('storeId'));
    }

    public function test_page_shows_totals_all_three_statuses_the_link_and_the_empty_state(): void
    {
        $this->september();

        $page = $this->page();
        $page->assertSuccessful()
            ->assertSee('Total Gaji Bersih')
            ->assertSee('Rp12.000.000', false)
            ->assertSee('Andi Teknisi')
            ->assertSee('Toko B')
            ->assertSee('Sudah Dibayar')
            ->assertSee('Menunggu Persetujuan Direksi')
            ->assertSee('Draft');

        $this->assertStringContainsString('Andi', urldecode($page->instance()->payrollUrl('Andi Teknisi')));

        $this->page(['month' => '2026-07-01'])->assertSee('Belum ada payroll digenerate untuk bulan ini.');
    }

    public function test_payroll_status_label_covers_every_status(): void
    {
        $this->assertSame('Draft', (new Payroll(['status' => 'draft']))->status_label);
        $this->assertSame('Menunggu Persetujuan Direksi', (new Payroll(['status' => 'pending_approval']))->status_label);
        $this->assertSame('Sudah Dibayar', (new Payroll(['status' => 'paid']))->status_label);
    }

    public function test_excel_rows_are_aligned_and_pending_approval_is_not_reported_as_draft(): void
    {
        $this->september();

        $export = new EmployeeReportExport($this->report());
        $rows = $export->array();

        $this->assertSame(['Karyawan', 'Toko', 'Hari Kerja', 'Telat (Menit)', 'Alpha (Hari)', 'Gaji Bersih', 'Status'], $export->headings());
        $this->assertCount(3, $rows);
        $this->assertSame(['Andi Teknisi', 'Toko A', 26, 30, 1, 5000000.0, 'Sudah Dibayar'], $rows[0]);
        $this->assertSame(['Citra Admin', 'Toko A', 26, 120, 2, 4000000.0, 'Draft'], $rows[1]);
        $this->assertSame(['Budi Kasir', 'Toko B', 24, 0, 0, 3000000.0, 'Menunggu Persetujuan Direksi'], $rows[2]);
    }

    public function test_exports_download_and_are_logged(): void
    {
        $this->september();
        $admin = $this->user('super_admin');
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeA->id], $admin);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-karyawan-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $admin->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('employee', $logs->first()->properties['report']);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['month' => '2026-07-01'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->september();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
