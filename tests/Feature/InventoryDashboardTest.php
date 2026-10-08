<?php

namespace Tests\Feature;

use App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget;
use App\Filament\InventoryWidgets\InventoryStatsOverview;
use App\Filament\InventoryWidgets\MaterialsNeedingAttentionWidget;
use App\Filament\InventoryWidgets\ProblemAssetsWidget;
use App\Filament\Pages\InventoryDashboard;
use App\Models\Asset;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dashboard Inventaris: kartu ringkasan (perlu perhatian per ITEM, nilai stok bahan baku per batch FIFO, nilai barang habis
 * pakai, nilai aset, ready stock, menipis / kedaluwarsa / tidak bergerak, aset bermasalah) dan urutan tabel "perlu perhatian"
 * menurut jumlah baris; kartu mengikuti hak akses menu; aset per toko untuk staf; "Tandai Ditinjau" menyembunyikan baris.
 * Migrasi sudah menanam data awal, jadi tes mengosongkan tabel terkait dulu. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class InventoryDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private RawMaterial $film;
    private RawMaterial $sepi;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        RawMaterialBatch::query()->delete();
        RawMaterial::query()->delete();
        ConsumableItem::query()->delete();
        InventoryItem::query()->delete();
        Asset::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, ?Store $store = null): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function asset(string $tag, string $status, Store $store, float $cost): Asset
    {
        return Asset::create(['asset_tag' => $tag, 'name' => "Aset {$tag}", 'status' => $status, 'store_id' => $store->id, 'purchase_cost' => $cost]);
    }

    /**
     * Film (stok 10, harga bahan 100, ambang 20 => menipis; batch lama 5 @50 kedaluwarsa 20 Okt + batch baru 8 @70 => nilai 8x70 + 2x50 = 660)
     * Sepi (stok 5 tanpa harga, tidak bergerak sejak Juli). Aman (stok 50 @10 = 500).
     * Lap (stok 3, ambang 5, @2.000 = 6.000 => menipis), Tanpa Harga (stok 4), Kosong (stok 0 tanpa harga).
     * Aset: rusak A 1.000.000, hilang B 500.000, aktif A 300.000, dijual A 200.000. Ready stock: 2 dari 3.
     */
    private function fillInventory(): void
    {
        $this->film = RawMaterial::create(['name' => 'Film', 'code' => 'F-1', 'unit' => 'meter', 'current_stock' => 10, 'unit_cost' => 100, 'reorder_point' => 20]);
        RawMaterialBatch::create(['raw_material_id' => $this->film->id, 'quantity' => 5, 'unit_cost' => 50, 'received_date' => '2026-08-01', 'expiry_date' => '2026-10-20']);
        RawMaterialBatch::create(['raw_material_id' => $this->film->id, 'quantity' => 8, 'unit_cost' => 70, 'received_date' => '2026-09-01']);

        $this->sepi = RawMaterial::create(['name' => 'Sepi', 'code' => 'S-1', 'unit' => 'pcs', 'current_stock' => 5]);
        DB::table('raw_materials')->where('id', $this->sepi->id)->update(['updated_at' => '2026-07-01 09:00:00']);

        RawMaterial::create(['name' => 'Aman', 'code' => 'A-1', 'unit' => 'pcs', 'current_stock' => 50, 'unit_cost' => 10, 'reorder_point' => 10]);

        ConsumableItem::create(['name' => 'Lap', 'code' => 'L-1', 'unit' => 'pcs', 'current_stock' => 3, 'unit_cost' => 2000, 'reorder_point' => 5]);
        ConsumableItem::create(['name' => 'Tanpa Harga', 'code' => 'TH-1', 'unit' => 'pcs', 'current_stock' => 4]);
        ConsumableItem::create(['name' => 'Kosong', 'code' => 'K-1', 'unit' => 'pcs', 'current_stock' => 0]);

        $this->asset('A-RUSAK', 'rusak', $this->storeA, 1000000);
        $this->asset('A-HILANG', 'hilang', $this->storeB, 500000);
        $this->asset('A-AKTIF', 'aktif', $this->storeA, 300000);
        $this->asset('A-JUAL', 'dijual', $this->storeA, 200000);

        InventoryItem::create(['code' => 'INV-1', 'name' => 'PPF A', 'status' => 'in_stock']);
        InventoryItem::create(['code' => 'INV-2', 'name' => 'PPF B', 'status' => 'in_stock']);
        InventoryItem::create(['code' => 'INV-3', 'name' => 'PPF C', 'status' => 'out']);
    }

    /** @return array<string, \Filament\Widgets\StatsOverviewWidget\Stat> */
    private function stats(): array
    {
        $method = new \ReflectionMethod(InventoryStatsOverview::class, 'getStats');
        $method->setAccessible(true);

        return collect($method->invoke(new InventoryStatsOverview()))->keyBy(fn ($stat) => (string) $stat->getLabel())->all();
    }

    private function value(array $stats, string $label): mixed
    {
        return $stats[$label]->getValue();
    }

    // ------------------------------------------------------------- akses

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(InventoryDashboard::canAccess());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(InventoryDashboard::canAccess());

        $this->actingAs($this->user('kasir', ['InventoryDashboard']), 'web');
        $this->assertTrue(InventoryDashboard::canAccess());

        $this->actingAs($this->user('kasir', ['BookingResource']), 'web');
        $this->assertFalse(InventoryDashboard::canAccess());
    }

    // ------------------------------------------------------------- nilai stok

    public function test_raw_material_value_uses_the_remaining_fifo_batches(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('super_admin'), 'web');

        $stats = $this->stats();

        // Film 660 + Sepi 0 (tanpa harga) + Aman 500 = 1.160; Sepi ditandai belum ada harganya.
        $this->assertSame('Rp 1.160', $this->value($stats, 'Nilai Stok Bahan Baku'));
        $this->assertStringContainsString('1 bahan ada stok yang belum ada harganya', (string) $stats['Nilai Stok Bahan Baku']->getDescription());
        $this->assertEqualsWithDelta(660.0, $this->film->fresh()->load('batches')->stockValue(), 0.001);
        $this->assertFalse($this->film->fresh()->load('batches')->hasUnpricedStock());
        $this->assertTrue($this->sepi->fresh()->load('batches')->hasUnpricedStock());
    }

    public function test_consumable_and_asset_values_and_missing_price_counts(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('super_admin'), 'web');

        $stats = $this->stats();

        $this->assertSame('Rp 6.000', $this->value($stats, 'Nilai Stok Barang Habis Pakai'));
        $this->assertStringContainsString('(1 item belum ada harga', (string) $stats['Nilai Stok Barang Habis Pakai']->getDescription(), 'Hanya "Tanpa Harga" -- item kosong ("Kosong") tidak dihitung.');
        $this->assertSame('Rp 1.800.000', $this->value($stats, 'Nilai Aset'), 'Aset yang sudah dijual tidak ikut.');
        $this->assertSame(2, $this->value($stats, 'Produk PPF/WF Ready Stock'));
    }

    // ------------------------------------------------------------- perlu perhatian

    public function test_attention_cards_and_the_total_count_each_item_once(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('super_admin'), 'web');

        $stats = $this->stats();

        $this->assertSame(1, $this->value($stats, 'Bahan Baku Menipis'));
        $this->assertSame(1, $this->value($stats, 'Bahan Baku Kedaluwarsa/Mendekati'));
        $this->assertSame(1, $this->value($stats, 'Bahan Baku Tidak Bergerak'));
        $this->assertSame(1, $this->value($stats, 'Barang Habis Pakai Menipis'));
        $this->assertSame(0, $this->value($stats, 'Barang Habis Pakai Tidak Bergerak'));
        $this->assertSame(2, $this->value($stats, 'Aset Bermasalah'));
        // Bahan: Film (menipis + mendekati kedaluwarsa = 1 item) + Sepi; barang: Lap; aset: 2 => 5, bukan 6.
        $this->assertSame(5, $this->value($stats, 'Perlu Perhatian Hari Ini'));
    }

    public function test_the_total_equals_the_rows_of_the_attention_tables(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('super_admin'), 'web');

        $this->assertSame(2, MaterialsNeedingAttentionWidget::needingAttentionQuery()->count());
        $this->assertSame(1, ConsumablesNeedingAttentionWidget::needingAttentionQuery()->count());
        $this->assertSame(
            MaterialsNeedingAttentionWidget::needingAttentionQuery()->count() + ConsumablesNeedingAttentionWidget::needingAttentionQuery()->count() + 2,
            $this->value($this->stats(), 'Perlu Perhatian Hari Ini')
        );
    }

    public function test_acknowledging_hides_an_item_until_it_changes_again(): void
    {
        $this->fillInventory();
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        $this->sepi->fresh()->acknowledge($admin->id);
        $stats = $this->stats();
        $this->assertSame(0, $this->value($stats, 'Bahan Baku Tidak Bergerak'));
        $this->assertSame(4, $this->value($stats, 'Perlu Perhatian Hari Ini'));
        $this->assertSame(1, MaterialsNeedingAttentionWidget::needingAttentionQuery()->count());

        // Film juga ditinjau, lalu stoknya berubah (masih menipis) satu jam kemudian => muncul lagi.
        $this->film->fresh()->acknowledge($admin->id);
        $this->assertSame(0, MaterialsNeedingAttentionWidget::needingAttentionQuery()->count());

        Carbon::setTestNow('2026-10-08 11:00:00');
        $this->film->fresh()->update(['current_stock' => 9]);

        $this->assertSame(1, MaterialsNeedingAttentionWidget::needingAttentionQuery()->count());
        $this->assertSame(1, $this->value($this->stats(), 'Bahan Baku Menipis'));
    }

    // ------------------------------------------------------------- hak akses kartu & toko

    public function test_cards_follow_the_menu_access_of_each_category(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('kasir', ['InventoryDashboard', 'RawMaterialResource']), 'web');

        $labels = array_keys($this->stats());

        $this->assertContains('Nilai Stok Bahan Baku', $labels);
        $this->assertContains('Bahan Baku Menipis', $labels);
        $this->assertNotContains('Nilai Aset', $labels);
        $this->assertNotContains('Aset Bermasalah', $labels);
        $this->assertNotContains('Nilai Stok Barang Habis Pakai', $labels);
        $this->assertNotContains('Produk PPF/WF Ready Stock', $labels);
    }

    public function test_staff_see_only_their_store_assets(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('kasir', null, $this->storeA), 'web');

        $stats = $this->stats();

        $this->assertSame('Rp 1.300.000', $this->value($stats, 'Nilai Aset'), 'Toko A: rusak 1.000.000 + aktif 300.000.');
        $this->assertSame(1, $this->value($stats, 'Aset Bermasalah'));
        // Bahan baku dan barang habis pakai tetap nasional.
        $this->assertSame('Rp 1.160', $this->value($stats, 'Nilai Stok Bahan Baku'));
        $this->assertSame(4, $this->value($stats, 'Perlu Perhatian Hari Ini'), '2 bahan + 1 barang + 1 aset toko ini.');
    }

    public function test_an_empty_inventory_reports_all_clear(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $stats = $this->stats();

        $this->assertSame(0, $this->value($stats, 'Perlu Perhatian Hari Ini'));
        $this->assertStringContainsString('Semua kategori dalam kondisi baik', (string) $stats['Perlu Perhatian Hari Ini']->getDescription());
        $this->assertSame('Rp 0', $this->value($stats, 'Nilai Stok Bahan Baku'));
    }

    // ------------------------------------------------------------- halaman

    public function test_tables_are_ordered_by_how_many_rows_need_attention(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('super_admin'), 'web');

        $widgets = (new InventoryDashboard())->getWidgets();

        $this->assertSame(InventoryStatsOverview::class, $widgets[0], 'Ringkasan selalu di atas.');
        $this->assertSame([MaterialsNeedingAttentionWidget::class, ProblemAssetsWidget::class, ConsumablesNeedingAttentionWidget::class], array_slice($widgets, 1), 'Bahan baku (2 baris) dan aset (2) di atas barang habis pakai (1).');
        $this->assertSame(1, (new InventoryDashboard())->getColumns());
    }

    public function test_the_page_renders_with_the_cards_and_the_attention_tables(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('super_admin'), 'web');

        $this->get(InventoryDashboard::getUrl())
            ->assertOk()
            ->assertSee('Perlu Perhatian Hari Ini')
            ->assertSee('Bahan Baku Perlu Perhatian')
            ->assertSee('Film')
            ->assertSee('Sepi');
    }

    public function test_the_page_is_forbidden_without_menu_access(): void
    {
        $this->actingAs($this->user('kasir', ['BookingResource']), 'web');

        $this->get(InventoryDashboard::getUrl())->assertForbidden();
    }
}
