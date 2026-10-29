<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeDataCompletenessReport;
use App\Filament\Pages\InstallerCompetencyReport;
use App\Filament\Pages\TechnicianServiceDurationReport;
use App\Filament\Pages\TechnicianUtilizationReport;
use App\Filament\Resources\BookingReworkResource;
use App\Filament\Resources\ChecklistTemplateResource;
use App\Filament\Resources\CompanyPolicyResource;
use App\Filament\Resources\ContractExtensionResource;
use App\Filament\Resources\EmployeeChecklistItemResource;
use App\Filament\Resources\LeaveRequestResource;
use App\Filament\Resources\LeaveTypeResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\SalaryGradeResource;
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
        foreach ([TechnicianUtilizationReport::class, TechnicianServiceDurationReport::class, InstallerCompetencyReport::class, BookingReworkResource::class] as $screen) {
            $this->assertSame('Kinerja Installer', $screen::getNavigationGroup(), $screen);
        }
    }

    public function test_leave_and_contract_are_separate_from_policies_and_checklists(): void
    {
        foreach ([LeaveRequestResource::class, LeaveTypeResource::class, ContractExtensionResource::class, WarningLetterResource::class] as $screen) {
            $this->assertSame('Cuti & Kontrak', $screen::getNavigationGroup(), $screen);
        }

        foreach ([CompanyPolicyResource::class, EmployeeChecklistItemResource::class, ChecklistTemplateResource::class] as $screen) {
            $this->assertSame('Kebijakan & Checklist', $screen::getNavigationGroup(), $screen);
        }

        $this->assertLessThan(ContractExtensionResource::getNavigationSort(), LeaveTypeResource::getNavigationSort(), 'Jenis Izin & Cuti mengikuti Izin & Cuti.');
    }

    public function test_menu_names_are_clear(): void
    {
        $this->assertSame('Role & Divisi', RoleResource::getNavigationLabel());
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
