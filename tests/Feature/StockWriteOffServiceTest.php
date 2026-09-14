<?php

namespace Tests\Feature;

use App\Models\RawMaterial;
use App\Services\StockWriteOffService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit framework 2026-09-14, "Cakupan automated testing" -- sekaligus
 * regression-test untuk bug akun 6520 yang belum ada di
 * ChartOfAccountSeeder (ditemukan saat menyiapkan test ini) dan
 * perbaikan race condition "Integritas transaksi finansial" (baca
 * stok setelah lockForUpdate, bukan sebelum).
 */
class StockWriteOffServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    private function makeRawMaterial(float $stock, float $unitCost): RawMaterial
    {
        return RawMaterial::create([
            'name' => 'Adhesive Test',
            'code' => 'RM-TEST-' . uniqid(),
            'category' => 'chemical',
            'unit' => 'liter',
            'current_stock' => $stock,
            'unit_cost' => $unitCost,
        ]);
    }

    public function test_record_reduces_stock_and_posts_loss_journal(): void
    {
        $material = $this->makeRawMaterial(stock: 20, unitCost: 50_000);

        $writeOff = app(StockWriteOffService::class)->record($material, 'raw_material', 5, 'damaged', 'Tumpah', null);

        $this->assertEquals(15, (float) $material->fresh()->current_stock);
        $this->assertEquals(250_000, (float) $writeOff->total_value);
        $this->assertNotNull($writeOff->journal_entry_id);
        $this->assertEquals(250_000, (float) $writeOff->journalEntry->lines()->sum('debit'));
    }

    public function test_record_skips_journal_when_unit_cost_not_set(): void
    {
        $material = $this->makeRawMaterial(stock: 10, unitCost: 0);
        $material->update(['unit_cost' => null]);

        $writeOff = app(StockWriteOffService::class)->record($material, 'raw_material', 3, 'expired', null, null);

        $this->assertNull($writeOff->journal_entry_id);
        $this->assertNull($writeOff->total_value);
        $this->assertEquals(7, (float) $material->fresh()->current_stock);
    }

    public function test_record_rejects_quantity_exceeding_current_stock(): void
    {
        $material = $this->makeRawMaterial(stock: 5, unitCost: 10_000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/melebihi stok saat ini/');

        app(StockWriteOffService::class)->record($material, 'raw_material', 10, 'damaged', null, null);
    }

    public function test_record_rejects_zero_or_negative_quantity(): void
    {
        $material = $this->makeRawMaterial(stock: 5, unitCost: 10_000);

        $this->expectException(RuntimeException::class);

        app(StockWriteOffService::class)->record($material, 'raw_material', 0, 'damaged', null, null);
    }

    public function test_record_rejects_unknown_reason(): void
    {
        $material = $this->makeRawMaterial(stock: 5, unitCost: 10_000);

        $this->expectException(RuntimeException::class);

        app(StockWriteOffService::class)->record($material, 'raw_material', 1, 'alasan_tidak_ada', null, null);
    }
}
