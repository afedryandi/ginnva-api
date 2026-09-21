<?php

namespace Tests\Feature;

use App\Models\RawMaterial;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit framework 2026-09-14, "Cakupan automated testing" -- model
 * movement stok (RawMaterial::adjustStock / consumeBatchesFifo) belum
 * punya test otomatis sama sekali sebelum ini.
 */
class RawMaterialStockTest extends TestCase
{
    use RefreshDatabase;

    private function makeMaterial(float $stock = 0): RawMaterial
    {
        return RawMaterial::create([
            'name' => 'Adhesive Test',
            'code' => 'RM-TEST-' . uniqid(),
            'category' => 'chemical',
            'unit' => 'liter',
            'current_stock' => $stock,
        ]);
    }

    public function test_positive_adjustment_creates_unknown_origin_batch(): void
    {
        $material = $this->makeMaterial(stock: 0);

        $movement = $material->adjustStock(10, null, 'Stok awal');

        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $this->assertEquals(10, (float) $material->batches()->sum('quantity'));
        $this->assertEquals('adjustment', $movement->type);
        $this->assertEquals(10, (float) $movement->quantity);
    }

    public function test_negative_adjustment_consumes_oldest_batch_first(): void
    {
        $material = $this->makeMaterial(stock: 0);
        $material->batches()->create(['quantity' => 5, 'received_date' => now()->subDays(10)->toDateString()]);
        $material->batches()->create(['quantity' => 5, 'received_date' => now()->subDays(1)->toDateString()]);
        $material->update(['current_stock' => 10]);

        $material->adjustStock(7, null, 'Pemakaian');

        $batches = $material->batches()->orderBy('received_date')->get();
        $this->assertEquals(3, (float) $material->fresh()->current_stock);
        $this->assertEquals(0, (float) $batches[0]->quantity, 'Batch tertua harus habis duluan (FIFO).');
        $this->assertEquals(2, (float) $batches[1]->quantity, 'Sisa 2 dari 7 yang diambil, dari batch termuda.');
    }

    public function test_adjustment_smaller_than_rounding_threshold_creates_no_movement(): void
    {
        $material = $this->makeMaterial(stock: 10);

        $movement = $material->adjustStock(10.005, null);

        $this->assertNull($movement, 'Selisih < 0.01 dianggap sama, tidak perlu movement baru.');
    }

    public function test_current_stock_never_goes_negative_from_fifo_consumption_alone(): void
    {
        // consumeBatchesFifo() SENGAJA membiarkan sisa yang tidak
        // tertutup batch (data lama tanpa batch matching) -- current_stock
        // tetap sumber kebenaran utama, bukan jumlah batch.
        $material = $this->makeMaterial(stock: 10);
        $material->batches()->create(['quantity' => 3, 'received_date' => now()->toDateString()]);

        $material->adjustStock(2, null);

        $this->assertEquals(2, (float) $material->fresh()->current_stock);
        $this->assertEquals(0, (float) $material->batches()->sum('quantity'));
    }

    /**
     * Topik 4, Fase 1 (2026-09-19), "penanda cabang pada pencatatan
     * pemakaian bahan" -- store_id OPSIONAL, current_stock TETAP
     * nasional (tidak dipecah), cuma movement-nya yang ditandai.
     */
    public function test_record_movement_tags_store_id_without_splitting_stock(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $material = $this->makeMaterial(stock: 10);

        $movement = $material->recordMovement('out', 3, null, 'Dipakai toko', storeId: $store->id);

        $this->assertEquals($store->id, $movement->store_id);
        $this->assertEquals(7, (float) $material->fresh()->current_stock, 'Stok tetap 1 angka nasional, bukan per-cabang.');
    }

    public function test_movement_without_store_id_still_defaults_to_null(): void
    {
        $material = $this->makeMaterial(stock: 10);

        $movement = $material->recordMovement('out', 3, null, 'Tanpa penanda cabang');

        $this->assertNull($movement->store_id);
    }

    public function test_reverse_last_movement_inherits_store_id_of_original(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $material = $this->makeMaterial(stock: 10);
        $movement = $material->recordMovement('out', 3, null, null, storeId: $store->id);

        $material->reverseLastMovement($movement, null);

        $correction = $material->movements()->where('type', 'correction')->first();
        $this->assertEquals($store->id, $correction->store_id);
    }
}
