<?php

namespace Tests\Feature;

use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Mutasi Roll Film antar cabang" -- keputusan atasan 2026-09-19 (Topik
 * 4, Fase 1, "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"). Lihat
 * ScrollCode::transferTo().
 */
class ScrollCodeTransferTest extends TestCase
{
    use RefreshDatabase;

    private function makeAllocatedScrollCode(int $storeId): ScrollCode
    {
        $product = FilmProduct::create([
            'sku' => 'WF-TEST',
            'name' => 'Produk Test',
            'product_type' => 'window_film',
            'position' => 'front',
            'base_price' => 100_000,
            'is_active' => true,
        ]);

        return ScrollCode::create([
            'code' => 'ROLL-TEST-' . uniqid(),
            'film_product_id' => $product->id,
            'store_id' => $storeId,
            'status' => 'allocated',
            'usage_count' => 0,
            'allocated_at' => now(),
        ]);
    }

    public function test_transfer_moves_store_and_creates_history_row(): void
    {
        $storeA = Store::create(['name' => 'Toko A', 'is_active' => true]);
        $storeB = Store::create(['name' => 'Toko B', 'is_active' => true]);
        $scrollCode = $this->makeAllocatedScrollCode($storeA->id);

        $transfer = $scrollCode->transferTo($storeB->id, 'Toko A kelebihan stok', null);

        $this->assertEquals($storeA->id, $transfer->from_store_id);
        $this->assertEquals($storeB->id, $transfer->to_store_id);
        $this->assertEquals($storeB->id, $scrollCode->fresh()->store_id);
        $this->assertCount(1, $scrollCode->fresh()->transfers);
    }

    public function test_transfer_rejected_when_status_is_not_allocated(): void
    {
        $storeA = Store::create(['name' => 'Toko A', 'is_active' => true]);
        $storeB = Store::create(['name' => 'Toko B', 'is_active' => true]);
        $scrollCode = $this->makeAllocatedScrollCode($storeA->id);
        $scrollCode->update(['status' => 'used']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Dialokasi/');

        $scrollCode->transferTo($storeB->id, 'alasan', null);
    }

    public function test_transfer_rejected_when_destination_same_as_origin(): void
    {
        $storeA = Store::create(['name' => 'Toko A', 'is_active' => true]);
        $scrollCode = $this->makeAllocatedScrollCode($storeA->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sama dengan toko asal/');

        $scrollCode->transferTo($storeA->id, 'alasan', null);
    }

    public function test_multiple_transfers_accumulate_in_history(): void
    {
        $storeA = Store::create(['name' => 'Toko A', 'is_active' => true]);
        $storeB = Store::create(['name' => 'Toko B', 'is_active' => true]);
        $storeC = Store::create(['name' => 'Toko C', 'is_active' => true]);
        $scrollCode = $this->makeAllocatedScrollCode($storeA->id);

        $scrollCode->transferTo($storeB->id, 'pertama', null);
        $scrollCode->fresh()->transferTo($storeC->id, 'kedua', null);

        $this->assertEquals($storeC->id, $scrollCode->fresh()->store_id);
        $this->assertCount(2, $scrollCode->fresh()->transfers);
    }
}
