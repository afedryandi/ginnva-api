<?php

namespace Tests\Feature;

use App\Filament\Clusters\BookingCluster;
use App\Filament\Clusters\InventarisCluster;
use App\Filament\Clusters\KaryawanCluster;
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

    public function test_the_remaining_clusters_exist_and_inventori_is_not_under_penjualan(): void
    {
        // Cluster Produk, Pelanggan, Promosi, dan Analisa Laporan sudah tidak ada: modul Penjualan diganti satu cluster "Laporan"
        // (PenjualanCluster, tanpa grup induk). Yang dijaga di sini: cluster itu tidak lagi bergantung pada grup "Penjualan" dan
        // Inventori tetap tidak berada di bawahnya.
        foreach (['ProdukCluster', 'PelangganCluster', 'PromosiCluster', 'AnalisaLaporanCluster'] as $gone) {
            $this->assertFalse(class_exists('App\\Filament\\Clusters\\' . $gone), "{$gone} sudah dihapus, tes ini tidak perlu mengacunya lagi.");
        }

        $this->assertNull(\App\Filament\Clusters\PenjualanCluster::getNavigationGroup());
        $this->assertNotSame('Penjualan', InventarisCluster::getNavigationGroup());
    }
}
