<?php

namespace Tests\Feature;

use App\Filament\Clusters\BookingCluster;
use App\Filament\Clusters\SistemCluster;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman induk menu (Cluster) harus ditolak (403) untuk role yang tidak punya satu pun isi di dalamnya, bukan error 500
 * (ditemukan lewat smoke:panel 2026-10-13: store_manager membuka alamat Sistem langsung).
 */
class ClusterAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $store = Store::firstOrCreate(['name' => 'Toko A'], ['city' => 'Jakarta', 'address' => 'Jl. A', 'is_active' => true]);

        return tap(User::create([
            'name' => ucfirst($role), 'email' => uniqid() . '@test.local', 'password' => 'x',
            'store_id' => $store->id, 'menu_access' => null, 'is_active' => true,
        ]), fn (User $u) => $u->assignRole($role));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('store_manager', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_cluster_without_accessible_items_is_not_accessible(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');

        $this->assertFalse(SistemCluster::canAccess(), 'Riwayat Aktivitas khusus full-access, jadi Sistem kosong untuk store_manager.');
        $this->assertTrue(BookingCluster::canAccess());
    }

    public function test_full_access_user_can_access_every_cluster(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $this->assertTrue(SistemCluster::canAccess());
        $this->assertTrue(BookingCluster::canAccess());
    }

    public function test_opening_an_empty_cluster_url_directly_is_forbidden_not_a_server_error(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');

        $status = $this->get(SistemCluster::getUrl())->getStatusCode();

        $this->assertContains($status, [403, 404], "Status {$status}: harus ditolak, bukan error server.");
    }
}
