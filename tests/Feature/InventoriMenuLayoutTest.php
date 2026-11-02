<?php

namespace Tests\Feature;

use App\Filament\Clusters\BookingCluster;
use App\Filament\Clusters\InventarisCluster;
use App\Filament\Clusters\KaryawanCluster;
use App\Filament\Clusters\PelangganCluster;
use App\Filament\Clusters\ProdukCluster;
use App\Filament\Clusters\PromosiCluster;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Inventori jadi tab menu atas sendiri (2026-11-02), bukan lagi di bawah grup "Penjualan". Grup Penjualan sisanya tetap.
 */
class InventoriMenuLayoutTest extends TestCase
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

    public function test_inventori_is_its_own_top_menu_between_booking_and_karyawan(): void
    {
        $this->assertNull(InventarisCluster::getNavigationGroup(), 'Tidak lagi di bawah grup Penjualan.');
        $this->assertGreaterThan(BookingCluster::getNavigationSort(), InventarisCluster::getNavigationSort());
        $this->assertLessThan(KaryawanCluster::getNavigationSort(), InventarisCluster::getNavigationSort());
        $this->assertTrue(InventarisCluster::canAccess(), 'Akses penuh tetap bisa membukanya.');
    }

    public function test_scroll_codes_and_master_resep_moved_into_the_inventori_cluster(): void
    {
        $this->assertSame(InventarisCluster::class, \App\Filament\Resources\ScrollCodeResource::getCluster());
        $this->assertSame('Master Data Stok', \App\Filament\Resources\ScrollCodeResource::getNavigationGroup());
        $this->assertFalse(\App\Filament\Resources\ScrollCodeResource::shouldRegisterNavigation(), 'Kode Gulungan tetap tersembunyi dari sidebar; dibuka lewat Produk PPF/WF.');

        $this->assertSame(InventarisCluster::class, \App\Filament\Resources\MasterResepResource::getCluster());
        $this->assertSame('Kelola Stok', \App\Filament\Resources\MasterResepResource::getNavigationGroup());
        $this->assertSame(\App\Filament\Resources\StockOpnameResource::getNavigationGroup(), \App\Filament\Resources\MasterResepResource::getNavigationGroup());
        $this->assertTrue(\App\Filament\Resources\MasterResepResource::shouldRegisterNavigation());
        $this->assertNotEmpty(\App\Filament\Resources\MasterResepResource::getUrl('index'));
    }

    public function test_the_other_clusters_stay_under_the_penjualan_group(): void
    {
        foreach ([ProdukCluster::class, PelangganCluster::class, PromosiCluster::class, \App\Filament\Clusters\PenjualanCluster::class, \App\Filament\Clusters\AnalisaLaporanCluster::class] as $cluster) {
            $this->assertSame('Penjualan', $cluster::getNavigationGroup(), $cluster);
        }
    }
}
