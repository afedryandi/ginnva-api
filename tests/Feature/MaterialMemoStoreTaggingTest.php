<?php

namespace Tests\Feature;

use App\Models\MaterialMemo;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Services\MaterialMemoStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Penanda cabang pada pencatatan pemakaian bahan" -- keputusan atasan
 * 2026-09-19 (Topik 4, Fase 1). MaterialMemoStockService::addMaterial()
 * adalah SATU-SATUNYA event "pemakaian bahan" per keputusan ini --
 * lihat catatan lengkap di service tsb.
 */
class MaterialMemoStoreTaggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_taking_material_via_memo_tags_movement_with_memo_store(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $material = RawMaterial::create([
            'name' => 'Adhesive Test',
            'code' => 'RM-TEST-' . uniqid(),
            'category' => 'chemical',
            'unit' => 'liter',
            'current_stock' => 10,
        ]);
        $staff = \App\Models\User::create(['name' => 'Staff', 'email' => uniqid() . '@test.local', 'password' => 'x']);
        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-TEST-' . uniqid(),
            'created_by' => $staff->id,
            'store_id' => $store->id,
        ]);

        MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 2, $staff->id, null);

        $movement = $material->fresh()->movements()->where('type', 'out')->first();
        $this->assertEquals($store->id, $movement->store_id);
        $this->assertEquals(8, (float) $material->fresh()->current_stock, 'Stok tetap nasional, cuma movement-nya ditandai.');
    }

    public function test_corrections_returns_and_removals_carry_the_memo_store_and_a_returned_batch_can_keep_its_expiry(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $material = RawMaterial::create(['name' => 'Adhesive Test', 'code' => 'RM-TEST-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 20]);
        $consumable = \App\Models\ConsumableItem::create(['name' => 'Lakban Test', 'code' => 'BHP-TEST-' . uniqid(), 'category' => 'umum', 'unit' => 'pcs', 'current_stock' => 10]);
        $staff = \App\Models\User::create(['name' => 'Staff', 'email' => uniqid() . '@test.local', 'password' => 'x']);
        $memo = MaterialMemo::create(['memo_number' => 'MEMO-TEST-' . uniqid(), 'created_by' => $staff->id, 'store_id' => $store->id]);

        $item = MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 5, $staff->id, null);
        MaterialMemoStockService::updateMaterialQty($item->fresh(), 7, $staff->id, $memo);   // keluar 2 lagi
        MaterialMemoStockService::updateMaterialQty($item->fresh(), 6, $staff->id, $memo);   // masuk 1
        MaterialMemoStockService::returnMaterial($item->fresh(), 2, $staff->id, $memo, null, '2027-03-01');

        // Diperiksa sebelum pengambilan berikutnya: stok keluar memakai FIFO dan menghabiskan batch tertua lebih dulu.
        $this->assertEquals(2, (float) $material->batches()->whereDate('expiry_date', '2027-03-01')->sum('quantity'), 'Batch hasil pengembalian membawa kedaluwarsa yang diisi.');

        $second = MaterialMemoStockService::addMaterial($material->fresh(), 'raw_material', $memo, 3, $staff->id, null);
        MaterialMemoStockService::reverseItem($second->fresh(), $staff->id, $memo);

        $this->assertSame(0, $material->movements()->whereNull('store_id')->count(), 'Semua pergerakan memo bahan baku ditandai cabang memo.');
        $this->assertSame(6, $material->movements()->count());
        $this->assertEquals(16, (float) $material->fresh()->current_stock);

        $consumableItem = MaterialMemoStockService::addMaterial($consumable, 'consumable_item', $memo, 4, $staff->id, null);
        MaterialMemoStockService::returnMaterial($consumableItem->fresh(), 1, $staff->id, $memo, null, '2027-03-01');
        $this->assertSame(0, $consumable->movements()->whereNull('store_id')->count());
        $this->assertEquals(7, (float) $consumable->fresh()->current_stock, 'Tanggal kedaluwarsa diabaikan untuk barang habis pakai.');
    }
}
