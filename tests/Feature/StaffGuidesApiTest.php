<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Support\GuideCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panduan di aplikasi staf (Panduan Fase 3): isinya sama dengan panel, installer hanya mendapat panduan aplikasi, dan butuh login.
 */
class StaffGuidesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['kasir', 'installer', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        $store = Store::first() ?? Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function ids($response): array
    {
        return collect($response->assertOk()->json('guides'))->pluck('id')->all();
    }

    public function test_the_guides_require_a_staff_login(): void
    {
        $this->getJson('/api/staff/guides')->assertStatus(401);
    }

    public function test_the_app_only_guide_list_names_real_guides(): void
    {
        $all = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (GuideCatalog::APP_ONLY_GUIDE_IDS as $id) {
            $this->assertContains($id, $all, "Panduan aplikasi `{$id}` tidak ada di katalog.");
        }
    }

    public function test_an_installer_only_gets_the_app_guides_even_without_a_menu_limit(): void
    {
        $ids = $this->ids($this->actingAs($this->user('installer'), 'api')->getJson('/api/staff/guides'));

        $this->assertEqualsCanonicalizing(GuideCatalog::APP_ONLY_GUIDE_IDS, $ids);
        $this->assertNotContains('penggajian', $ids);
        $this->assertNotContains('transaksi-keuangan', $ids);
    }

    public function test_full_access_gets_every_guide_and_restricted_staff_only_their_modules(): void
    {
        $all = collect(GuideCatalog::all())->pluck('id')->all();

        $admin = $this->ids($this->actingAs($this->user('super_admin'), 'api')->getJson('/api/staff/guides'));
        $this->assertEqualsCanonicalizing($all, $admin);

        $kasir = $this->ids($this->actingAs($this->user('kasir', ['InvoiceResource']), 'api')->getJson('/api/staff/guides'));
        $this->assertContains('invoice', $kasir);
        $this->assertNotContains('penggajian', $kasir);
    }

    public function test_the_app_returns_the_same_text_as_the_panel(): void
    {
        $guide = collect($this->actingAs($this->user('super_admin'), 'api')->getJson('/api/staff/guides')->assertOk()->json('guides'))
            ->firstWhere('id', 'absensi');
        $catalog = collect(GuideCatalog::all())->firstWhere('id', 'absensi');

        $this->assertSame($catalog['steps'], $guide['steps']);
        $this->assertSame($catalog['tips'], $guide['tips']);
        $this->assertSame($catalog['title'], $guide['title']);
    }
}
