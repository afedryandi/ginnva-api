<?php

namespace Tests\Feature;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * bahan-baku:reset: kosongkan Bahan Baku untuk diinput ulang. Dry-run tanpa --confirm, menolak bila ada dokumen yang menunjuk Bahan Baku
 * kecuali --hapus-referensi, dan tidak menyentuh Barang Habis Pakai.
 */
class ResetRawMaterialsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Migrasi impor Majoo sudah mengisi beberapa Bahan Baku di setiap database uji, jadi hitungan dibandingkan dengan jumlah awal. */
    private function baseline(): int
    {
        return DB::table('raw_materials')->count();
    }

    private function seedMaterials(): RawMaterial
    {
        $film = RawMaterial::create(['name' => 'Cairan Slip', 'code' => 'CS-1', 'unit' => 'ml', 'current_stock' => 0]);
        RawMaterial::create(['name' => 'Lem', 'code' => 'LM-1', 'unit' => 'ml', 'current_stock' => 0]);
        $film->recordMovement('in', 500, null, 'Stok awal salah input');

        return $film;
    }

    public function test_without_confirm_it_only_reports_and_changes_nothing(): void
    {
        $before = $this->baseline();
        $this->seedMaterials();

        $this->artisan('bahan-baku:reset')->assertSuccessful();

        $this->assertSame($before + 2, DB::table('raw_materials')->count());
        $this->assertGreaterThan(0, DB::table('raw_material_movements')->count());
    }

    public function test_confirm_removes_materials_batches_and_movements_but_not_consumables(): void
    {
        $this->seedMaterials();
        ConsumableItem::create(['name' => 'Sarung Tangan', 'unit' => 'pcs', 'current_stock' => 5]);

        $this->artisan('bahan-baku:reset --confirm')->assertSuccessful();

        $this->assertSame(0, DB::table('raw_materials')->count());
        $this->assertSame(0, DB::table('raw_material_batches')->count());
        $this->assertSame(0, DB::table('raw_material_movements')->count());
        $this->assertSame(1, DB::table('consumable_items')->count(), 'Barang Habis Pakai tidak disentuh.');
        $this->assertTrue(DB::table('activity_log')->where('description', 'like', 'Reset Bahan Baku%')->exists(), 'Penghapusan tercatat di Histori Aktivitas.');
    }

    public function test_it_refuses_while_documents_still_point_at_a_raw_material(): void
    {
        $before = $this->baseline();
        $film = $this->seedMaterials();
        DB::table('stock_write_offs')->insert($this->writeOff($film->id));

        $this->artisan('bahan-baku:reset --confirm')->assertFailed();

        $this->assertSame($before + 2, DB::table('raw_materials')->count(), 'Tidak ada yang dihapus ketika ditolak.');

        $this->artisan('bahan-baku:reset --confirm --hapus-referensi')->assertSuccessful();

        $this->assertSame(0, DB::table('raw_materials')->count());
        $this->assertSame(0, DB::table('stock_write_offs')->where('writeoffable_type', 'raw_material')->count());
    }

    /** @return array<string, mixed> */
    private function writeOff(int $materialId): array
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('stock_write_offs');
        $row = [
            'write_off_number' => 'WO-TEST-0001', 'writeoffable_type' => 'raw_material', 'writeoffable_id' => $materialId,
            'item_name' => 'Cairan Slip', 'unit' => 'ml', 'quantity' => 1, 'reason' => 'damaged', 'created_at' => now(), 'updated_at' => now(),
        ];

        return array_intersect_key($row, array_flip($columns));
    }
}
