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
    // ------------------------------------------------------------- audit 2026-11-03

    public function test_a_note_is_required_for_lost_and_other_only(): void
    {
        $material = $this->makeRawMaterial(stock: 10, unitCost: 1_000);
        $service = app(StockWriteOffService::class);

        foreach (['lost', 'other'] as $reason) {
            try {
                $service->record($material->fresh(), 'raw_material', 1, $reason, '  ', null);
                $this->fail("Alasan {$reason} tanpa catatan harus ditolak.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Catatan wajib', $e->getMessage());
            }
        }

        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $service->record($material->fresh(), 'raw_material', 1, 'damaged', null, null);
        $service->record($material->fresh(), 'raw_material', 1, 'lost', 'Hilang saat dipindah', null);
        $this->assertEquals(8, (float) $material->fresh()->current_stock);
    }

    public function test_expired_write_off_takes_the_expired_batch_first_and_values_it_by_batch_cost(): void
    {
        $material = $this->makeRawMaterial(stock: 0, unitCost: 500);
        $old = $material->batches()->create(['quantity' => 5, 'received_date' => '2026-01-01', 'unit_cost' => 100]);
        $expired = $material->batches()->create(['quantity' => 5, 'received_date' => '2026-06-01', 'expiry_date' => '2026-06-30', 'unit_cost' => 200]);
        $material->update(['current_stock' => 10]);

        $writeOff = app(StockWriteOffService::class)->record($material->fresh(), 'raw_material', 3, 'expired', null, null);

        $this->assertEquals(2, (float) $expired->fresh()->quantity, 'Batch yang sudah kedaluwarsa dikurangi lebih dulu, walau lebih baru masuknya.');
        $this->assertEquals(5, (float) $old->fresh()->quantity);
        $this->assertEquals(200, (float) $writeOff->unit_cost);
        $this->assertEquals(600, (float) $writeOff->total_value, 'Nilai dari biaya batch (200), bukan harga terakhir bahan (500).');

        // Alasan lain tetap FIFO biasa (batch tertua dulu) dengan biaya batch tertua.
        $other = app(StockWriteOffService::class)->record($material->fresh(), 'raw_material', 2, 'damaged', null, null);
        $this->assertEquals(3, (float) $old->fresh()->quantity);
        $this->assertEquals(100, (float) $other->unit_cost);
    }

    public function test_cancelling_restores_stock_and_batch_reverses_the_journal_and_hides_the_movement(): void
    {
        $material = $this->makeRawMaterial(stock: 0, unitCost: 100);
        $material->batches()->create(['quantity' => 10, 'received_date' => '2026-01-01', 'unit_cost' => 100]);
        $material->update(['current_stock' => 10]);
        $service = app(StockWriteOffService::class);

        $writeOff = $service->record($material->fresh(), 'raw_material', 4, 'damaged', 'Tumpah', null);
        $this->assertEquals(6, (float) $material->fresh()->current_stock);
        $this->assertNotNull($writeOff->journal_entry_id);

        $cancelled = $service->cancel($writeOff, null, 'Salah barang');

        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $this->assertEquals(10, (float) $material->batches()->sum('quantity'), 'Total batch kembali sama dengan stok.');
        $this->assertTrue((bool) $material->batches()->where('is_restoration', true)->exists());
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Salah barang', $cancelled->cancelled_reason);
        $this->assertNotNull($cancelled->reversal_journal_entry_id);
        $this->assertEquals(400, (float) $cancelled->reversalJournalEntry->lines()->sum('debit'));

        // Pergerakan stoknya tersembunyi dari kueri bawaan dan ada jejak Koreksi.
        $this->assertSame(0, \App\Models\RawMaterialMovement::where('type', 'adjustment')->count());
        $this->assertSame(1, \App\Models\RawMaterialMovement::withReversed()->where('type', 'adjustment')->whereNotNull('reversed_at')->count());
        $this->assertSame(1, $material->movements()->where('type', 'correction')->count());

        // Baris dibatalkan hilang dari kueri bawaan (ringkasan), tapi tetap ada untuk menu Stok Terbuang.
        $this->assertSame(0, \App\Models\StockWriteOff::query()->count());
        $this->assertSame(1, \App\Models\StockWriteOff::withoutGlobalScope('effective')->count());

        // Tidak bisa dibatalkan dua kali, dan alasan wajib.
        try {
            $service->cancel($cancelled, null, 'Lagi');
            $this->fail('Pembatalan kedua harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dibatalkan', $e->getMessage());
        }
        $other = $service->record($material->fresh(), 'raw_material', 1, 'damaged', null, null);
        $this->expectException(RuntimeException::class);
        $service->cancel($other, null, '  ');
    }

    public function test_cancelling_a_consumable_write_off_and_one_whose_item_was_deleted(): void
    {
        $item = \App\Models\ConsumableItem::create(['name' => 'Lakban', 'code' => 'CI-WO', 'category' => 'umum', 'unit' => 'roll', 'current_stock' => 10, 'unit_cost' => 0]);
        $service = app(StockWriteOffService::class);

        $writeOff = $service->record($item->fresh(), 'consumable_item', 3, 'damaged', null, null);
        $this->assertEquals(7, (float) $item->fresh()->current_stock);
        $this->assertNull($writeOff->journal_entry_id);

        $service->cancel($writeOff, null, 'Salah catat');
        $this->assertEquals(10, (float) $item->fresh()->current_stock);

        $second = $service->record($item->fresh(), 'consumable_item', 1, 'damaged', null, null);
        $item->delete();
        $this->expectException(RuntimeException::class);
        $service->cancel($second, null, 'Coba');
    }
}
