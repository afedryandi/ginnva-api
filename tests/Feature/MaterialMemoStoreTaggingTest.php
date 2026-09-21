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
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $material = RawMaterial::create([
            'name' => 'Adhesive Test',
            'code' => 'RM-TEST-' . uniqid(),
            'category' => 'chemical',
            'unit' => 'liter',
            'current_stock' => 10,
        ]);
        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-TEST-' . uniqid(),
            'store_id' => $store->id,
        ]);

        MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 2, 1, null);

        $movement = $material->fresh()->movements()->where('type', 'out')->first();
        $this->assertEquals($store->id, $movement->store_id);
        $this->assertEquals(8, (float) $material->fresh()->current_stock, 'Stok tetap nasional, cuma movement-nya ditandai.');
    }
}
