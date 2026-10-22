<?php

namespace Tests\Feature;

use App\Filament\Pages\ClosePeriodPage;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\YearEndClosingPage;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panduan Direksi (direksi-harian): hanya untuk akses penuh, dan halaman yang ditulisnya sebagai "hanya Anda" (penutupan pembukuan,
 * Pengaturan Keuangan, pengelolaan akun) benar-benar tertutup bagi Store Manager dan Spv Finance.
 */
class DireksiGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['direksi', 'super_admin', 'store_manager', 'spv_finance'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    public function test_the_direksi_guide_is_for_full_access_only(): void
    {
        foreach (['direksi', 'super_admin'] as $role) {
            $this->assertContains('direksi-harian', collect(GuideCatalog::forUser($this->user($role)))->pluck('id')->all());
        }
        foreach (['store_manager', 'spv_finance'] as $role) {
            $this->assertNotContains('direksi-harian', collect(GuideCatalog::forUser($this->user($role)))->pluck('id')->all(), "{$role} tidak boleh melihat panduan Direksi.");
        }
    }

    public function test_what_the_guide_calls_only_for_full_access_is_closed_to_others(): void
    {
        $this->actingAs($this->user('direksi'), 'web');
        $this->assertTrue(ClosePeriodPage::canAccess());
        $this->assertTrue(YearEndClosingPage::canAccess());
        $this->assertTrue(FinanceSettingsPage::canAccess());
        $this->assertTrue(UserResource::canViewAny());

        foreach (['store_manager', 'spv_finance'] as $role) {
            $this->actingAs($this->user($role), 'web');
            $this->assertFalse(ClosePeriodPage::canAccess(), "{$role}: Tutup Periode hanya akses penuh.");
            $this->assertFalse(YearEndClosingPage::canAccess(), "{$role}: Tutup Tahun Buku hanya akses penuh.");
            $this->assertFalse(FinanceSettingsPage::canAccess(), "{$role}: Pengaturan Keuangan hanya akses penuh.");
            $this->assertFalse(UserResource::canViewAny(), "{$role}: pengelolaan akun hanya akses penuh.");
        }
    }
}
