<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * API mobile staf untuk stok dan aset: bahan baku & barang habis pakai (cari, detail, riwayat berhalaman, catat masuk/keluar
 * dengan batas angka, sesuaikan stok), memo pengambilan/pengembalian (hanya toko sendiri, tambah/kembalikan/koreksi/hapus baris
 * dengan efek stok), permohonan pembelian (toko dipaksa, validasi) dan aset (pembatasan toko termasuk saat mencari, ubah
 * status). "Hari ini" dibekukan di 8 Oktober 2026.
 */
class StaffStockApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('kasir', 'web');
        Carbon::setTestNow('2026-10-08 10:00:00');
        RawMaterial::query()->delete();
        ConsumableItem::query()->delete();
        InventoryItem::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role = 'kasir', ?Store $store = null, ?array $menuAccess = null, bool $withStore = true, ?array $permissions = null): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $withStore ? ($store ?? $this->storeA)->id : null, 'menu_access' => $menuAccess, 'menu_permissions' => $permissions, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Akun gudang: menu Bahan Baku + Barang Habis Pakai dicentang, boleh mencatat (Ubah) dan melihat harga (Lihat). */
    private function stockStaff(): User
    {
        return $this->staff('kasir', null, ['RawMaterialResource', 'ConsumableItemResource'], true, ['RawMaterialResource' => ['update', 'view'], 'ConsumableItemResource' => ['update', 'view']]);
    }

    private function api(User $user)
    {
        return $this->actingAs($user, 'api');
    }

    private function material(string $name = 'Adhesive', float $stock = 10, ?float $cost = null): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => 'RM-' . strtoupper(uniqid()), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => $stock, 'unit_cost' => $cost]);
    }

    private function consumable(string $name = 'Sarung Tangan', float $stock = 10): ConsumableItem
    {
        return ConsumableItem::create(['name' => $name, 'code' => 'CI-' . strtoupper(uniqid()), 'category' => 'umum', 'unit' => 'pcs', 'current_stock' => $stock]);
    }

    // ------------------------------------------------------------- bahan baku & barang habis pakai

    public function test_material_and_consumable_endpoints_need_a_token_and_the_menu(): void
    {
        $material = $this->material();
        $consumable = $this->consumable();
        $noMenu = $this->staff('kasir', null, ['BookingResource']);

        foreach (["/api/staff/materials/{$material->id}", "/api/staff/consumables/{$consumable->id}"] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
        foreach (["/api/staff/materials/{$material->id}", "/api/staff/consumables/{$consumable->id}"] as $url) {
            $this->api($noMenu)->getJson($url)->assertStatus(403);
            $this->api($noMenu)->getJson($url . '/movements')->assertStatus(403);
            $this->api($noMenu)->postJson($url . '/movement', ['type' => 'in', 'quantity' => 1])->assertStatus(403);
            $this->api($noMenu)->postJson($url . '/adjust', ['actual_quantity' => 1])->assertStatus(403);
        }
        $this->api($noMenu)->getJson('/api/staff/materials')->assertStatus(403);
        $this->api($noMenu)->getJson('/api/staff/consumables')->assertStatus(403);
    }

    public function test_material_access_is_explicit_writes_need_update_and_prices_need_view(): void
    {
        $material = $this->material('Adhesive', 10, 5000);
        $material->batches()->create(['quantity' => 10, 'unit_cost' => 5000, 'received_date' => '2026-10-01']);
        $material->recordMovement('in', 1, null, 'uji', '2026-10-02', null, 5000);

        // Akun dengan akses bawaan hanya boleh memakai daftar pencarian (pemilih Memo Bahan/Permohonan Pembelian, tanpa harga):
        // detail dan pencatatan tetap tertutup.
        $default = $this->staff();
        $this->api($default)->getJson('/api/staff/materials')->assertSuccessful()->assertJsonPath('can_modify', false);
        $this->api($default)->getJson("/api/staff/materials/{$material->id}")->assertStatus(403);
        $this->api($default)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 1])->assertStatus(403);

        $viewer = $this->staff('kasir', null, ['RawMaterialResource']);
        $detail = $this->api($viewer)->getJson("/api/staff/materials/{$material->id}")->assertSuccessful();
        $this->assertFalse($detail->json('can_modify'));
        $this->assertArrayNotHasKey('unit_cost', $detail->json('data'));
        $this->assertArrayNotHasKey('notes', $detail->json('data'));
        $this->assertArrayNotHasKey('unit_cost', $detail->json('data.batches.0'));
        $this->assertArrayNotHasKey('unit_cost', $detail->json('data.movements.0'));
        $this->api($viewer)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 1])->assertStatus(403);
        $this->api($viewer)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 1])->assertStatus(403);

        $writer = $this->staff('kasir', null, ['RawMaterialResource'], true, ['RawMaterialResource' => ['update']]);
        $this->api($writer)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 2, 'unit_cost' => 99999])
            ->assertSuccessful()
            ->assertJsonMissingPath('data.unit_cost');
        $this->assertSame(0, $material->batches()->where('unit_cost', 99999)->count(), 'Harga dari akun tanpa izin Lihat diabaikan.');
        $this->assertTrue($this->api($writer)->getJson("/api/staff/materials/{$material->id}")->json('can_modify'));

        $costViewer = $this->staff('kasir', null, ['RawMaterialResource'], true, ['RawMaterialResource' => ['view']]);
        $withCost = $this->api($costViewer)->getJson("/api/staff/materials/{$material->id}")->assertSuccessful();
        $this->assertEquals(5000, $withCost->json('data.unit_cost'));
        $this->assertEquals(5000, $withCost->json('data.batches.0.unit_cost'));
    }

    public function test_mobile_records_are_tagged_with_the_recorders_store_and_write_off_has_its_own_endpoint(): void
    {
        // Jurnal kerugian memerlukan akun bagan akun (6520/1130) bila modul Keuangan menyala.
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
        $material = $this->material('Adhesive', 10, 5000);
        $user = $this->stockStaff();

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'out', 'quantity' => 1])->assertSuccessful();
        $this->api($user)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 8])->assertSuccessful();
        $this->assertSame([$this->storeA->id, $this->storeA->id], $material->movements()->pluck('store_id')->all());

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/write-off", ['quantity' => 3, 'reason' => 'damaged', 'note' => 'tumpah'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Stok terbuang dicatat.');
        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $writeOff = \App\Models\StockWriteOff::withoutGlobalScopes()->where('writeoffable_id', $material->id)->firstOrFail();
        $this->assertSame(['damaged', 'tumpah'], [$writeOff->reason, $writeOff->note]);
        $this->assertSame($this->storeA->id, $material->movements()->where('type', 'adjustment')->latest('id')->first()->store_id);

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/write-off", ['quantity' => 99, 'reason' => 'lost'])->assertStatus(422);
        $this->api($user)->postJson("/api/staff/materials/{$material->id}/write-off", ['quantity' => 1, 'reason' => 'ngawur'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->api($user)->postJson('/api/staff/materials/999999/write-off', ['quantity' => 1, 'reason' => 'lost'])->assertNotFound();
        $this->assertEquals(5, (float) $material->fresh()->current_stock);

        $detail = $this->api($user)->getJson("/api/staff/materials/{$material->id}")->assertSuccessful();
        $this->assertArrayHasKey('damaged', $detail->json('write_off_reasons'));

        $viewer = $this->staff('kasir', null, ['RawMaterialResource']);
        $this->api($viewer)->postJson("/api/staff/materials/{$material->id}/write-off", ['quantity' => 1, 'reason' => 'lost'])->assertStatus(403);
        $this->assertNull($this->api($viewer)->getJson("/api/staff/materials/{$material->id}")->json('write_off_reasons'));
        $this->assertEquals(5, (float) $material->fresh()->current_stock);
    }

    public function test_expiry_cannot_be_earlier_than_the_received_date_and_the_purchase_unit_is_exposed(): void
    {
        $material = RawMaterial::create(['name' => 'Cairan', 'code' => 'RM-C1', 'unit' => 'ml', 'purchase_unit' => 'Galon', 'purchase_conversion_factor' => 3785, 'current_stock' => 0]);
        $user = $this->stockStaff();

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 5, 'received_date' => '2026-10-07', 'expiry_date' => '2026-10-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_date')
            ->assertJsonPath('message', 'Tanggal kedaluwarsa tidak boleh lebih awal dari tanggal masuk.');
        $this->assertEquals(0, (float) $material->fresh()->current_stock);

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 5, 'received_date' => '2026-10-07', 'expiry_date' => '2026-10-07'])->assertSuccessful();
        $this->api($user)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 5, 'expiry_date' => '2027-01-01'])->assertSuccessful();

        $detail = $this->api($user)->getJson("/api/staff/materials/{$material->id}")->assertSuccessful();
        $this->assertSame('Galon', $detail->json('data.purchase_unit'));
        $this->assertEquals(3785, $detail->json('data.purchase_conversion_factor'));
    }

    public function test_memo_and_purchase_request_holders_can_use_the_material_picker_but_nothing_else(): void
    {
        $material = $this->material('Adhesive', 10, 5000);

        foreach (['MaterialMemoResource', 'PurchaseRequestResource'] as $menu) {
            $picker = $this->staff('kasir', null, [$menu]);
            $rows = $this->api($picker)->getJson('/api/staff/materials?search=Adhesive')->assertSuccessful();
            $this->assertSame(['Adhesive'], array_column($rows->json('data'), 'name'));
            $this->assertArrayNotHasKey('unit_cost', $rows->json('data.0'));
            $this->assertFalse($rows->json('can_modify'));

            $this->api($picker)->getJson("/api/staff/materials/{$material->id}")->assertStatus(403);
            $this->api($picker)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 1])->assertStatus(403);
        }

        $this->api($this->staff('kasir', null, ['BookingResource']))->getJson('/api/staff/materials')->assertStatus(403);
    }

    public function test_the_list_reports_the_total_and_a_summary_independent_of_the_search(): void
    {
        $low = $this->material('Zeta Tipis', 1);
        $low->update(['reorder_point' => 5]);
        $ok = $this->material('Zeta Aman', 50);
        $ok->update(['reorder_point' => 5]);
        $ok->batches()->create(['quantity' => 5, 'received_date' => '2026-09-01', 'expiry_date' => '2026-10-20']);
        $before = $this->api($this->stockStaff())->getJson('/api/staff/materials?search=ZZZ-tidak-ada')->json('summary');

        $response = $this->api($this->stockStaff())->getJson('/api/staff/materials?search=Zeta')->assertSuccessful();
        $this->assertSame(2, $response->json('total'));
        $this->assertSame(30, $response->json('limit'));
        $this->assertTrue($response->json('can_modify'));
        $this->assertSame($before, $response->json('summary'), 'Ringkasan tidak ikut berubah karena pencarian.');
        $this->assertGreaterThanOrEqual(1, $response->json('summary.low'));
        $this->assertGreaterThanOrEqual(1, $response->json('summary.expiry'));

        $lowOnly = $this->api($this->stockStaff())->getJson('/api/staff/materials?filter=low&search=Zeta')->assertSuccessful();
        $this->assertSame(1, $lowOnly->json('total'));
        $this->assertSame(100, $lowOnly->json('limit'));
    }

    public function test_the_detail_mentions_an_approved_purchase_waiting_for_the_material(): void
    {
        $material = $this->material('Adhesive', 10);
        $user = $this->stockStaff();

        $this->assertNull($this->api($user)->getJson("/api/staff/materials/{$material->id}")->json('pending_purchase'));

        $request = \App\Models\PurchaseRequest::create(['store_id' => $this->storeA->id, 'item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Adhesive', 'unit' => 'liter', 'quantity' => 5, 'status' => 'pending', 'requested_by' => $user->id]);
        $this->assertNull($this->api($user)->getJson("/api/staff/materials/{$material->id}")->json('pending_purchase'), 'Yang masih menunggu persetujuan tidak ditampilkan.');

        $request->update(['status' => 'approved']);
        $pending = $this->api($user)->getJson("/api/staff/materials/{$material->id}")->json('pending_purchase');
        $this->assertSame($request->request_number, $pending['request_number']);

        $viewer = $this->staff('kasir', null, ['RawMaterialResource']);
        $this->assertNull($this->api($viewer)->getJson("/api/staff/materials/{$material->id}")->json('pending_purchase'));
    }

    public function test_staff_can_request_a_purchase_for_a_low_stock_material_from_the_detail(): void
    {
        $material = $this->material('Adhesive', 2);
        $material->update(['reorder_point' => 10]);
        $fine = $this->material('Aman', 50);
        $fine->update(['reorder_point' => 10]);
        $user = $this->staff('kasir', null, ['RawMaterialResource', 'PurchaseRequestResource']);

        $info = $this->api($user)->getJson("/api/staff/materials/{$material->id}")->assertSuccessful()->json('restock');
        $this->assertTrue($info['can_request']);
        $this->assertNull($info['open_request']);
        $this->assertEquals(8, $info['suggested_quantity']);
        $this->assertFalse($this->api($user)->getJson("/api/staff/materials/{$fine->id}")->json('restock.can_request'));

        $this->api($user)->postJson("/api/staff/materials/{$fine->id}/restock-request", ['quantity' => 5])->assertStatus(422);

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/restock-request", ['quantity' => 0])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $created = $this->api($user)->postJson("/api/staff/materials/{$material->id}/restock-request", ['quantity' => 8, 'note' => 'Habis minggu ini'])->assertSuccessful();
        $purchase = \App\Models\PurchaseRequest::where('item_id', $material->id)->firstOrFail();
        $this->assertSame($purchase->request_number, $created->json('data.request_number'));
        $this->assertSame(['pending', $this->storeA->id, $user->id], [$purchase->status, $purchase->store_id, $purchase->requested_by]);

        $after = $this->api($user)->getJson("/api/staff/materials/{$material->id}")->json('restock');
        $this->assertFalse($after['can_request']);
        $this->assertSame($purchase->request_number, $after['open_request']['request_number']);
        $this->api($user)->postJson("/api/staff/materials/{$material->id}/restock-request", ['quantity' => 3])->assertStatus(422);
        $this->assertSame(1, \App\Models\PurchaseRequest::where('item_id', $material->id)->count());

        $noPurchaseMenu = $this->staff('kasir', null, ['RawMaterialResource']);
        $this->assertNull($this->api($noPurchaseMenu)->getJson("/api/staff/materials/{$material->id}")->json('restock'));
        $this->api($noPurchaseMenu)->postJson("/api/staff/materials/{$material->id}/restock-request", ['quantity' => 3])->assertStatus(403);

        $noStore = $this->staff('kasir', null, ['RawMaterialResource', 'PurchaseRequestResource'], false);
        $this->assertFalse($this->api($noStore)->getJson("/api/staff/materials/{$material->id}")->json('restock.can_request') ?? false);
    }

    public function test_inactive_materials_leave_the_picker_and_the_counts_but_the_detail_still_opens(): void
    {
        $active = $this->material('Zeta Aktif', 1);
        $active->update(['reorder_point' => 5]);
        $inactive = $this->material('Zeta Mati', 1);
        $inactive->update(['reorder_point' => 5, 'is_active' => false]);
        $user = $this->stockStaff();

        $list = $this->api($user)->getJson('/api/staff/materials?search=Zeta')->assertSuccessful();
        $this->assertSame(['Zeta Aktif'], array_column($list->json('data'), 'name'));
        $this->assertSame(1, $list->json('total'));
        $this->assertSame(['Zeta Aktif'], array_column($this->api($user)->getJson('/api/staff/materials?filter=low&search=Zeta')->json('data'), 'name'));

        $this->api($user)->getJson("/api/staff/materials/{$inactive->id}")->assertSuccessful()->assertJsonPath('data.name', 'Zeta Mati');
    }

    public function test_the_detail_card_carries_the_supplier_and_the_nearest_expiry(): void
    {
        $supplier = \App\Models\Supplier::create(['name' => 'PT Kimia Jaya']);
        $material = $this->material('Adhesive', 10);
        $material->update(['preferred_supplier_id' => $supplier->id]);
        $material->batches()->create(['quantity' => 5, 'received_date' => '2026-09-01', 'expiry_date' => '2026-12-31']);
        $material->batches()->create(['quantity' => 5, 'received_date' => '2026-09-02', 'expiry_date' => '2026-11-15']);
        $material->batches()->create(['quantity' => 0, 'received_date' => '2026-08-01', 'expiry_date' => '2026-09-01']);   // habis: diabaikan
        $user = $this->stockStaff();

        $card = $this->api($user)->getJson("/api/staff/materials/{$material->id}")->assertSuccessful()->json('card');
        $this->assertSame('PT Kimia Jaya', $card['preferred_supplier']);
        $this->assertSame('2026-11-15', $card['nearest_expiry_date']);

        $bare = $this->material('Tanpa Pemasok', 3);
        $empty = $this->api($user)->getJson("/api/staff/materials/{$bare->id}")->json('card');
        $this->assertNull($empty['preferred_supplier']);
        $this->assertNull($empty['nearest_expiry_date']);
    }

    public function test_the_api_requires_a_note_for_an_opname_above_the_materials_limit(): void
    {
        $material = $this->material('Adhesive', 20);
        $material->update(['adjustment_note_threshold' => 5]);
        $free = $this->material('Tanpa Batas', 20);
        $user = $this->stockStaff();

        $this->assertEquals(5, $this->api($user)->getJson("/api/staff/materials/{$material->id}")->json('data.adjustment_note_threshold'));

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
        $this->api($user)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 10, 'note' => '   '])->assertStatus(422);
        $this->assertEquals(20, (float) $material->fresh()->current_stock);

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 10, 'note' => 'Tumpah'])->assertSuccessful();
        $this->assertEquals(10, (float) $material->fresh()->current_stock);

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 8])->assertSuccessful();
        $this->api($user)->postJson("/api/staff/materials/{$free->id}/adjust", ['actual_quantity' => 1])->assertSuccessful();
    }

    public function test_search_lists_by_name_or_code_and_flags_expiry_for_materials(): void
    {
        $adhesive = $this->material('Adhesive');
        $this->material('Slip Solution');
        $adhesive->batches()->create(['quantity' => 5, 'received_date' => '2026-09-01', 'expiry_date' => '2026-10-20']);
        $adhesive->batches()->create(['quantity' => 5, 'received_date' => '2026-09-02', 'expiry_date' => '2026-12-31']);
        $user = $this->stockStaff();

        $rows = collect($this->api($user)->getJson('/api/staff/materials?search=Adhesive')->assertSuccessful()->json('data'));
        $this->assertSame(['Adhesive'], $rows->pluck('name')->all());
        $this->assertSame('2026-10-20', Carbon::parse($rows[0]['nearest_expiry_date'])->setTimezone(config('app.timezone'))->toDateString());
        $this->assertTrue($rows[0]['is_near_expiry']);
        $this->assertFalse($rows[0]['is_expired']);

        $this->assertSame(['Slip Solution'], collect($this->api($user)->getJson('/api/staff/materials?search=Slip')->json('data'))->pluck('name')->all());
        $this->assertCount(2, $this->api($user)->getJson('/api/staff/materials')->json('data'));

        $this->consumable('Lakban');
        $this->assertSame(['Lakban'], collect($this->api($user)->getJson('/api/staff/consumables?search=Lakban')->json('data'))->pluck('name')->all());
    }

    public function test_an_expired_batch_is_flagged(): void
    {
        $material = $this->material('Adhesive');
        $material->batches()->create(['quantity' => 3, 'received_date' => '2026-06-01', 'expiry_date' => '2026-09-30']);

        $row = $this->api($this->staff())->getJson('/api/staff/materials?search=Adhesive')->json('data.0');

        $this->assertTrue($row['is_expired']);
        $this->assertTrue($row['is_near_expiry']);
    }

    public function test_detail_and_paged_history_for_both_kinds(): void
    {
        $material = $this->material('Adhesive', 100);
        $consumable = $this->consumable('Sarung Tangan', 100);
        $user = $this->stockStaff();
        foreach (range(1, 22) as $i) {
            $material->recordMovement('out', 1, $user->id, "Pakai {$i}");
            $consumable->recordMovement('out', 1, $user->id, "Pakai {$i}");
        }

        foreach (["/api/staff/materials/{$material->id}", "/api/staff/consumables/{$consumable->id}"] as $url) {
            $detail = $this->api($user)->getJson($url)->assertSuccessful();
            $this->assertCount(20, $detail->json('data.movements'));
            $this->assertTrue($detail->json('movements_has_more'));

            $page = $this->api($user)->getJson($url . '/movements?offset=20')->assertSuccessful();
            $this->assertCount(2, $page->json('data'));
            $this->assertFalse($page->json('has_more'));
            $this->api($user)->getJson($url . '/movements')->assertSuccessful()->assertJsonPath('has_more', true);
        }

        $this->api($user)->getJson('/api/staff/materials/999999')->assertNotFound();
        $this->api($user)->getJson('/api/staff/consumables/999999/movements')->assertNotFound();
    }

    public function test_recording_in_and_out_changes_the_stock(): void
    {
        $material = $this->material('Adhesive', 10);
        $consumable = $this->consumable('Sarung Tangan', 10);
        $user = $this->stockStaff();

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 5, 'unit_cost' => 20000, 'received_date' => '2026-10-07', 'expiry_date' => '2027-10-07'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Bahan masuk berhasil dicatat.');
        $this->api($user)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'out', 'quantity' => 3])->assertSuccessful();
        $this->assertEquals(12, (float) $material->fresh()->current_stock);
        $this->assertSame(1, $material->batches()->where('unit_cost', 20000)->count());

        $this->api($user)->postJson("/api/staff/consumables/{$consumable->id}/movement", ['type' => 'out', 'quantity' => 4])->assertSuccessful();
        $this->assertEquals(6, (float) $consumable->fresh()->current_stock);
    }

    public function test_out_of_stock_and_bad_numbers_are_refused(): void
    {
        $material = $this->material('Adhesive', 2);
        $consumable = $this->consumable('Sarung Tangan', 2);
        $user = $this->stockStaff();

        foreach (["/api/staff/materials/{$material->id}", "/api/staff/consumables/{$consumable->id}"] as $url) {
            $this->api($user)->postJson($url . '/movement', ['type' => 'out', 'quantity' => 5])->assertStatus(422);
            $this->api($user)->postJson($url . '/movement', ['type' => 'in', 'quantity' => 0])->assertStatus(422)->assertJsonValidationErrors('quantity');
            $this->api($user)->postJson($url . '/movement', ['type' => 'in', 'quantity' => 2000000])->assertStatus(422)->assertJsonValidationErrors('quantity');
            $this->api($user)->postJson($url . '/movement', ['type' => 'in', 'quantity' => 1, 'unit_cost' => 5000000000])->assertStatus(422)->assertJsonValidationErrors('unit_cost');
            $this->api($user)->postJson($url . '/movement', ['type' => 'in', 'quantity' => 1, 'unit_cost' => -1])->assertStatus(422)->assertJsonValidationErrors('unit_cost');
            $this->api($user)->postJson($url . '/movement', ['type' => 'salah', 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('type');
            $this->api($user)->postJson($url . '/adjust', ['actual_quantity' => -1])->assertStatus(422)->assertJsonValidationErrors('actual_quantity');
            $this->api($user)->postJson($url . '/adjust', ['actual_quantity' => 2000000])->assertStatus(422)->assertJsonValidationErrors('actual_quantity');
        }

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/movement", ['type' => 'in', 'quantity' => 1, 'received_date' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('received_date');
        $this->api($user)->postJson('/api/staff/materials/999999/movement', ['type' => 'in', 'quantity' => 1])->assertNotFound();
        $this->assertEquals(2, (float) $material->fresh()->current_stock);
        $this->assertEquals(2, (float) $consumable->fresh()->current_stock);
    }

    public function test_adjusting_stock_records_the_difference_or_nothing(): void
    {
        $material = $this->material('Adhesive', 10);
        $user = $this->stockStaff();

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 7.5, 'note' => 'Opname'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Stok disesuaikan (selisih -2.50 liter).');
        $this->assertEquals(7.5, (float) $material->fresh()->current_stock);

        $this->api($user)->postJson("/api/staff/materials/{$material->id}/adjust", ['actual_quantity' => 7.5])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.');
        $this->assertSame(1, $material->movements()->where('type', 'adjustment')->count());
    }

    // ------------------------------------------------------------- memo

    private function memo(Store $store, array $extra = []): MaterialMemo
    {
        return MaterialMemo::create(array_merge(['memo_number' => 'MEMO-T-' . uniqid(), 'store_id' => $store->id, 'created_by' => $this->staff()->id], $extra));
    }

    public function test_memo_endpoints_need_a_token_and_the_menu(): void
    {
        $memo = $this->memo($this->storeA);
        $noMenu = $this->staff('kasir', null, ['BookingResource']);

        $this->getJson('/api/staff/memos')->assertStatus(401);
        $this->api($noMenu)->getJson('/api/staff/memos')->assertStatus(403);
        $this->api($noMenu)->postJson('/api/staff/memos', [])->assertStatus(403);
        $this->api($noMenu)->getJson("/api/staff/memos/{$memo->id}")->assertStatus(403);
        $this->api($noMenu)->deleteJson("/api/staff/memos/{$memo->id}")->assertStatus(403);
        $this->assertNotNull(MaterialMemo::find($memo->id));
    }

    public function test_staff_create_a_memo_for_their_own_store_and_only_see_their_own(): void
    {
        $staff = $this->staff('kasir', $this->storeA);
        $theirs = $this->memo($this->storeB);

        $response = $this->api($staff)->postJson('/api/staff/memos', ['vehicle_info' => 'Avanza B 1 XYZ', 'spk_number' => 'SPK-1', 'notes' => 'Catatan', 'store_id' => $this->storeB->id])
            ->assertStatus(201);

        $memo = MaterialMemo::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame([$this->storeA->id, $staff->id, 'Avanza B 1 XYZ'], [$memo->store_id, $memo->created_by, $memo->vehicle_info]);
        $this->assertMatchesRegularExpression('/^MEMO-20261008-\d{4}$/', $memo->memo_number);

        $ids = collect($this->api($staff)->getJson('/api/staff/memos')->assertSuccessful()->json('data'))->pluck('id')->all();
        $this->assertSame([$memo->id], $ids);

        // Memo toko lain tidak terlihat oleh staf (Global Scope toko): 404, tanpa membocorkan keberadaannya.
        $this->api($staff)->getJson("/api/staff/memos/{$theirs->id}")->assertNotFound();
        $this->api($staff)->patchJson("/api/staff/memos/{$theirs->id}", ['notes' => 'x'])->assertNotFound();
        $this->api($staff)->deleteJson("/api/staff/memos/{$theirs->id}")->assertNotFound();
        $this->assertNotNull(MaterialMemo::withoutGlobalScopes()->find($theirs->id));
        $this->api($staff)->getJson('/api/staff/memos/999999')->assertNotFound();

        $this->api($this->staff('super_admin'))->getJson('/api/staff/memos')->assertJsonCount(2, 'data');
        $this->api($this->staff('super_admin'))->getJson('/api/staff/memos?store_id=' . $this->storeB->id)->assertJsonCount(1, 'data');
    }

    public function test_memo_creation_rules(): void
    {
        $admin = $this->staff('super_admin');

        $this->api($admin)->postJson('/api/staff/memos', [])->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->api($admin)->postJson('/api/staff/memos', ['store_id' => $this->storeB->id])->assertStatus(201);
        $this->assertSame($this->storeB->id, MaterialMemo::withoutGlobalScopes()->firstOrFail()->store_id);

        $noStore = $this->staff('kasir', null, null, false);
        $this->api($noStore)->postJson('/api/staff/memos', [])->assertStatus(422)->assertJsonPath('message', 'Akun ini belum terhubung ke toko manapun.');

        $this->api($this->staff())->postJson('/api/staff/memos', ['vehicle_info' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors('vehicle_info');
    }

    public function test_memo_numbers_stay_unique_across_many_creations(): void
    {
        $staff = $this->staff();

        foreach (range(1, 3) as $i) {
            $this->api($staff)->postJson('/api/staff/memos', [])->assertStatus(201);
        }

        $this->assertSame(['MEMO-20261008-0001', 'MEMO-20261008-0002', 'MEMO-20261008-0003'], MaterialMemo::withoutGlobalScopes()->orderBy('id')->pluck('memo_number')->all());
    }

    public function test_memo_items_move_stock_and_can_be_corrected_returned_and_removed(): void
    {
        $staff = $this->staff();
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $consumable = $this->consumable('Sarung Tangan', 10);
        $scroll = ScrollCode::create(['code' => 'GLN-M', 'status' => 'allocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => 15, 'allocated_at' => now()]);
        $roll = InventoryItem::create(['code' => 'INV-M', 'name' => 'Film', 'category' => 'PPF', 'status' => 'in_stock', 'scroll_code_id' => $scroll->id]);

        $this->api($staff)->postJson("/api/staff/memos/{$memo->id}/items", ['item_type' => 'raw_material', 'item_id' => $material->id, 'qty_taken' => 4])->assertSuccessful();
        $this->api($staff)->postJson("/api/staff/memos/{$memo->id}/items", ['item_type' => 'consumable_item', 'item_id' => $consumable->id, 'qty_taken' => 2, 'condition_notes' => 'Baru'])->assertSuccessful();
        $this->api($staff)->postJson("/api/staff/memos/{$memo->id}/items", ['item_type' => 'inventory_item', 'item_id' => $roll->id, 'meters_used' => 3])->assertSuccessful();

        $this->assertEquals(6, (float) $material->fresh()->current_stock);
        $this->assertEquals(8, (float) $consumable->fresh()->current_stock);
        $this->assertEquals(12, (float) $scroll->fresh()->remaining_length_meters);
        $rawRow = $memo->items()->where('item_type', 'raw_material')->firstOrFail();
        $rollRow = $memo->items()->where('item_type', 'inventory_item')->firstOrFail();

        $this->api($staff)->patchJson("/api/staff/memos/{$memo->id}/items/{$rawRow->id}", ['qty_taken' => 5])->assertSuccessful();
        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $this->api($staff)->patchJson("/api/staff/memos/{$memo->id}/items/{$rollRow->id}", ['meters_used' => 2])->assertSuccessful();
        $this->assertEquals(13, (float) $scroll->fresh()->remaining_length_meters);

        $this->api($staff)->postJson("/api/staff/memos/{$memo->id}/items/{$rawRow->id}/return", ['qty_returned' => 2])->assertSuccessful();
        $this->assertEquals(7, (float) $material->fresh()->current_stock);
        $this->api($staff)->postJson("/api/staff/memos/{$memo->id}/items/{$rawRow->id}/return", ['qty_returned' => 1])->assertStatus(422);

        $consumableRow = $memo->items()->where('item_type', 'consumable_item')->firstOrFail();
        $this->api($staff)->deleteJson("/api/staff/memos/{$memo->id}/items/{$consumableRow->id}")->assertSuccessful();
        $this->assertEquals(10, (float) $consumable->fresh()->current_stock);
        $this->assertNull(MaterialMemoItem::find($consumableRow->id));

        $this->api($this->staff('super_admin'))->deleteJson("/api/staff/memos/{$memo->id}")->assertSuccessful();
        $this->assertNull(MaterialMemo::withoutGlobalScopes()->find($memo->id));
        $this->assertEquals(10, (float) $material->fresh()->current_stock, 'Yang masih keluar (3 dari 5, setelah 2 kembali) dikembalikan.');
        $this->assertEquals(15, (float) $scroll->fresh()->remaining_length_meters);
    }

    public function test_only_full_access_can_delete_a_whole_memo_but_staff_can_still_remove_a_row(): void
    {
        $staff = $this->staff('kasir', $this->storeA);
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $this->api($staff)->postJson("/api/staff/memos/{$memo->id}/items", ['item_type' => 'raw_material', 'item_id' => $material->id, 'qty_taken' => 4])->assertSuccessful();
        $row = $memo->items()->firstOrFail();

        $this->api($staff)->deleteJson("/api/staff/memos/{$memo->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Hanya akun akses penuh yang boleh menghapus memo utuh. Hapus baris barang yang salah, atau hubungi admin.');
        $this->assertNotNull(MaterialMemo::withoutGlobalScopes()->find($memo->id));
        $this->assertEquals(6, (float) $material->fresh()->current_stock, 'Stok tidak berubah karena penghapusan ditolak.');

        $this->api($staff)->deleteJson("/api/staff/memos/{$memo->id}/items/{$row->id}")->assertSuccessful();
        $this->assertEquals(10, (float) $material->fresh()->current_stock);

        $this->api($this->staff('super_admin'))->deleteJson("/api/staff/memos/{$memo->id}")->assertSuccessful();
        $this->assertNull(MaterialMemo::withoutGlobalScopes()->find($memo->id));
    }

    public function test_the_login_profile_tells_the_app_whether_the_delete_button_is_allowed(): void
    {
        $this->api($this->staff('kasir'))->getJson('/api/staff/auth/me')->assertSuccessful()->assertJsonPath('user.can_delete_memo', false);
        $this->api($this->staff('super_admin'))->getJson('/api/staff/auth/me')->assertSuccessful()->assertJsonPath('user.can_delete_memo', true);
    }

    public function test_memo_item_validation_and_errors(): void
    {
        $staff = $this->staff();
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 3);
        $plainRoll = InventoryItem::create(['code' => 'INV-P', 'name' => 'Tanpa Gulungan', 'category' => 'PPF', 'status' => 'in_stock']);
        $url = "/api/staff/memos/{$memo->id}/items";

        $this->api($staff)->postJson($url, ['item_type' => 'laptop', 'item_id' => 1])->assertStatus(422)->assertJsonValidationErrors('item_type');
        $this->api($staff)->postJson($url, ['item_type' => 'raw_material', 'item_id' => $material->id])->assertStatus(422)->assertJsonValidationErrors('qty_taken');
        $this->api($staff)->postJson($url, ['item_type' => 'raw_material', 'item_id' => $material->id, 'qty_taken' => 2000000])->assertStatus(422)->assertJsonValidationErrors('qty_taken');
        $this->api($staff)->postJson($url, ['item_type' => 'inventory_item', 'item_id' => $plainRoll->id])->assertStatus(422)->assertJsonValidationErrors('meters_used');
        $this->api($staff)->postJson($url, ['item_type' => 'raw_material', 'item_id' => 999999, 'qty_taken' => 1])->assertNotFound();
        $this->api($staff)->postJson($url, ['item_type' => 'raw_material', 'item_id' => $material->id, 'qty_taken' => 5])->assertStatus(422);
        $this->api($staff)->postJson($url, ['item_type' => 'inventory_item', 'item_id' => $plainRoll->id, 'meters_used' => 1])->assertStatus(422);

        $this->assertSame(0, $memo->items()->count());
        $this->assertEquals(3, (float) $material->fresh()->current_stock);

        $this->api($staff)->postJson("/api/staff/memos/{$memo->id}/items/999999/return", ['qty_returned' => 1])->assertNotFound();
        $this->api($staff)->patchJson("/api/staff/memos/{$memo->id}/items/999999", ['qty_taken' => 1])->assertNotFound();
        $this->api($staff)->deleteJson("/api/staff/memos/{$memo->id}/items/999999")->assertNotFound();
    }

    // ------------------------------------------------------------- permohonan pembelian

    public function test_purchase_request_endpoints_need_a_token_and_the_menu(): void
    {
        $noMenu = $this->staff('kasir', null, ['BookingResource']);

        $this->getJson('/api/staff/purchase-requests')->assertStatus(401);
        $this->api($noMenu)->getJson('/api/staff/purchase-requests')->assertStatus(403);
        $this->api($noMenu)->postJson('/api/staff/purchase-requests', ['item_type' => 'asset', 'item_name' => 'X', 'quantity' => 1])->assertStatus(403);
    }

    public function test_staff_submit_a_request_snapshotting_the_catalogue_and_forcing_their_store(): void
    {
        $staff = $this->staff('kasir', $this->storeA);
        $material = $this->material('Slip Solution');

        $response = $this->api($staff)->postJson('/api/staff/purchase-requests', ['item_type' => 'raw_material', 'item_id' => $material->id, 'quantity' => 6, 'reason' => 'Stok menipis', 'store_id' => $this->storeB->id])
            ->assertStatus(201);

        $request = PurchaseRequest::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame([$this->storeA->id, 'Slip Solution', 'liter', 'pending', $staff->id], [$request->store_id, $request->item_name, $request->unit, $request->status, $request->requested_by]);
        $this->assertMatchesRegularExpression('/^PR-202610-[A-Z0-9]{4}$/', $request->request_number);
        $this->assertEquals(6, $response->json('data.quantity'));

        $this->api($staff)->postJson('/api/staff/purchase-requests', ['item_type' => 'asset', 'item_name' => 'Kompresor', 'quantity' => 1])->assertStatus(201);
        $this->assertNull(PurchaseRequest::withoutGlobalScopes()->where('item_name', 'Kompresor')->firstOrFail()->item_id);
    }

    public function test_purchase_request_validation_and_missing_catalogue_items(): void
    {
        $staff = $this->staff();
        $url = '/api/staff/purchase-requests';

        $this->api($staff)->postJson($url, ['item_type' => 'laptop', 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('item_type');
        $this->api($staff)->postJson($url, ['item_type' => 'asset', 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('item_name');
        $this->api($staff)->postJson($url, ['item_type' => 'raw_material', 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('item_id');
        $this->api($staff)->postJson($url, ['item_type' => 'asset', 'item_name' => 'X', 'quantity' => 0])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->api($staff)->postJson($url, ['item_type' => 'asset', 'item_name' => 'X', 'quantity' => 2000000])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->api($staff)->postJson($url, ['item_type' => 'raw_material', 'item_id' => 999999, 'quantity' => 1])->assertNotFound();
        $this->api($staff)->postJson($url, ['item_type' => 'consumable_item', 'item_id' => 999999, 'quantity' => 1])->assertNotFound();
        $this->api($this->staff('super_admin'))->postJson($url, ['item_type' => 'asset', 'item_name' => 'X', 'quantity' => 1, 'store_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('store_id');

        $noStore = $this->staff('kasir', null, null, false);
        $this->api($noStore)->postJson($url, ['item_type' => 'asset', 'item_name' => 'X', 'quantity' => 1])->assertStatus(422);
        $this->assertSame(0, PurchaseRequest::withoutGlobalScopes()->count());
    }

    public function test_the_request_list_is_scoped_and_paged(): void
    {
        $mine = $this->staff('kasir', $this->storeA);
        foreach (range(1, 52) as $i) {
            PurchaseRequest::create(['store_id' => $this->storeA->id, 'item_type' => 'asset', 'item_name' => "Barang {$i}", 'quantity' => 1, 'status' => 'pending', 'requested_by' => $mine->id]);
        }
        PurchaseRequest::create(['store_id' => $this->storeB->id, 'item_type' => 'asset', 'item_name' => 'Punya B', 'quantity' => 1, 'status' => 'pending', 'requested_by' => $mine->id]);

        $first = $this->api($mine)->getJson('/api/staff/purchase-requests')->assertSuccessful();
        $this->assertCount(50, $first->json('data'));
        $this->assertTrue($first->json('has_more'));
        $this->assertNotContains('Punya B', collect($first->json('data'))->pluck('item_name')->all());

        $second = $this->api($mine)->getJson('/api/staff/purchase-requests?offset=50')->assertSuccessful();
        $this->assertCount(2, $second->json('data'));
        $this->assertFalse($second->json('has_more'));

        $this->assertSame(1, count($this->api($this->staff('super_admin'))->getJson('/api/staff/purchase-requests?store_id=' . $this->storeB->id)->json('data')));
    }

    // ------------------------------------------------------------- aset

    private function asset(string $name, ?Store $store, array $extra = []): Asset
    {
        return Asset::create(array_merge(['asset_tag' => Asset::generateAssetTag(), 'name' => $name, 'category' => 'Mesin', 'status' => 'aktif', 'store_id' => $store?->id, 'received_date' => '2026-10-01'], $extra));
    }

    public function test_asset_endpoints_need_a_token_and_the_menu(): void
    {
        $asset = $this->asset('Kompresor', $this->storeA);
        $noMenu = $this->staff('kasir', null, ['BookingResource']);

        $this->getJson('/api/staff/assets')->assertStatus(401);
        $this->api($noMenu)->getJson('/api/staff/assets')->assertStatus(403);
        $this->api($noMenu)->getJson("/api/staff/assets/{$asset->asset_tag}")->assertStatus(403);
        $this->api($noMenu)->postJson("/api/staff/assets/{$asset->asset_tag}/update", ['status' => 'rusak'])->assertStatus(403);
        $this->assertSame('aktif', $asset->fresh()->status);
    }

    public function test_asset_search_never_leaks_other_stores_even_when_matching_by_tag(): void
    {
        $mine = $this->asset('Kompresor A', $this->storeA);
        $theirs = $this->asset('Kompresor B', $this->storeB);
        $headOffice = $this->asset('Kompresor Pusat', null);
        $staff = $this->staff('kasir', $this->storeA);

        $names = fn (string $query) => collect($this->api($staff)->getJson('/api/staff/assets' . $query)->assertSuccessful()->json('data'))->pluck('name')->all();

        $this->assertSame(['Kompresor A'], $names(''));
        $this->assertSame(['Kompresor A'], $names('?search=Kompresor'));
        $this->assertSame([], $names('?search=' . $theirs->asset_tag), 'Kode aset toko lain tidak boleh ditemukan.');
        $this->assertSame([], $names('?search=' . $headOffice->asset_tag));
        $this->assertSame(['Kompresor A'], $names('?search=' . $mine->asset_tag));

        $admin = $this->staff('super_admin');
        $this->assertCount(3, $this->api($admin)->getJson('/api/staff/assets')->json('data'));
        $this->assertSame(['Kompresor B'], collect($this->api($admin)->getJson('/api/staff/assets?search=' . $theirs->asset_tag)->json('data'))->pluck('name')->all());
    }

    public function test_assigned_to_me_filter_cannot_be_bypassed_by_searching(): void
    {
        $staff = $this->staff('kasir', $this->storeA);
        $other = $this->staff('kasir', $this->storeA);
        $assigned = $this->asset('Bor Saya', $this->storeA, ['assigned_to' => $staff->id]);
        $notMine = $this->asset('Bor Orang', $this->storeA, ['assigned_to' => $other->id]);

        $names = collect($this->api($staff)->getJson('/api/staff/assets?assigned_to_me=1&search=' . $notMine->asset_tag)->json('data'))->pluck('name')->all();
        $this->assertSame([], $names);

        $names = collect($this->api($staff)->getJson('/api/staff/assets?assigned_to_me=1')->json('data'))->pluck('name')->all();
        $this->assertSame(['Bor Saya'], $names);
    }

    public function test_asset_detail_is_hidden_for_other_stores(): void
    {
        $mine = $this->asset('Kompresor A', $this->storeA);
        $theirs = $this->asset('Kompresor B', $this->storeB);
        $staff = $this->staff('kasir', $this->storeA);

        $this->api($staff)->getJson("/api/staff/assets/{$mine->asset_tag}")->assertSuccessful()->assertJsonPath('data.name', 'Kompresor A');
        $this->api($staff)->getJson("/api/staff/assets/{$theirs->asset_tag}")->assertNotFound();
        $this->api($staff)->getJson('/api/staff/assets/ASSET-NOPE')->assertNotFound();
        $this->api($this->staff('super_admin'))->getJson("/api/staff/assets/{$theirs->asset_tag}")->assertSuccessful();
    }

    public function test_updating_an_asset_status_notes_and_store(): void
    {
        $asset = $this->asset('Kompresor A', $this->storeA, ['notes' => 'Catatan lama']);
        $staff = $this->staff('kasir', $this->storeA);

        $this->api($staff)->postJson("/api/staff/assets/{$asset->asset_tag}/update", ['status' => 'diperbaiki', 'store_id' => $this->storeB->id])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Aset berhasil diperbarui.');
        $fresh = $asset->fresh();
        $this->assertSame(['diperbaiki', $this->storeA->id, 'Catatan lama'], [$fresh->status, $fresh->store_id, $fresh->notes], 'Staff tidak bisa memindah toko; catatan tidak tersentuh bila tidak dikirim.');

        $this->api($staff)->postJson("/api/staff/assets/{$asset->asset_tag}/update", ['status' => 'aktif', 'notes' => 'Sudah beres'])->assertSuccessful();
        $this->assertSame('Sudah beres', $asset->fresh()->notes);

        $this->api($this->staff('super_admin'))->postJson("/api/staff/assets/{$asset->asset_tag}/update", ['status' => 'aktif', 'store_id' => $this->storeB->id])->assertSuccessful();
        $this->assertSame($this->storeB->id, $asset->fresh()->store_id);

        $this->assertNotNull(Activity::where('log_name', 'asset')->where('subject_id', $asset->id)->where('description', 'like', '%updated%')->first()
            ?? Activity::where('log_name', 'asset')->where('subject_id', $asset->id)->latest('id')->first());
    }

    public function test_asset_update_validation_and_other_stores(): void
    {
        $theirs = $this->asset('Kompresor B', $this->storeB);
        $mine = $this->asset('Kompresor A', $this->storeA);
        $staff = $this->staff('kasir', $this->storeA);

        $this->api($staff)->postJson("/api/staff/assets/{$mine->asset_tag}/update", ['status' => 'terbang'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->api($staff)->postJson("/api/staff/assets/{$mine->asset_tag}/update", [])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->api($staff)->postJson("/api/staff/assets/{$mine->asset_tag}/update", ['status' => 'aktif', 'notes' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->api($this->staff('super_admin'))->postJson("/api/staff/assets/{$mine->asset_tag}/update", ['status' => 'aktif', 'store_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->api($staff)->postJson("/api/staff/assets/{$theirs->asset_tag}/update", ['status' => 'rusak'])->assertNotFound();
        $this->assertSame('aktif', $theirs->fresh()->status);
    }
}
