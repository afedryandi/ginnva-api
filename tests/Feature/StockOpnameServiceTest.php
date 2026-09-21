<?php

namespace Tests\Feature;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Services\StockOpnameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * "Stok Opname" -- keputusan atasan 2026-09-19 (Topik 4, Fase 1,
 * "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"). Lihat StockOpnameService.
 */
class StockOpnameServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRawMaterial(float $stock): RawMaterial
    {
        return RawMaterial::create([
            'name' => 'Adhesive Test',
            'code' => 'RM-TEST-' . uniqid(),
            'category' => 'chemical',
            'unit' => 'liter',
            'current_stock' => $stock,
        ]);
    }

    private function makeConsumableItem(float $stock): ConsumableItem
    {
        return ConsumableItem::create([
            'name' => 'Sarung Tangan',
            'code' => 'CI-TEST-' . uniqid(),
            'category' => 'consumable',
            'unit' => 'pcs',
            'current_stock' => $stock,
        ]);
    }

    public function test_create_session_adjusts_multiple_items_and_snapshots_delta(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $material = $this->makeRawMaterial(10);
        $consumable = $this->makeConsumableItem(20);

        $opname = app(StockOpnameService::class)->create(
            $store->id,
            now()->toDateString(),
            'Opname bulanan',
            [
                ['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 8],
                ['item_type' => 'consumable_item', 'item_id' => $consumable->id, 'actual_quantity' => 25],
            ],
            null
        );

        $this->assertEquals($store->id, $opname->store_id);
        $this->assertCount(2, $opname->items);
        $this->assertEquals(8, (float) $material->fresh()->current_stock);
        $this->assertEquals(25, (float) $consumable->fresh()->current_stock);

        $materialItem = $opname->items->firstWhere('item_type', 'raw_material');
        $this->assertEquals(10, (float) $materialItem->system_quantity);
        $this->assertEquals(8, (float) $materialItem->actual_quantity);
        $this->assertEquals(-2, (float) $materialItem->delta);
    }

    public function test_movements_created_are_tagged_with_session_store(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $material = $this->makeRawMaterial(10);

        app(StockOpnameService::class)->create(
            $store->id,
            now()->toDateString(),
            null,
            [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 15]],
            null
        );

        $movement = $material->fresh()->movements()->where('type', 'adjustment')->first();
        $this->assertEquals($store->id, $movement->store_id);
    }

    public function test_create_rejected_when_items_empty(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);

        $this->expectException(RuntimeException::class);

        app(StockOpnameService::class)->create($store->id, now()->toDateString(), null, [], null);
    }

    public function test_item_still_recorded_even_when_no_discrepancy_found(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $material = $this->makeRawMaterial(10);

        $opname = app(StockOpnameService::class)->create(
            $store->id,
            now()->toDateString(),
            null,
            [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 10]],
            null
        );

        $this->assertCount(1, $opname->items);
        $this->assertEquals(0, (float) $opname->items->first()->delta);
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
    }

    public function test_opname_number_format_and_uniqueness(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $material = $this->makeRawMaterial(10);
        $service = app(StockOpnameService::class);

        $opname1 = $service->create($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9]], null);
        $opname2 = $service->create($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 8]], null);

        $this->assertStringStartsWith('OPN-' . now()->format('Ymd'), $opname1->opname_number);
        $this->assertNotEquals($opname1->opname_number, $opname2->opname_number);
    }
}
