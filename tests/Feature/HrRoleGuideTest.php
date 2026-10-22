<?php

namespace Tests\Feature;

use App\Filament\Resources\ContractExtensionResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WarningLetterResource;
use App\Models\User;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panduan HR (hr-harian) muncul bagi akun yang menu HR-nya dicentang eksplisit, dan batas yang ditulisnya (tanpa kelola akun, tanpa
 * koreksi surat peringatan, riwayat kontrak tidak bisa diedit) harus benar-benar ditegakkan.
 */
class HrRoleGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['general_affair', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, ?array $menuAccess): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => $menuAccess, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function ids(User $user): array
    {
        return collect(GuideCatalog::forUser($user))->pluck('id')->all();
    }

    public function test_the_hr_guide_needs_an_explicit_hr_menu_and_not_just_an_unrestricted_account(): void
    {
        $this->assertContains('hr-harian', $this->ids($this->user('general_affair', ['LeaveRequestResource'])));
        $this->assertContains('hr-harian', $this->ids($this->user('super_admin', null)));
        $this->assertNotContains('hr-harian', $this->ids($this->user('general_affair', null)), 'Akun tanpa batas menu tidak boleh otomatis melihat panduan HR.');
        $this->assertNotContains('hr-harian', $this->ids($this->user('general_affair', ['InvoiceResource'])));
    }

    public function test_the_limits_the_hr_guide_states_are_enforced(): void
    {
        $this->actingAs($this->user('general_affair', ['LeaveRequestResource', 'WarningLetterResource', 'ContractExtensionResource']), 'web');

        $this->assertFalse(UserResource::canViewAny(), 'Panduan menyebut pengelolaan akun hanya untuk akses penuh.');
        $this->assertFalse(UserResource::canCreate());
        $this->assertTrue(WarningLetterResource::canCreate(), 'Panduan menyebut HR boleh menerbitkan Surat Peringatan.');
        $this->assertFalse(WarningLetterResource::canEdit(new \App\Models\WarningLetter()), 'Panduan menyebut surat yang sudah terbit tidak bisa dikoreksi HR.');
        $this->assertFalse(ContractExtensionResource::canEdit(new \App\Models\ContractExtension()), 'Panduan menyebut riwayat perpanjangan kontrak tidak bisa diedit.');
    }
}
