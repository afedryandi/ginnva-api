<?php

namespace Tests\Feature;

use App\Filament\InventoryWidgets\ConsumablesNeedingAttentionWidget;
use App\Filament\InventoryWidgets\InventoryActivityOverview;
use App\Filament\InventoryWidgets\InventoryStatsOverview;
use App\Filament\InventoryWidgets\InventoryValueOverview;
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

    private function user(string $role, ?array $menuAccess = null, ?Store $store = null, ?array $permissions = null): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'menu_permissions' => $permissions, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
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
        DB::table('raw_materials')->where('id', $this->sepi->id)->update(['created_at' => '2026-07-01 09:00:00', 'updated_at' => '2026-07-01 09:00:00']);

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
        $stats = collect();
        foreach ([InventoryStatsOverview::class, InventoryActivityOverview::class, InventoryValueOverview::class] as $widget) {
            $method = new \ReflectionMethod($widget, 'getStats');
            $method->setAccessible(true);
            $stats = $stats->merge($method->invoke(new $widget()));
        }

        return $stats->keyBy(fn ($stat) => (string) $stat->getLabel())->all();
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
        $this->assertFalse(InventoryDashboard::canAccess(), 'Akun bawaan (menu_access kosong) tidak boleh membuka dashboard ini.');

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

        // Satu kartu per kategori, dengan rincian alasannya.
        $this->assertSame(2, $this->value($stats, 'Bahan Baku Perlu Perhatian'), 'Film (menipis + mendekati kedaluwarsa) dan Sepi.');
        $materialText = (string) $stats['Bahan Baku Perlu Perhatian']->getDescription();
        $this->assertStringContainsString('1 menipis', $materialText);
        $this->assertStringContainsString('1 kedaluwarsa/mendekati', $materialText);
        $this->assertStringContainsString('1 tidak bergerak', $materialText);
        $this->assertSame(1, $this->value($stats, 'Barang Habis Pakai Perlu Perhatian'));
        $this->assertStringContainsString('1 menipis', (string) $stats['Barang Habis Pakai Perlu Perhatian']->getDescription());
        $this->assertStringNotContainsString('tidak bergerak', (string) $stats['Barang Habis Pakai Perlu Perhatian']->getDescription());
        $this->assertSame(2, $this->value($stats, 'Aset Bermasalah'));
        $this->assertStringContainsString('1 rusak · 1 hilang', (string) $stats['Aset Bermasalah']->getDescription());
        $this->assertSame('2 bahan · 1 barang · 2 aset', (string) $stats['Perlu Perhatian Hari Ini']->getDescription());
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
        $this->assertStringNotContainsString('tidak bergerak', (string) $stats['Bahan Baku Perlu Perhatian']->getDescription());
        $this->assertSame(4, $this->value($stats, 'Perlu Perhatian Hari Ini'));
        $this->assertSame(1, MaterialsNeedingAttentionWidget::needingAttentionQuery()->count());

        // Film juga ditinjau, lalu stoknya berubah (masih menipis) satu jam kemudian => muncul lagi.
        $this->film->fresh()->acknowledge($admin->id);
        $this->assertSame(0, MaterialsNeedingAttentionWidget::needingAttentionQuery()->count());

        Carbon::setTestNow('2026-10-08 11:00:00');
        $this->film->fresh()->update(['current_stock' => 9]);

        $this->assertSame(1, MaterialsNeedingAttentionWidget::needingAttentionQuery()->count());
        $this->assertStringContainsString('1 menipis', (string) $this->stats()['Bahan Baku Perlu Perhatian']->getDescription());
    }

    // ------------------------------------------------------------- hak akses kartu & toko

    public function test_cards_follow_the_menu_access_of_each_category(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('kasir', ['InventoryDashboard', 'RawMaterialResource'], null, ['InventoryDashboard' => ['view']]), 'web');

        $labels = array_keys($this->stats());

        $this->assertContains('Nilai Stok Bahan Baku', $labels);
        $this->assertContains('Bahan Baku Perlu Perhatian', $labels);
        $this->assertNotContains('Nilai Aset', $labels);
        $this->assertNotContains('Aset Bermasalah', $labels);
        $this->assertNotContains('Nilai Stok Barang Habis Pakai', $labels);
        $this->assertNotContains('Produk PPF/WF Ready Stock', $labels);
    }

    public function test_staff_see_only_their_store_assets(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user(
            'kasir',
            ['InventoryDashboard', 'InventoryItemResource', 'RawMaterialResource', 'ConsumableItemResource', 'AssetResource'],
            $this->storeA,
            ['InventoryDashboard' => ['view']]
        ), 'web');

        $stats = $this->stats();

        $this->assertSame('Rp 1.300.000', $this->value($stats, 'Nilai Aset'), 'Toko A: rusak 1.000.000 + aktif 300.000.');
        $this->assertSame(1, $this->value($stats, 'Aset Bermasalah'));
        // Bahan baku dan barang habis pakai tetap nasional.
        $this->assertSame('Rp 1.160', $this->value($stats, 'Nilai Stok Bahan Baku'));
        $this->assertSame(4, $this->value($stats, 'Perlu Perhatian Hari Ini'), '2 bahan + 1 barang + 1 aset toko ini.');
    }

    // ------------------------------------------------------------- audit Dashboard Inventaris A: akses eksplisit

    public function test_value_cards_need_the_view_grant_but_counts_do_not(): void
    {
        $this->fillInventory();
        $menu = ['InventoryDashboard', 'InventoryItemResource', 'RawMaterialResource', 'ConsumableItemResource', 'AssetResource'];

        $this->actingAs($this->user('kasir', $menu), 'web');
        $labels = array_keys($this->stats());
        foreach (['Nilai Stok Bahan Baku', 'Nilai Stok Barang Habis Pakai', 'Nilai Aset'] as $value) {
            $this->assertNotContains($value, $labels, 'Tanpa izin Lihat, nilai rupiah tidak tampil.');
        }
        $this->assertContains('Bahan Baku Perlu Perhatian', $labels);
        $this->assertContains('Perlu Perhatian Hari Ini', $labels);

        $this->actingAs($this->user('kasir', $menu, null, ['InventoryDashboard' => ['view']]), 'web');
        $labels = array_keys($this->stats());
        foreach (['Nilai Stok Bahan Baku', 'Nilai Stok Barang Habis Pakai', 'Nilai Aset'] as $value) {
            $this->assertContains($value, $labels);
        }

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertContains('Nilai Aset', array_keys($this->stats()));
    }

    public function test_default_accounts_see_no_cards_or_attention_tables(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('kasir'), 'web');

        $this->assertSame([], $this->stats());
        $this->assertFalse(MaterialsNeedingAttentionWidget::canView());
        $this->assertFalse(ConsumablesNeedingAttentionWidget::canView());
        $this->assertFalse(ProblemAssetsWidget::canView());
    }

    public function test_acknowledge_needs_the_update_grant_on_the_items_menu(): void
    {
        $this->fillInventory();
        $menu = ['InventoryDashboard', 'RawMaterialResource'];

        $this->actingAs($this->user('kasir', $menu), 'web');
        \Livewire\Livewire::test(MaterialsNeedingAttentionWidget::class)
            ->assertTableActionHidden('acknowledge', $this->sepi->getKey());

        $this->actingAs($this->user('kasir', $menu, null, ['RawMaterialResource' => ['update']]), 'web');
        \Livewire\Livewire::test(MaterialsNeedingAttentionWidget::class)
            ->assertTableActionVisible('acknowledge', $this->sepi->getKey());
    }

    // ------------------------------------------------------------- audit Dashboard Inventaris B: tinjauan punya batas

    public function test_a_review_expires_after_the_valid_days(): void
    {
        $this->fillInventory();
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        $this->sepi->fresh()->acknowledge($admin->id);
        $this->assertFalse(MaterialsNeedingAttentionWidget::needingAttentionQuery()->pluck('id')->contains($this->sepi->id));

        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->assertFalse(MaterialsNeedingAttentionWidget::needingAttentionQuery()->pluck('id')->contains($this->sepi->id), 'Hari ke-6: masih tersembunyi.');

        Carbon::setTestNow('2026-10-16 10:00:01');
        $this->assertTrue(MaterialsNeedingAttentionWidget::needingAttentionQuery()->pluck('id')->contains($this->sepi->id), 'Lewat 7 hari: muncul lagi walau datanya tidak berubah.');
        $this->assertFalse($this->sepi->fresh()->isAcknowledged());
    }

    public function test_a_review_reopens_when_a_batch_expires_after_it_was_made(): void
    {
        $this->fillInventory();
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        // Batch Film kedaluwarsa 20 Okt; ditinjau 18 Okt, saat masih "mendekati".
        Carbon::setTestNow('2026-10-18 09:00:00');
        $this->film->fresh()->acknowledge($admin->id);
        $this->assertFalse(MaterialsNeedingAttentionWidget::needingAttentionQuery()->pluck('id')->contains($this->film->id));

        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->assertFalse(MaterialsNeedingAttentionWidget::needingAttentionQuery()->pluck('id')->contains($this->film->id), 'Hari kedaluwarsa: belum lewat.');

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->assertTrue(MaterialsNeedingAttentionWidget::needingAttentionQuery()->pluck('id')->contains($this->film->id), 'Kedaluwarsa sesudah ditinjau: muncul lagi (baru 3 hari, datanya tidak berubah).');
        $this->assertFalse($this->film->fresh()->isAcknowledged());
    }

    public function test_reviewed_rows_can_be_listed_with_who_and_when_and_restored(): void
    {
        $this->fillInventory();
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $this->sepi->fresh()->acknowledge($admin->id);

        \Livewire\Livewire::test(MaterialsNeedingAttentionWidget::class)
            ->assertCanSeeTableRecords([$this->film])
            ->assertCanNotSeeTableRecords([$this->sepi])
            ->filterTable('review', 'reviewed')
            ->assertCanSeeTableRecords([$this->sepi])
            ->assertCanNotSeeTableRecords([$this->film])
            ->callTableAction('unacknowledge', $this->sepi->getKey());

        $this->assertNull($this->sepi->fresh()->reviewed_at);
        $this->assertTrue(MaterialsNeedingAttentionWidget::needingAttentionQuery()->pluck('id')->contains($this->sepi->id));
    }

    // ------------------------------------------------------------- audit Dashboard Inventaris C: aktivitas stok

    public function test_activity_cards_follow_the_menu_access_and_show_the_last_opname(): void
    {
        $this->fillInventory();

        $this->actingAs($this->user('kasir', ['InventoryDashboard', 'RawMaterialResource']), 'web');
        $labels = array_keys($this->stats());
        foreach (['Permohonan Pembelian Menunggu', 'Stok Terbuang Bulan Ini', 'Opname Terakhir', 'Produk PPF/WF Ready Stock'] as $card) {
            $this->assertNotContains($card, $labels, 'Tanpa akses ke menunya, kartu aktivitas tidak tampil.');
        }

        $this->actingAs($this->user('super_admin'), 'web');
        $stats = $this->stats();
        $this->assertSame('Belum pernah', $this->value($stats, 'Opname Terakhir'));
        $this->assertSame(0, $this->value($stats, 'Permohonan Pembelian Menunggu'));
        $this->assertSame(0, $this->value($stats, 'Stok Terbuang Bulan Ini'));

        \App\Models\StockOpname::create(['opname_number' => 'OPN-T-1', 'store_id' => $this->storeA->id, 'opname_date' => '2026-08-29']);
        $this->assertSame('40 hari lalu', $this->value($this->stats(), 'Opname Terakhir'));
    }

    public function test_the_attention_cards_link_to_the_filtered_lists(): void
    {
        $this->fillInventory();
        $this->actingAs($this->user('super_admin'), 'web');

        $stats = $this->stats();

        $this->assertStringContainsString('status', (string) $stats['Aset Bermasalah']->getUrl(), 'Aset bermasalah membuka daftar aset terfilter status.');
        $this->assertStringContainsString('rusak', (string) $stats['Aset Bermasalah']->getUrl());
        // Bahan punya tiga alasan sekaligus, jadi tautannya ke daftar penuh.
        $this->assertStringNotContainsString('tableFilters', rawurldecode((string) $stats['Bahan Baku Perlu Perhatian']->getUrl()));
        // Barang habis pakai hanya punya satu alasan (menipis): tautannya terfilter.
        $this->assertStringContainsString('low_stock', rawurldecode((string) $stats['Barang Habis Pakai Perlu Perhatian']->getUrl()));
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

        $this->assertSame([InventoryStatsOverview::class, InventoryActivityOverview::class, InventoryValueOverview::class], array_slice($widgets, 0, 3), 'Ringkasan selalu di atas.');
        $this->assertSame([MaterialsNeedingAttentionWidget::class, ProblemAssetsWidget::class, ConsumablesNeedingAttentionWidget::class], array_slice($widgets, 3), 'Bahan baku (2 baris) dan aset (2) di atas barang habis pakai (1).');
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
