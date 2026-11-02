<?php

namespace Tests\Feature;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ringkasan stok di Beranda aplikasi staf dan filter "menipis"/"kedaluwarsa" pada daftar bahan baku dan barang habis pakai (2026-11-02).
 * Hanya jumlah, tanpa nilai rupiah; kategori mengikuti akses menu akun. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class StockOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Carbon::setTestNow('2026-10-08 10:00:00');
        // Migrasi sudah menanam data awal, jadi kosongkan dulu.
        RawMaterialBatch::query()->delete();
        RawMaterial::query()->delete();
        ConsumableItem::query()->delete();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        // Bahan: Aman (50, ambang 10), Tipis (2, ambang 10), Kritis (0, ambang 5), Lewat (stok 8, batch kedaluwarsa 1 Okt), Dekat (batch 20 Okt).
        $this->material('Aman', 50, 10);
        $this->material('Tipis', 2, 10);
        $this->material('Kritis', 0, 5);
        $lewat = $this->material('Lewat', 8, null);
        $dekat = $this->material('Dekat', 8, null);
        RawMaterialBatch::create(['raw_material_id' => $lewat->id, 'quantity' => 8, 'unit_cost' => 10, 'received_date' => '2026-08-01', 'expiry_date' => '2026-10-01']);
        RawMaterialBatch::create(['raw_material_id' => $dekat->id, 'quantity' => 8, 'unit_cost' => 10, 'received_date' => '2026-08-01', 'expiry_date' => '2026-10-20']);

        ConsumableItem::create(['name' => 'Lap Aman', 'code' => 'L-1', 'unit' => 'pcs', 'current_stock' => 30, 'reorder_point' => 5]);
        ConsumableItem::create(['name' => 'Lap Tipis', 'code' => 'L-2', 'unit' => 'pcs', 'current_stock' => 3, 'reorder_point' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function material(string $name, float $stock, ?float $reorder): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'unit' => 'pcs', 'current_stock' => $stock, 'reorder_point' => $reorder]);
    }

    private function staff(array $menu): User
    {
        $user = User::create(['name' => 'Gudang ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menu, 'is_active' => true]);
        $user->assignRole('kasir');

        return $user;
    }

    public function test_the_overview_counts_low_expired_and_near_expiry_without_money(): void
    {
        $user = $this->staff(['RawMaterialResource', 'ConsumableItemResource']);

        $data = $this->actingAs($user, 'api')->getJson('/api/staff/stock-overview')->assertSuccessful()->json('data');

        $this->assertSame(['low' => 2, 'expired' => 1, 'near_expiry' => 1], $data['materials']);
        $this->assertSame(['low' => 1], $data['consumables']);
        $this->assertStringNotContainsString('Rp', json_encode($data));
        $this->assertStringNotContainsString('cost', json_encode($data));
    }

    public function test_each_category_follows_the_menu_access(): void
    {
        $materialsOnly = $this->actingAs($this->staff(['RawMaterialResource']), 'api')->getJson('/api/staff/stock-overview')->json('data');
        $this->assertNotNull($materialsOnly['materials']);
        $this->assertNull($materialsOnly['consumables']);

        $none = $this->actingAs($this->staff(['BookingResource']), 'api')->getJson('/api/staff/stock-overview')->json('data');
        $this->assertNull($none['materials']);
        $this->assertNull($none['consumables']);
    }

    public function test_the_materials_list_can_filter_low_stock_and_expiry_and_flags_low_items(): void
    {
        $user = $this->staff(['RawMaterialResource']);

        $low = $this->actingAs($user, 'api')->getJson('/api/staff/materials?filter=low')->assertSuccessful()->json('data');
        $this->assertSame(['Tipis', 'Kritis'], array_column($low, 'name'), 'Selisih ke ambang terbesar dulu (2 dari 10, lalu 0 dari 5).');
        $this->assertSame([true, true], array_column($low, 'is_low_stock'));

        $expiry = $this->actingAs($user, 'api')->getJson('/api/staff/materials?filter=expiry')->json('data');
        $this->assertEqualsCanonicalizing(['Lewat', 'Dekat'], array_column($expiry, 'name'));

        $all = collect($this->actingAs($user, 'api')->getJson('/api/staff/materials')->json('data'))->keyBy('name');
        $this->assertFalse($all['Aman']['is_low_stock']);
        $this->assertTrue($all['Tipis']['is_low_stock']);
    }

    public function test_the_consumables_list_can_filter_low_stock(): void
    {
        $user = $this->staff(['ConsumableItemResource']);

        $low = $this->actingAs($user, 'api')->getJson('/api/staff/consumables?filter=low')->assertSuccessful()->json('data');

        $this->assertSame(['Lap Tipis'], array_column($low, 'name'));
        $this->assertTrue($low[0]['is_low_stock']);
    }
}
