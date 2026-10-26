<?php

namespace Tests\Feature;

use App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget;
use App\Filament\InventoryWidgets\MaterialsNeedingAttentionWidget;
use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\RestockRequestService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pemasok utama per barang dan tombol "Buat Permintaan Beli" dari peringatan stok menipis (audit ACCURATE 5).
 */
class RestockRequestTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->admin = tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($this->admin, 'web');
    }

    private function material(float $stock = 2, ?float $reorder = 10, ?Supplier $supplier = null): RawMaterial
    {
        return RawMaterial::create([
            'name' => 'Lem Uji', 'code' => 'RM-' . strtoupper(uniqid()), 'category' => 'chemical', 'unit' => 'liter',
            'current_stock' => $stock, 'reorder_point' => $reorder, 'preferred_supplier_id' => $supplier?->id,
        ]);
    }

    private function consumable(float $stock = 1, ?float $reorder = 5): ConsumableItem
    {
        return ConsumableItem::create([
            'name' => 'Sarung Tangan', 'code' => 'CI-' . strtoupper(uniqid()), 'category' => 'perlengkapan', 'unit' => 'box',
            'current_stock' => $stock, 'reorder_point' => $reorder,
        ]);
    }

    public function test_the_suggested_quantity_is_the_gap_to_the_threshold_and_at_least_one(): void
    {
        $service = app(RestockRequestService::class);

        $this->assertSame(8.0, $service->suggestedQuantity($this->material(2, 10)));
        $this->assertSame(1.0, $service->suggestedQuantity($this->material(10, 10)));
    }

    public function test_it_creates_a_pending_request_that_names_the_preferred_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'PT Kimia Jaya', 'is_active' => true]);
        $material = $this->material(2, 10, $supplier);

        $request = app(RestockRequestService::class)->create($material, $this->store->id, 8, 'Mendesak', $this->admin);

        $this->assertSame('pending', $request->status);
        $this->assertSame('raw_material', $request->item_type);
        $this->assertSame($material->id, $request->item_id);
        $this->assertSame('Lem Uji', $request->item_name);
        $this->assertSame('liter', $request->unit);
        $this->assertSame('8.00', (string) $request->quantity);
        $this->assertSame($this->admin->id, $request->requested_by);
        $this->assertStringContainsString('Pemasok utama: PT Kimia Jaya', $request->reason);
        $this->assertStringContainsString('Mendesak', $request->reason);
        $this->assertNotEmpty($request->request_number);
    }

    public function test_an_item_cannot_have_two_open_requests(): void
    {
        $material = $this->material();
        $service = app(RestockRequestService::class);
        $first = $service->create($material, $this->store->id, 5, null, $this->admin);

        $this->assertSame($first->id, $service->openRequest($material)->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($first->request_number);
        $service->create($material, $this->store->id, 5, null, $this->admin);
    }

    public function test_a_fulfilled_or_rejected_request_no_longer_blocks_a_new_one(): void
    {
        $material = $this->material();
        $service = app(RestockRequestService::class);
        $first = $service->create($material, $this->store->id, 5, null, $this->admin);
        $first->update(['status' => 'rejected']);

        $this->assertNull($service->openRequest($material));
        $this->assertNotSame($first->id, $service->create($material, $this->store->id, 5, null, $this->admin)->id);
    }

    public function test_a_zero_quantity_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        app(RestockRequestService::class)->create($this->material(), $this->store->id, 0, null, $this->admin);
    }

    public function test_the_raw_material_widget_button_creates_the_request_and_then_disappears(): void
    {
        $material = $this->material();

        Livewire::test(MaterialsNeedingAttentionWidget::class)
            ->assertTableActionVisible('requestPurchase', $material)
            ->callTableAction('requestPurchase', $material, data: ['store_id' => $this->store->id, 'quantity' => 8, 'note' => 'Dari dashboard'])
            ->assertHasNoTableActionErrors();

        $request = PurchaseRequest::where('item_id', $material->id)->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertSame($this->store->id, $request->store_id);

        Livewire::test(MaterialsNeedingAttentionWidget::class)->assertTableActionHidden('requestPurchase', $material);
    }

    public function test_the_consumable_widget_button_works_the_same_way(): void
    {
        $item = $this->consumable();

        Livewire::test(ConsumablesNeedingAttentionWidget::class)
            ->callTableAction('requestPurchase', $item, data: ['store_id' => $this->store->id, 'quantity' => 4])
            ->assertHasNoTableActionErrors();

        $this->assertSame('consumable_item', PurchaseRequest::where('item_id', $item->id)->firstOrFail()->item_type);
    }

    public function test_the_button_is_hidden_for_an_item_that_is_only_flagged_for_other_reasons(): void
    {
        $material = $this->material(50, 10);
        $material->forceFill(['updated_at' => now()->subDays(90)])->saveQuietly();

        Livewire::test(MaterialsNeedingAttentionWidget::class)->assertTableActionHidden('requestPurchase', $material);
    }

    public function test_the_preferred_supplier_is_a_relation_and_clears_when_the_supplier_is_deleted(): void
    {
        $supplier = Supplier::create(['name' => 'PT Sementara', 'is_active' => true]);
        $material = $this->material(2, 10, $supplier);

        $this->assertSame('PT Sementara', $material->preferredSupplier->name);

        $supplier->delete();

        $this->assertNull($material->fresh()->preferredSupplier);
    }
}
