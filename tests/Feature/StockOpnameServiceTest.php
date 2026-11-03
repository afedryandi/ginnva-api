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
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
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
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
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
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);

        $this->expectException(RuntimeException::class);

        app(StockOpnameService::class)->create($store->id, now()->toDateString(), null, [], null);
    }

    public function test_item_still_recorded_even_when_no_discrepancy_found(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
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
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $material = $this->makeRawMaterial(10);
        $service = app(StockOpnameService::class);

        $opname1 = $service->create($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9]], null);
        $opname2 = $service->create($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 8]], null);

        $this->assertStringStartsWith('OPN-' . now()->format('Ymd'), $opname1->opname_number);
        $this->assertNotEquals($opname1->opname_number, $opname2->opname_number);
    }
    // ------------------------------------------------------------- audit 2026-11-03: alur dua tahap

    private function storeForOpname(): Store
    {
        return Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test ' . uniqid(), 'is_active' => true]);
    }

    public function test_submit_saves_a_draft_without_touching_stock_and_apply_adjusts_it(): void
    {
        $store = $this->storeForOpname();
        $material = $this->makeRawMaterial(10);
        $service = app(StockOpnameService::class);

        $draft = $service->submit($store->id, now()->toDateString(), 'Opname rak A', [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 7, 'note' => 'tumpah']], null);

        $this->assertSame('draft', $draft->status);
        $this->assertEquals(10, (float) $material->fresh()->current_stock, 'Stok belum berubah.');
        $this->assertSame(0, $material->movements()->count());
        $item = $draft->items->first();
        $this->assertEquals([10.0, 7.0, -3.0], [(float) $item->system_quantity, (float) $item->actual_quantity, (float) $item->delta]);
        $this->assertSame('tumpah', $item->note);

        $applied = $service->apply($draft, null);

        $this->assertSame('applied', $applied->status);
        $this->assertEquals(7, (float) $material->fresh()->current_stock);
        $movement = $material->movements()->where('type', 'adjustment')->firstOrFail();
        $this->assertSame(['stock_opname', $draft->id], [$movement->source_type, $movement->source_id]);
        $this->assertEquals(10, (float) $applied->items->first()->applied_system_quantity);

        // Tidak bisa diterapkan dua kali.
        $this->expectException(RuntimeException::class);
        $service->apply($draft, null);
    }

    public function test_apply_keeps_the_counted_difference_even_when_stock_moved_after_counting(): void
    {
        $store = $this->storeForOpname();
        $material = $this->makeRawMaterial(10);
        $service = app(StockOpnameService::class);

        // Dihitung: sistem 10, fisik 8 (selisih -2). Sebelum diterapkan ada pemakaian 3, jadi stok sistem jadi 7.
        $draft = $service->submit($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 8]], null);
        $material->fresh()->recordMovement('out', 3, null, 'Pakai setelah dihitung');
        $this->assertEquals(7, (float) $material->fresh()->current_stock);

        $service->apply($draft, null);

        $this->assertEquals(5, (float) $material->fresh()->current_stock, 'Selisih -2 diterapkan pada stok terkini (7 -> 5), bukan menimpa ke 8.');
    }

    public function test_discarding_a_draft_leaves_stock_alone_and_blocks_applying(): void
    {
        $store = $this->storeForOpname();
        $material = $this->makeRawMaterial(10);
        $service = app(StockOpnameService::class);
        $draft = $service->submit($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 1]], null);

        try {
            $service->discard($draft, null, '  ');
            $this->fail('Alasan wajib.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('alasan', $e->getMessage());
        }

        $discarded = $service->discard($draft, null, 'Salah toko');
        $this->assertSame(['cancelled', 'Salah toko'], [$discarded->status, $discarded->cancel_reason]);
        $this->assertEquals(10, (float) $material->fresh()->current_stock);

        $this->expectException(RuntimeException::class);
        $service->apply($draft, null);
    }

    public function test_a_large_difference_needs_a_note_and_a_future_date_is_refused(): void
    {
        $store = $this->storeForOpname();
        $material = $this->makeRawMaterial(10);
        $material->update(['adjustment_note_threshold' => 2]);
        $service = app(StockOpnameService::class);

        try {
            $service->submit($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 5]], null);
            $this->fail('Selisih besar tanpa catatan harus ditolak.');
        } catch (\App\Services\OpnameNeedsNoteException $e) {
            $this->assertSame($material->id, $e->problems[0]['item_id']);
        }
        $this->assertSame(0, \App\Models\StockOpname::withoutGlobalScopes()->count(), 'Sesi tidak setengah tersimpan.');

        $ok = $service->submit($store->id, now()->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 5, 'note' => 'Tumpah']], null);
        $this->assertSame('Tumpah', $ok->items->first()->note);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('masa depan');
        $service->submit($store->id, now()->addDays(2)->toDateString(), null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 5]], null);
    }

    public function test_apply_fails_when_an_item_was_deleted_after_counting(): void
    {
        $store = $this->storeForOpname();
        $kept = $this->makeRawMaterial(10);
        $gone = $this->makeRawMaterial(4);
        $service = app(StockOpnameService::class);
        $draft = $service->submit($store->id, now()->toDateString(), null, [
            ['item_type' => 'raw_material', 'item_id' => $kept->id, 'actual_quantity' => 9],
            ['item_type' => 'raw_material', 'item_id' => $gone->id, 'actual_quantity' => 3],
        ], null);
        $gone->delete();

        try {
            $service->apply($draft, null);
            $this->fail('Item terhapus harus menggagalkan penerapan.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dihapus', $e->getMessage());
        }

        $this->assertEquals(10, (float) $kept->fresh()->current_stock, 'Penerapan dibatalkan seluruhnya.');
        $this->assertSame('draft', $draft->fresh()->status);
    }
}
