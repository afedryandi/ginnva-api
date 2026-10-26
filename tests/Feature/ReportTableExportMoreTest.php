<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeDataCompletenessReport;
use App\Filament\Pages\PaymentBookReport;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ekspor Excel dan PDF untuk Buku Pembayaran dan Kelengkapan Data Karyawan (ExportsReportTable).
 * "Hari ini" dibekukan di 20 Oktober 2026.
 */
class ReportTableExportMoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exportData($page): array
    {
        return (fn () => $this->reportExportData())->call($page->instance());
    }

    public function test_payment_book_export_has_the_documented_shape_even_when_empty(): void
    {
        $data = $this->exportData(Livewire::test(PaymentBookReport::class));

        $this->assertSame('buku-pembayaran', $data['key']);
        $this->assertCount(8, $data['headings']);
        $this->assertSame([], $data['rows']);
        $this->assertSame(0.0, $data['totals'][6]);
        $this->assertStringContainsString('Semua cabang', $data['subtitle']);
    }

    public function test_completeness_export_lists_employees_with_findings(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        User::create(['name' => 'Budi Baru', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'is_active' => true]);

        $data = $this->exportData(Livewire::test(EmployeeDataCompletenessReport::class));

        $this->assertSame('kelengkapan-data-karyawan', $data['key']);
        $row = collect($data['rows'])->firstWhere(0, 'Budi Baru');
        $this->assertNotNull($row);
        $this->assertSame('Toko A', $row[1]);
        $this->assertStringContainsString('Gaji pokok belum diisi', $row[2]);
    }

    public function test_the_buttons_download_named_files_and_are_logged(): void
    {
        Excel::fake();

        Livewire::test(PaymentBookReport::class)->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('buku-pembayaran-20261020-090000.xlsx');
        Livewire::test(PaymentBookReport::class)->callAction('exportPdf')->assertFileDownloaded('buku-pembayaran-20261020-090000.pdf');
        Livewire::test(EmployeeDataCompletenessReport::class)->callAction('exportPdf')->assertFileDownloaded('kelengkapan-data-karyawan-20261020-090000.pdf');

        $this->assertSame(3, Activity::where('log_name', 'report_export')->count());
    }
}
