<?php

namespace Tests\Feature;

use App\Filament\Pages\DashboardHome;
use App\Filament\Widgets\BookingRevenueByCategoryChart;
use App\Filament\Widgets\BookingRevenueByPaymentMethodChart;
use App\Filament\Widgets\BookingRevenueStatsWidget;
use App\Filament\Widgets\BookingRevenueTrendChart;
use App\Filament\Widgets\BookingStatsWidget;
use App\Filament\Widgets\KaryawanStatsWidget;
use App\Filament\Widgets\LayananChart;
use App\Filament\Widgets\MarketingStatsWidget;
use App\Filament\Widgets\MasterDataStatsWidget;
use App\Filament\Widgets\PerluPerhatianWidget;
use App\Filament\Widgets\QuotationTrendChart;
use App\Filament\Widgets\SalesByOutletChart;
use App\Filament\Widgets\WarrantyByStoreChart;
use App\Filament\Widgets\WarrantyTrendChart;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BookingPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dashboard Utama (/admin): halaman dan SEMUA widget-nya harus dirender
 * tanpa error dengan data nyata, filter cabang bekerja untuk akses penuh,
 * dan staf toko hanya melihat widget/angka sesuai akses menu & tokonya.
 */
class DashboardHomeSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private Store $storeC;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(ChartOfAccountSeeder::class);
        Role::findOrCreate('store_manager', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->storeC = Store::create(['city' => 'Surabaya', 'address' => 'Jl. C', 'name' => 'Toko C', 'is_active' => true]);

        $this->admin = $this->user('super_admin', null);

        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        // Pendapatan terposting (laporan penjualan) di toko A dan B.
        foreach ([$this->storeA, $this->storeB] as $store) {
            $done = Booking::create([
                'booking_number'     => 'BKG-TEST-' . uniqid(),
                'customer_id'        => $customer->id,
                'store_id'           => $store->id,
                'service_type'       => 'Pelindung Cat (PPF)',
                'product_ppf'        => true,
                'preferred_date'     => now()->toDateString(),
                'status'             => 'completed',
                'transaction_amount' => 2_000_000,
                'amount_received'    => 1_500_000,
                'payment_method'     => 'tunai',
            ]);
            app(BookingPostingService::class)->sync($done);
        }

        // Pending lewat SLA 4 jam HANYA di toko A.
        $pending = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $this->storeA->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status'         => 'pending',
        ]);
        DB::table('bookings')->where('id', $pending->id)->update(['created_at' => now()->subHours(6)]);

        $vehicle = Vehicle::create(['brand' => 'Toyota', 'model' => 'Raize', 'size_category' => 'M']);
        Quotation::create([
            'quotation_number' => 'INQ-TEST-' . uniqid(), 'vehicle_id' => $vehicle->id,
            'customer_name' => 'Calon Customer', 'customer_phone' => '081234567890',
            'status' => 'new', 'source' => 'customer', 'store_id' => $this->storeA->id,
        ]);
    }

    private function user(string $role, ?int $storeId, ?array $menuAccess = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'store_id' => $storeId,
            'menu_access' => $menuAccess,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_dashboard_renders_for_full_access_with_data(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(DashboardHome::class)
            ->assertSuccessful()
            ->assertSee('Booking Hari Ini')
            ->assertSee('Booking Pending Lewat SLA');
    }

    public function test_store_filter_works_for_full_access(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(DashboardHome::class)
            ->set('storeId', $this->storeA->id)
            ->assertSuccessful()
            ->assertSee('Booking Pending Lewat SLA')
            ->set('storeId', $this->storeC->id)
            ->assertSuccessful()
            ->assertDontSee('Booking Pending Lewat SLA');
    }

    public function test_every_dashboard_widget_renders_on_its_own(): void
    {
        $this->actingAs($this->admin, 'web');

        $withStore = [
            PerluPerhatianWidget::class, BookingRevenueStatsWidget::class, BookingStatsWidget::class,
            BookingRevenueTrendChart::class, BookingRevenueByCategoryChart::class,
            BookingRevenueByPaymentMethodChart::class, WarrantyTrendChart::class,
            QuotationTrendChart::class, KaryawanStatsWidget::class, LayananChart::class,
        ];
        $withoutStore = [WarrantyByStoreChart::class, MarketingStatsWidget::class, MasterDataStatsWidget::class, SalesByOutletChart::class];

        foreach ($withStore as $widget) {
            Livewire::test($widget, ['storeId' => null])->assertSuccessful();
            Livewire::test($widget, ['storeId' => $this->storeA->id])->assertSuccessful();
        }

        foreach ($withoutStore as $widget) {
            Livewire::test($widget)->assertSuccessful();
        }
    }

    public function test_store_manager_only_sees_signals_of_own_store(): void
    {
        // Toko C tidak punya pending lewat SLA; toko A punya.
        $this->actingAs($this->user('store_manager', $this->storeC->id), 'web');
        Livewire::test(DashboardHome::class)->assertSuccessful()->assertDontSee('Booking Pending Lewat SLA');

        $this->actingAs($this->user('store_manager', $this->storeA->id), 'web');
        Livewire::test(DashboardHome::class)->assertSuccessful()->assertSee('Booking Pending Lewat SLA');
    }

    public function test_store_manager_cannot_override_store_filter_via_query(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeC->id), 'web');

        Livewire::test(DashboardHome::class)
            ->set('storeId', $this->storeA->id)
            ->assertSuccessful()
            ->assertDontSee('Booking Pending Lewat SLA');
    }

    public function test_staff_without_booking_menu_does_not_see_booking_widgets(): void
    {
        $this->actingAs($this->user('store_manager', $this->storeA->id, ['SomeOtherResource']), 'web');

        Livewire::test(DashboardHome::class)
            ->assertSuccessful()
            ->assertDontSee('Booking Hari Ini')
            ->assertDontSee('Booking Pending Lewat SLA');
    }
}
