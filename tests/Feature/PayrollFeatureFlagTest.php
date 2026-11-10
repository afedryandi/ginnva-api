<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeReport;
use App\Filament\Resources\AttendanceDeductionRuleResource;
use App\Filament\Resources\PayrollItemCategoryResource;
use App\Filament\Resources\PayrollResource;
use App\Models\Store;
use App\Models\User;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keputusan pemilik 2026-11-10: gaji tidak dihitung di sistem ini. Saklar FEATURE_PAYROLL (config/features.php) menyembunyikan menu
 * Penggajian, Kategori Komponen Gaji, Aturan Potongan Absensi, Laporan Karyawan, endpoint slip gaji, dan panduan Penggajian. Tes lain berjalan
 * dengan saklar menyala (phpunit.xml). Kode dan data tetap ada.
 */
class PayrollFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->admin = tap(User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'store_id' => $store->id]), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_payroll_menus_and_report_are_closed_even_to_full_access_when_the_switch_is_off(): void
    {
        config(['features.payroll' => false]);
        $this->actingAs($this->admin, 'web');

        foreach ([PayrollResource::class, PayrollItemCategoryResource::class, AttendanceDeductionRuleResource::class] as $resource) {
            $this->assertFalse($resource::canViewAny(), "{$resource} seharusnya tertutup.");
        }
        $this->assertFalse(EmployeeReport::canAccess());
    }

    public function test_payroll_menus_and_report_open_for_full_access_when_the_switch_is_on(): void
    {
        config(['features.payroll' => true]);
        $this->actingAs($this->admin, 'web');

        $this->assertTrue(PayrollResource::canViewAny());
        $this->assertTrue(EmployeeReport::canAccess());
    }

    public function test_slip_endpoints_return_404_and_profile_flag_is_false_when_off(): void
    {
        config(['features.payroll' => false]);

        $this->actingAs($this->admin, 'api')->getJson('/api/staff/payroll')->assertNotFound();
        $this->actingAs($this->admin, 'api')->getJson('/api/staff/payroll/1/slip')->assertNotFound();
        $this->actingAs($this->admin, 'api')->getJson('/api/staff/auth/me')->assertOk()->assertJsonPath('user.has_payroll', false);
    }

    public function test_slip_endpoint_works_and_profile_flag_is_true_when_on(): void
    {
        config(['features.payroll' => true]);

        $this->actingAs($this->admin, 'api')->getJson('/api/staff/payroll')->assertOk();
        $this->actingAs($this->admin, 'api')->getJson('/api/staff/auth/me')->assertOk()->assertJsonPath('user.has_payroll', true);
    }

    public function test_payroll_guide_is_hidden_when_off_and_shown_when_on(): void
    {
        config(['features.payroll' => false, 'features.finance' => true]);
        $this->assertNotContains('penggajian', array_column(GuideCatalog::forUser($this->admin), 'id'));

        config(['features.payroll' => true]);
        $this->assertContains('penggajian', array_column(GuideCatalog::forUser($this->admin), 'id'));
    }
}
