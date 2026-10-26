<?php

namespace Tests\Feature;

use App\Exports\SectionedTableExport;
use App\Filament\Pages\BookingServiceLevelReport;
use App\Filament\Pages\InstallerCompetencyReport;
use App\Filament\Pages\LeadFunnelReport;
use App\Models\Store;
use App\Models\Technician;
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
 * Ekspor Excel dan PDF untuk laporan ringkasan beberapa tabel: SLA & Tidak Datang, Dasbor Lead, Kompetensi Installer
 * (ExportsReportTable bentuk 'sections' + SectionedTableExport). "Hari ini" dibekukan di 20 Oktober 2026.
 */
class SectionedReportExportTest extends TestCase
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

    private function sectionTitles(array $data): array
    {
        return array_column($data['sections'], 'title');
    }

    public function test_service_level_export_has_summary_and_cancellation_sections(): void
    {
        $data = $this->exportData(Livewire::test(BookingServiceLevelReport::class));

        $this->assertSame('sla-dan-tidak-datang', $data['key']);
        $this->assertSame(['Ringkasan', 'Dibatalkan oleh', 'Alasan pembatalan terbanyak'], $this->sectionTitles($data));
        $this->assertCount(6, $data['sections'][0]['rows']);
        $this->assertSame(0, $data['sections'][0]['rows'][0][1]);
        $this->assertStringContainsString('Semua cabang', $data['subtitle']);
    }

    public function test_lead_funnel_export_has_every_section_with_matching_columns(): void
    {
        $data = $this->exportData(Livewire::test(LeadFunnelReport::class));

        $this->assertSame('dasbor-lead', $data['key']);
        $this->assertSame(
            ['Ringkasan', 'Menurut Jenis Lead', 'Menurut Kanal', 'Menurut Kampanye (utm_campaign)', 'Alasan Tidak Jadi Terbanyak'],
            $this->sectionTitles($data),
        );
        $this->assertCount(9, $data['sections'][1]['headings']);
        $this->assertStringContainsString('Semua jenis lead', $data['subtitle']);
    }

    public function test_competency_export_lists_each_technician_with_a_level_per_service(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        Technician::create(['store_id' => $store->id, 'name' => 'Andi Teknisi', 'level' => 'intermediate', 'status' => 'active']);

        $data = $this->exportData(Livewire::test(InstallerCompetencyReport::class));

        $this->assertSame('kompetensi-installer', $data['key']);
        $matrix = $data['sections'][0];
        $row = collect($matrix['rows'])->firstWhere(0, 'Andi Teknisi');
        $this->assertNotNull($row);
        $this->assertSame('Toko A', $row[1]);
        $this->assertCount(count($matrix['headings']), $row);
        $this->assertContains('Belum dinilai', array_slice($row, 2));
        $this->assertSame(['Matriks keterampilan', 'Sertifikat kedaluwarsa atau hampir kedaluwarsa', 'Mutu pekerjaan'], $this->sectionTitles($data));
    }

    public function test_the_buttons_download_named_files_and_are_logged(): void
    {
        Excel::fake();

        Livewire::test(BookingServiceLevelReport::class)->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('sla-dan-tidak-datang-20261020-090000.xlsx');
        Livewire::test(BookingServiceLevelReport::class)->callAction('exportPdf')->assertFileDownloaded('sla-dan-tidak-datang-20261020-090000.pdf');
        Livewire::test(LeadFunnelReport::class)->callAction('exportPdf')->assertFileDownloaded('dasbor-lead-20261020-090000.pdf');
        Livewire::test(InstallerCompetencyReport::class)->callAction('exportPdf')->assertFileDownloaded('kompetensi-installer-20261020-090000.pdf');

        $this->assertSame(4, Activity::where('log_name', 'report_export')->count());
    }

    public function test_the_sectioned_excel_class_lays_out_titles_headings_rows_and_blank_separators(): void
    {
        $export = new SectionedTableExport(['sections' => [
            ['title' => 'Satu', 'headings' => ['A', 'B'], 'rows' => [['x', 1]], 'totals' => ['Total', 1]],
            ['title' => 'Dua', 'headings' => ['C'], 'rows' => []],
        ]]);

        $this->assertSame([
            ['Satu'], ['A', 'B'], ['x', 1], ['Total', 1], [],
            ['Dua'], ['C'], ['Tidak ada data.'], [],
        ], $export->array());

        $styles = $export->styles(new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet());
        $keys = array_keys($styles);
        sort($keys);
        $this->assertSame([1, 2, 4, 6, 7], $keys);
    }
}
