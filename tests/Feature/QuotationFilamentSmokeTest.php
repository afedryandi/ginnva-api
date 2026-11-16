<?php

namespace Tests\Feature;

use App\Filament\Resources\QuotationResource\Pages\CreateQuotation;
use App\Filament\Resources\QuotationResource\Pages\EditQuotation;
use App\Filament\Resources\QuotationResource\Pages\ListQuotations;
use App\Filament\Resources\QuotationResource\Pages\ViewQuotation;
use App\Models\FilmProduct;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke test halaman Filament Quotation: daftar, filter status, detail,
 * edit, buat -- semuanya harus bisa dirender tanpa error.
 */
class QuotationFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Quotation $new;
    private Quotation $contacted;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        // Izin 'quotation.manage' (edit/buat) dibagikan oleh seeder ke super_admin di lingkungan sungguhan.
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->admin = $admin;
        // Panel admin Filament memakai guard 'web' (sesi); actingAs 'web' juga menjadikannya
        // guard default seperti saat request panel sungguhan.
        $this->actingAs($admin, 'web');

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $vehicle = Vehicle::create(['brand' => 'Toyota', 'model' => 'Raize', 'size_category' => 'M']);
        $product = FilmProduct::create([
            'sku' => 'WF-Q2', 'name' => 'Produk Q2', 'product_type' => 'window_film',
            'position' => 'front', 'base_price' => 100_000, 'is_active' => true,
        ]);

        $make = function (string $status) use ($store, $vehicle, $product) {
            $q = Quotation::create([
                'quotation_number' => 'INQ-TEST-' . uniqid(),
                'vehicle_id'       => $vehicle->id,
                'customer_name'    => 'Budi ' . $status,
                'customer_phone'   => '081234567890',
                'status'           => $status,
                'source'           => 'customer',
                'store_id'         => $store->id,
            ]);
            QuotationItem::create(['quotation_id' => $q->id, 'film_product_id' => $product->id]);

            return $q;
        };

        $this->new = $make('new');
        $this->contacted = $make('contacted');
    }

    public function test_super_admin_passes_quotation_authorization(): void
    {
        $user = Filament::auth()->user();

        $this->assertNotNull($user, 'Filament::auth()->user() null: guard Filament berbeda dari guard login.');
        $this->assertTrue($this->admin->canAccessStaffArea(), 'canAccessStaffArea() false untuk super_admin.');
        $this->assertTrue($this->admin->hasMenuAccess(\App\Filament\Resources\QuotationResource::class), 'hasMenuAccess() false.');
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($user)->check('viewAny', Quotation::class), 'Gate viewAny Quotation ditolak.');
        $this->assertTrue(\App\Filament\Resources\QuotationResource::canViewAny(), 'QuotationResource::canViewAny() false.');
    }

    public function test_quotation_list_renders_and_status_tabs_work(): void
    {
        Livewire::test(ListQuotations::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->new, $this->contacted])
            ->set('activeTab', 'new')
            ->assertCanSeeTableRecords([$this->new])
            ->assertCanNotSeeTableRecords([$this->contacted])
            ->set('activeTab', 'contacted')
            ->assertCanSeeTableRecords([$this->contacted])
            ->assertCanNotSeeTableRecords([$this->new]);
    }

    public function test_status_tabs_carry_the_count_of_each_status(): void
    {
        $tabs = Livewire::test(ListQuotations::class)->instance()->getTabs();

        $this->assertSame(['all', 'new', 'contacted', 'closed', 'cancelled'], array_keys($tabs));
        $this->assertSame(1, $tabs['new']->getBadge());
        $this->assertSame(1, $tabs['contacted']->getBadge());
        $this->assertSame(0, $tabs['closed']->getBadge());
    }

    public function test_row_actions_are_whatsapp_view_and_the_more_menu(): void
    {
        Livewire::test(ListQuotations::class)
            ->assertTableActionExists('whatsapp')
            ->assertTableActionExists('view')
            ->assertTableActionExists('quickStatus')
            ->assertTableActionExists('edit');
    }

    public function test_quick_status_still_stamps_the_first_response_time(): void
    {
        Livewire::test(ListQuotations::class)
            ->callTableAction('quickStatus', $this->new, ['status' => 'contacted'])
            ->assertHasNoTableActionErrors();

        $fresh = $this->new->fresh();
        $this->assertSame('contacted', $fresh->status);
        $this->assertNotNull($fresh->contacted_at);
    }

    public function test_the_clean_style_applies_only_to_redesigned_menus(): void
    {
        $this->assertTrue(\App\Support\CleanUi::appliesTo(new ListQuotations()));
        $this->assertTrue(\App\Support\CleanUi::appliesTo(new \App\Filament\Resources\BookingResource\Pages\ListBookings()));
        $this->assertFalse(\App\Support\CleanUi::appliesTo(new \App\Filament\Resources\CustomerResource\Pages\ListCustomers()));
        $this->assertFalse(\App\Support\CleanUi::appliesTo(null));
    }

    public function test_quotation_view_edit_and_create_pages_render(): void
    {
        Livewire::test(ViewQuotation::class, ['record' => $this->new->getKey()])->assertSuccessful();
        Livewire::test(EditQuotation::class, ['record' => $this->new->getKey()])->assertSuccessful();
        Livewire::test(CreateQuotation::class)->assertSuccessful();
    }
}
