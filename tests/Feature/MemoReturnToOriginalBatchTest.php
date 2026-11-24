<?php

namespace Tests\Feature;

use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\MaterialMemoStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bahan baku yang dikembalikan lewat memo masuk lagi ke batch asalnya, bukan membuka Batch Penerimaan baru (2026-11-24).
 */
class MemoReturnToOriginalBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_returned_raw_material_goes_back_to_the_batches_it_was_drawn_from(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $user = User::create(['name' => 'Gudang', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'is_active' => true]);
        $material = RawMaterial::create(['name' => 'Cairan Slip', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 0]);

        $material->recordMovement('in', 3, $user->id, null, '2026-01-01');
        $material->recordMovement('in', 5, $user->id, null, '2026-02-01');
        $batchesBefore = StockBatch::count();
        [$older, $newer] = $material->batches()->orderBy('received_date')->get()->all();

        $memo = MaterialMemo::create(['memo_number' => 'MEMO-' . uniqid(), 'store_id' => $store->id, 'created_by' => $user->id, 'vehicle_info' => 'Innova']);
        MaterialMemoStockService::addMaterial($material->fresh(), 'raw_material', $memo, 4, $user->id, null);

        // FIFO: 3 dari batch lama (habis), 1 dari batch baru.
        $this->assertEquals(0.0, (float) $older->fresh()->quantity);
        $this->assertEquals(4.0, (float) $newer->fresh()->quantity);

        $item = MaterialMemoItem::where('material_memo_id', $memo->id)->firstOrFail();
        MaterialMemoStockService::returnMaterial($item, 2, $user->id, $memo);

        // Yang terakhir terpakai dipulihkan duluan: 1 ke batch baru, 1 ke batch lama. Tidak ada batch/BTH baru.
        $this->assertEquals(5.0, (float) $newer->fresh()->quantity);
        $this->assertEquals(1.0, (float) $older->fresh()->quantity);
        $this->assertSame(2, $material->batches()->count());
        $this->assertSame($batchesBefore, StockBatch::count());
        $this->assertEquals(6.0, (float) $material->fresh()->current_stock);
    }

    public function test_return_without_allocation_history_goes_to_the_oldest_batch(): void
    {
        $material = RawMaterial::create(['name' => 'Cairan Lama', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 0]);
        $material->recordMovement('in', 5, null, null, '2026-01-01');
        $material->recordMovement('in', 5, null, null, '2026-02-01');
        $batchesBefore = StockBatch::count();

        $material->returnToOriginalBatches(2, null, 'tes', null, 999);

        $this->assertEquals(7.0, (float) $material->batches()->orderBy('received_date')->first()->quantity);
        $this->assertSame($batchesBefore, StockBatch::count());
        $this->assertEquals(12.0, (float) $material->fresh()->current_stock);
    }
}
