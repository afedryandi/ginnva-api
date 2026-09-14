<?php

namespace Tests\Feature;

use App\Models\RawMaterial;
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
}
