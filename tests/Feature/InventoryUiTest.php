<?php

namespace Tests\Feature;

use App\Filament\Pages\InventoryDashboard;
use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Filament\Resources\InventoryMovementResource\Pages\ListInventoryMovements;
use App\Filament\Resources\MaterialMemoResource\Pages\ListMaterialMemos;
use App\Filament\Resources\PurchaseRequestResource\Pages\ListPurchaseRequests;
use App\Filament\Resources\RawMaterialMovementResource\Pages\ListRawMaterialMovements;
use App\Filament\Resources\RawMaterialResource\Pages\ListRawMaterials;
use App\Filament\Resources\StockOpnameResource\Pages\ListStockOpnames;
use App\Filament\Resources\StockWriteOffResource\Pages\ListStockWriteOffs;
use App\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use App\Models\Store;
use App\Models\User;
use App\Support\CleanUi;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Perombakan tampilan menu Inventaris: gaya bersih terpasang, filter lipat di atas daftar, tab status Permohonan Pembelian. */
class InventoryUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    public function test_the_clean_ui_applies_to_every_redesigned_inventory_screen(): void
    {
        foreach ([
            new ListInventoryItems(), new ListRawMaterials(), new ListMaterialMemos(), new ListPurchaseRequests(), new ListSuppliers(),
            new ListInventoryMovements(), new ListRawMaterialMovements(), new ListStockWriteOffs(), new ListStockOpnames(), new InventoryDashboard(),
        ] as $page) {
            $this->assertTrue(CleanUi::appliesTo($page), $page::class);
        }
    }

    public function test_filters_are_folded_above_the_list_by_default_on_clean_ui_screens(): void
    {
        $this->admin();

        foreach ([ListSuppliers::class, ListStockOpnames::class, ListRawMaterialMovements::class] as $list) {
            $table = Livewire::test($list)->assertSuccessful()->instance()->getTable();

            $this->assertSame(FiltersLayout::AboveContentCollapsible, $table->getFiltersLayout(), $list);
        }
    }

    public function test_purchase_request_tabs_show_everything_by_default_and_split_by_status(): void
    {
        $this->admin();

        $component = Livewire::test(ListPurchaseRequests::class)->assertSuccessful();

        $this->assertSame('semua', $component->instance()->getDefaultActiveTab());
        $this->assertSame(['semua', 'menunggu', 'disetujui', 'selesai', 'ditolak'], array_keys($component->instance()->getTabs()));
    }
}
