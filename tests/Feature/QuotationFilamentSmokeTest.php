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

    public function test_quotation_list_renders_and_status_filter_works(): void
    {
        Livewire::test(ListQuotations::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->new, $this->contacted])
            ->filterTable('status', 'new')
            ->assertCanSeeTableRecords([$this->new])
            ->assertCanNotSeeTableRecords([$this->contacted]);
    }

    public function test_quotation_view_edit_and_create_pages_render(): void
    {
        Livewire::test(ViewQuotation::class, ['record' => $this->new->getKey()])->assertSuccessful();
        Livewire::test(EditQuotation::class, ['record' => $this->new->getKey()])->assertSuccessful();
        Livewire::test(CreateQuotation::class)->assertSuccessful();
    }
}
