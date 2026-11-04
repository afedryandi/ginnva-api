<?php

namespace Tests\Feature;

use App\Filament\Pages\JenisOrderReport;
use App\Filament\Pages\LayananReport;
use App\Filament\Pages\PaymentBookReport;
use App\Filament\Pages\ProductSalesReport;
use App\Filament\Pages\RefundReport;
use App\Filament\Pages\SalesByOutletReport;
use App\Filament\Pages\SalesByPeriodReport;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\SalesSummaryReport;
use App\Filament\Pages\VoidReport;
use App\Filament\Resources\SalesResource;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan omzet (Dashboard, Ringkasan, Detail, Per Periode, Outlet, Buku Pembayaran, Refund, Void, Jasa/Jenis Order, Penjualan Produk)
 * hanya terbuka untuk akses penuh atau karyawan yang menunya dicentang eksplisit. Daftar menu yang masih kosong (belum diatur) tidak
 * otomatis membukanya (keputusan pemilik 2026-11-04).
 */
class ExplicitRevenueReportAccessTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function staff(?array $menuAccess, string $role = 'kasir'): User
    {
        return tap(User::create(['name' => 'Staff ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess]), fn (User $u) => $u->assignRole($role));
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function reports(): array
    {
        return [
            'Dashboard Penjualan' => [SalesDashboard::class, 'canAccess'],
            'Ringkasan Penjualan' => [SalesSummaryReport::class, 'canAccess'],
            'Detail Penjualan' => [SalesResource::class, 'canViewAny'],
            'Penjualan Per Periode' => [SalesByPeriodReport::class, 'canAccess'],
            'Penjualan Outlet' => [SalesByOutletReport::class, 'canAccess'],
            'Buku Pembayaran' => [PaymentBookReport::class, 'canAccess'],
            'Laporan Refund' => [RefundReport::class, 'canAccess'],
            'Laporan Pembatalan' => [VoidReport::class, 'canAccess'],
            'Laporan Jasa' => [LayananReport::class, 'canAccess'],
            'Laporan Jenis Order' => [JenisOrderReport::class, 'canAccess'],
            'Penjualan Produk' => [ProductSalesReport::class, 'canAccess'],
        ];
    }

    public function test_an_unset_menu_list_does_not_open_the_report_but_a_checked_box_and_full_access_do(): void
    {
        foreach (self::reports() as $name => [$class, $method]) {
            $this->actingAs($this->staff(null), 'web');
            $this->assertFalse((bool) $class::$method(), "{$name}: daftar menu kosong tidak membuka laporan omzet.");

            $this->actingAs($this->staff([class_basename($class)]), 'web');
            $this->assertTrue((bool) $class::$method(), "{$name}: menu yang dicentang eksplisit membuka laporan.");

            $this->actingAs($this->staff(['BookingResource']), 'web');
            $this->assertFalse((bool) $class::$method(), "{$name}: menu lain yang dicentang tidak membukanya.");

            $this->actingAs($this->staff(null, 'super_admin'), 'web');
            $this->assertTrue((bool) $class::$method(), "{$name}: akses penuh selalu terbuka.");
        }
    }
}