<?php

namespace Tests\Feature;

use App\Filament\Clusters\KaryawanCluster;
use App\Filament\Pages\AttendanceReport;
use App\Filament\Pages\EmployeeDataCompletenessReport;
use App\Filament\Pages\FixedCommissionReport;
use App\Filament\Pages\HrDashboard;
use App\Filament\Pages\InstallerCompetencyReport;
use App\Filament\Pages\TechnicianCommissionReport;
use App\Filament\Pages\TechnicianServiceDurationReport;
use App\Filament\Pages\TechnicianUtilizationReport;
use App\Filament\Resources\AttendanceCorrectionRequestResource;
use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\BookingReworkResource;
use App\Filament\Resources\ChecklistTemplateResource;
use App\Filament\Resources\CompanyPolicyResource;
use App\Filament\Resources\ContractExtensionResource;
use App\Filament\Resources\EmployeeChecklistItemResource;
use App\Filament\Resources\EmployeeTypeResource;
use App\Filament\Resources\LeaveRequestResource;
use App\Filament\Resources\LeaveTypeResource;
use App\Filament\Resources\PayrollResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\SalaryGradeResource;
use App\Filament\Resources\TechnicianResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WarningLetterResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Audit modul Karyawan (2026-10-29): grup dan nama menu dirapikan, dan tiga menu yang tidak dibutuhkan disembunyikan lewat saklar
 * FEATURE_SALARY_GRADES, FEATURE_EMPLOYEE_CHECKLISTS, dan FEATURE_INSTALLER_UTILIZATION (mati bawaan; phpunit.xml menyalakannya).
 */
class KaryawanMenuLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    public function test_installer_performance_screens_share_one_group(): void
    {
        foreach ([TechnicianUtilizationReport::class, TechnicianServiceDurationReport::class, InstallerCompetencyReport::class, TechnicianCommissionReport::class, FixedCommissionReport::class] as $screen) {
            $this->assertSame('Kinerja Installer', $screen::getNavigationGroup(), $screen);
        }
    }

    public function test_rework_lives_with_the_booking_menus_after_service(): void
    {
        $this->assertSame(\App\Filament\Clusters\BookingCluster::class, BookingReworkResource::getCluster());
        $this->assertSame('Setelah Layanan', BookingReworkResource::getNavigationGroup());
        $this->assertSame(\App\Filament\Resources\WarrantyResource::getNavigationGroup(), BookingReworkResource::getNavigationGroup());
    }

    public function test_the_employee_menus_are_grouped_by_how_often_they_are_used(): void
    {
        foreach ([UserResource::class, HrDashboard::class] as $screen) {
            $this->assertSame('Karyawan', $screen::getNavigationGroup(), $screen);
        }

        $this->assertLessThan(UserResource::getNavigationSort(), HrDashboard::getNavigationSort(), 'Dasbor HR menu pertama klaster Karyawan.');

        foreach ([AttendanceResource::class, AttendanceCorrectionRequestResource::class, LeaveRequestResource::class] as $screen) {
            $this->assertSame('Absensi & Cuti', $screen::getNavigationGroup(), $screen);
        }

        // Catatan dan dokumen kepegawaian (dipakai saat ada kejadian) dipisah dari pengaturan sungguhan (diatur sekali).
        $records = [
            EmployeeDataCompletenessReport::class, ContractExtensionResource::class, WarningLetterResource::class,
            CompanyPolicyResource::class, EmployeeChecklistItemResource::class, ChecklistTemplateResource::class,
        ];

        foreach ($records as $screen) {
            $this->assertSame('Kepegawaian', $screen::getNavigationGroup(), $screen);
            $this->assertGreaterThanOrEqual(500, $screen::getNavigationSort(), $screen);
            $this->assertLessThan(600, $screen::getNavigationSort(), $screen);
        }

        foreach ([RoleResource::class, EmployeeTypeResource::class, LeaveTypeResource::class] as $screen) {
            $this->assertSame('Pengaturan Karyawan', $screen::getNavigationGroup(), $screen);
            $this->assertGreaterThanOrEqual(600, $screen::getNavigationSort(), $screen);
            $this->assertLessThan(700, $screen::getNavigationSort(), $screen);
        }

        foreach ([UserResource::class, AttendanceResource::class, TechnicianServiceDurationReport::class] as $daily) {
            $this->assertLessThan(500, $daily::getNavigationSort(), $daily);
        }

        // Penggajian (mati bawaan) paling bawah.
        $this->assertGreaterThanOrEqual(700, PayrollResource::getNavigationSort());
    }

    public function test_installer_attendance_report_and_commission_live_in_the_employee_cluster(): void
    {
        foreach ([TechnicianResource::class, AttendanceReport::class, TechnicianCommissionReport::class, FixedCommissionReport::class] as $screen) {
            $this->assertSame(KaryawanCluster::class, $screen::getCluster(), $screen);
        }

        $this->assertSame('Karyawan', TechnicianResource::getNavigationGroup());
        $this->assertSame('Absensi & Cuti', AttendanceReport::getNavigationGroup());
        $this->assertSame('Laporan Absensi', AttendanceReport::getNavigationLabel());
        $this->assertGreaterThan(LeaveRequestResource::getNavigationSort(), AttendanceReport::getNavigationSort(), 'Laporan Absensi menutup grup Absensi & Cuti.');
        $this->assertGreaterThan(UserResource::getNavigationSort(), TechnicianResource::getNavigationSort(), 'Installer menyusul User.');
    }

    public function test_groups_follow_the_agreed_order_by_their_first_item(): void
    {
        $first = fn (array $screens) => min(array_map(fn ($s) => $s::getNavigationSort(), $screens));
        $order = [
            $first([HrDashboard::class, UserResource::class, TechnicianResource::class]),
            $first([AttendanceResource::class, AttendanceReport::class]),
            $first([TechnicianUtilizationReport::class, TechnicianServiceDurationReport::class, InstallerCompetencyReport::class]),
            $first([EmployeeDataCompletenessReport::class, WarningLetterResource::class]),
            $first([RoleResource::class, LeaveTypeResource::class]),
            $first([PayrollResource::class]),
        ];

        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'Karyawan, Absensi & Cuti, Kinerja Installer, Kepegawaian, Pengaturan Karyawan, Penggajian.');
    }

    public function test_menu_names_are_clear(): void
    {
        $this->assertSame('Jabatan & Divisi', RoleResource::getNavigationLabel());
        $this->assertSame('Kelengkapan Data Karyawan', EmployeeDataCompletenessReport::getNavigationLabel());
        $this->assertSame('Riwayat Kontrak', ContractExtensionResource::getNavigationLabel());
        $this->assertSame('Checklist Masuk & Keluar', EmployeeChecklistItemResource::getNavigationLabel());
    }

    public function test_the_three_menus_are_closed_to_full_access_when_their_switches_are_off(): void
    {
        config(['features.salary_grades' => false, 'features.employee_checklists' => false, 'features.installer_utilization' => false]);

        $this->assertFalse(SalaryGradeResource::canViewAny());
        $this->assertFalse(EmployeeChecklistItemResource::canViewAny());
        $this->assertFalse(ChecklistTemplateResource::canViewAny());
        $this->assertFalse(TechnicianUtilizationReport::canAccess());
        $this->assertTrue(TechnicianServiceDurationReport::canAccess(), 'Durasi Servis Installer tetap terbuka.');

        config(['features.salary_grades' => true, 'features.employee_checklists' => true, 'features.installer_utilization' => true]);

        $this->assertTrue(SalaryGradeResource::canViewAny());
        $this->assertTrue(EmployeeChecklistItemResource::canViewAny());
        $this->assertTrue(ChecklistTemplateResource::canViewAny());
        $this->assertTrue(TechnicianUtilizationReport::canAccess());
    }
}
