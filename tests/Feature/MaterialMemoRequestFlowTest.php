<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\RawMaterial;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use App\Services\MaterialMemoRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Memo barang berpersetujuan (2026-11-17): installer mengajukan tanpa daftar barang, store manager menyetujui, gudang mencatat barang dan
 * jumlah yang benar-benar diambil lalu menekan Selesai diserahkan (terkunci), direksi diberi tahu. Meter gulungan PPF/WF diisi belakangan.
 */
class MaterialMemoRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private User $installer;

    private User $manager;

    private User $warehouse;

    private User $director;

    private RawMaterial $material;

    private MaterialMemoRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('installer_leader', 'web');
        RawMaterial::query()->delete();
        InventoryItem::query()->delete();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->other = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->installer = $this->user('installer', $this->store);
        $this->manager = $this->user('store_manager', $this->store);
        $this->warehouse = $this->user('kasir', $this->store, ['RawMaterialResource', 'InventoryItemResource'], ['RawMaterialResource' => ['update', 'view'], 'InventoryItemResource' => ['update', 'view']]);
        $this->director = $this->user('direksi', null);
        $this->material = RawMaterial::create(['name' => 'Cairan Slip', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 10]);
        $this->service = app(MaterialMemoRequestService::class);
    }

    private function user(string $role, ?Store $store, ?array $menus = null, ?array $permissions = null): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id, 'menu_access' => $menus, 'menu_permissions' => $permissions, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Memo yang dibuat installer: langsung menunggu persetujuan. */
    private function pending(): MaterialMemo
    {
        $memo = MaterialMemo::create(['memo_number' => 'MEMO-T-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $this->installer->id, 'vehicle_info' => 'Avanza']);

        return $this->service->submitNew($memo, 'PPF full body');
    }

    private function approved(): MaterialMemo
    {
        $memo = $this->pending();
        $this->service->approve($memo, $this->manager);

        return $memo->fresh();
    }

    private function roll(): InventoryItem
    {
        $scroll = ScrollCode::create(['code' => 'SC-' . uniqid(), 'store_id' => $this->store->id, 'status' => 'allocated', 'total_length_meters' => 15, 'remaining_length_meters' => 15]);

        return InventoryItem::create(['code' => 'INV-' . uniqid(), 'name' => 'Film PPF', 'category' => 'PPF', 'scroll_code_id' => $scroll->id, 'status' => 'in_stock']);
    }

    public function test_the_request_has_no_items_and_stock_moves_only_when_the_warehouse_records_what_was_taken(): void
    {
        $memo = $this->pending();
        $this->assertSame('pending', $memo->request_status);
        $this->assertSame(0, $memo->items()->count(), 'Pengajuan tidak berisi barang atau jumlah.');

        $this->service->approve($memo, $this->manager);
        $this->assertEquals(10, (float) $this->material->fresh()->current_stock, 'Disetujui pun belum mengubah stok.');

        $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 2.5, 'untuk PPF');
        $this->assertEquals(7.5, (float) $this->material->fresh()->current_stock, 'Stok berkurang saat gudang mencatat.');
        $movement = $this->material->movements()->where('type', 'out')->first();
        $this->assertSame($this->warehouse->id, (int) $movement->user_id);

        // Tambahan di tengah penyerahan: jumlah bebas, sebelum Selesai.
        $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 1, null);
        $this->assertEquals(6.5, (float) $this->material->fresh()->current_stock);
    }

    public function test_complete_locks_the_memo_even_for_accounts_with_edit_permission(): void
    {
        $memo = $this->approved();
        $item = $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 2, null);

        $this->service->complete($memo, $this->warehouse);
        $memo->refresh();
        $this->assertSame('fulfilled', $memo->request_status);
        $this->assertTrue($memo->isHandoverLocked());
        $this->assertTrue($memo->isLocked());

        foreach ([
            fn () => $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 1, null),
            fn () => $this->service->updateItemQty($memo, $item, $this->warehouse, 5),
            fn () => $this->service->removeItem($memo, $item, $this->warehouse),
        ] as $edit) {
            try {
                $edit();
                $this->fail('Memo terkunci, seharusnya ditolak.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('terkunci', $e->getMessage());
            }
        }

        $this->assertEquals(8, (float) $this->material->fresh()->current_stock);

        // Akses penuh bisa membuka kunci lewat unlocked_at yang sudah ada.
        $memo->update(['unlocked_at' => now()]);
        $this->assertFalse($memo->fresh()->isHandoverLocked());
    }

    public function test_completing_needs_at_least_one_recorded_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->complete($this->approved(), $this->warehouse);
    }

    public function test_only_the_stores_manager_or_full_access_can_decide_and_never_the_requester(): void
    {
        $memo = $this->pending();
        $otherManager = $this->user('store_manager', $this->other);

        foreach ([$otherManager, $this->installer, $this->warehouse] as $outsider) {
            try {
                $this->service->approve($memo, $outsider);
                $this->fail('Seharusnya ditolak.');
            } catch (InvalidArgumentException) {
                $this->assertSame('pending', $memo->fresh()->request_status);
            }
        }

        $this->service->approve($memo, $this->director);
        $this->assertSame('approved', $memo->fresh()->request_status);
    }

    public function test_rejection_needs_a_reason_and_the_requester_can_resubmit(): void
    {
        $memo = $this->pending();

        try {
            $this->service->reject($memo, $this->manager, '  ');
            $this->fail('Alasan wajib.');
        } catch (InvalidArgumentException) {
        }

        $this->service->reject($memo, $this->manager, 'Belum waktunya');
        $this->assertSame('rejected', $memo->fresh()->request_status);
        $this->assertSame('Belum waktunya', $memo->fresh()->reject_reason);

        $this->service->resubmit($memo, $this->installer, 'Sudah waktunya');
        $this->assertSame('pending', $memo->fresh()->request_status);
        $this->assertNull($memo->fresh()->reject_reason);
    }

    public function test_the_warehouse_must_hold_a_stock_menu_and_cannot_be_the_requester(): void
    {
        $memo = $this->approved();

        foreach ([$this->installer, $this->manager] as $notWarehouse) {
            try {
                $this->service->addItem($memo, $notWarehouse, 'raw_material', $this->material->id, 1, null);
                $this->fail('Tanpa menu barangnya tidak boleh mencatat penyerahan.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertEquals(10, (float) $this->material->fresh()->current_stock);
    }

    public function test_items_cannot_be_recorded_before_approval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->addItem($this->pending(), $this->warehouse, 'raw_material', $this->material->id, 1, null);
    }

    public function test_insufficient_stock_is_refused_and_changes_nothing(): void
    {
        $memo = $this->approved();

        try {
            $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 50, null);
            $this->fail('Stok tidak cukup.');
        } catch (InvalidArgumentException) {
        }

        $this->assertEquals(10, (float) $this->material->fresh()->current_stock);
        $this->assertSame(0, $memo->items()->count());
    }

    public function test_asking_for_more_after_handover_needs_a_note_and_runs_the_same_flow_and_relocks(): void
    {
        $memo = $this->approved();
        $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 2, null);
        $this->service->complete($memo, $this->warehouse);

        try {
            $this->service->resubmit($memo, $this->installer, '');
            $this->fail('Catatan tambahan wajib.');
        } catch (InvalidArgumentException) {
        }

        $this->service->resubmit($memo, $this->installer, 'Cairan kurang');
        $this->assertSame('pending', $memo->fresh()->request_status);
        $this->service->approve($memo, $this->manager);
        $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 1.5, null);
        $this->service->complete($memo, $this->warehouse);

        $this->assertEquals(6.5, (float) $this->material->fresh()->current_stock);
        $this->assertTrue($memo->fresh()->isHandoverLocked());
        $this->assertCount(2, $memo->items);
    }

    public function test_a_roll_is_handed_over_without_meters_and_the_meters_are_filled_in_after_the_job(): void
    {
        $roll = $this->roll();
        $scroll = ScrollCode::find($roll->scroll_code_id);
        $memo = $this->approved();

        $item = $this->service->addItem($memo, $this->warehouse, 'inventory_item', $roll->id, null, null);
        $this->assertNull($item->meters_used);
        $this->assertEquals(15, (float) $scroll->fresh()->remaining_length_meters, 'Sisa gulungan belum berkurang tanpa meter.');

        $this->service->complete($memo, $this->warehouse);
        $this->assertTrue($memo->fresh()->isHandoverLocked());

        // Tetap bisa diisi saat terkunci, tetapi hanya oleh staff gudang: installer pengaju dan store manager ditolak.
        foreach ([$this->installer, $this->manager] as $notWarehouse) {
            try {
                $this->service->setMeters($memo->fresh(), $item, $notWarehouse, 3);
                $this->fail('Bukan staff gudang.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->service->setMeters($memo->fresh(), $item, $this->warehouse, 3.5);
        $this->assertEquals(11.5, (float) $scroll->fresh()->remaining_length_meters);
        $this->assertEquals(3.5, (float) $item->fresh()->meters_used);
        $this->assertNotNull($item->fresh()->scroll_code_usage_id);

        try {
            $this->service->setMeters($memo->fresh(), $item->fresh(), $this->warehouse, 1);
            $this->fail('Meter hanya diisi sekali.');
        } catch (InvalidArgumentException) {
        }
    }

    public function test_the_approval_center_lists_pending_memos_for_the_stores_manager_and_decides_them_inline(): void
    {
        $memo = $this->pending();
        $center = app(\App\Services\ApprovalCenterService::class);
        $otherManager = $this->user('store_manager', $this->other);

        $this->assertCount(1, $center->items($this->manager)->where('source', 'material_memo'));
        $this->assertCount(1, $center->items($this->director)->where('source', 'material_memo'));
        $this->assertCount(0, $center->items($otherManager)->where('source', 'material_memo'), 'Store manager toko lain tidak melihatnya.');
        $this->assertCount(0, $center->items($this->installer)->where('source', 'material_memo'));

        $center->source('material_memo')->approve($memo->id, $this->manager);
        $this->assertSame('approved', $memo->fresh()->request_status);

        $this->assertCount(0, $center->items($this->manager)->where('source', 'material_memo'));
        $handover = $center->items($this->warehouse)->where('source', 'material_memo_handover');
        $this->assertCount(1, $handover, 'Gudang melihatnya sebagai menunggu diserahkan.');
        $this->assertFalse($handover->first()->inline, 'Penyerahan dicatat di halaman memo, bukan satu klik.');

        $this->expectException(\RuntimeException::class);
        $center->source('material_memo')->approve($memo->id, $this->manager);
    }

    public function test_the_warehouse_records_returned_leftovers_even_after_the_memo_is_locked(): void
    {
        $memo = $this->approved();
        $item = $this->service->addItem($memo, $this->warehouse, 'raw_material', $this->material->id, 4, null);
        $this->service->complete($memo, $this->warehouse);
        $this->assertTrue($memo->fresh()->isHandoverLocked());
        $this->assertEquals(6, (float) $this->material->fresh()->current_stock);

        try {
            $this->service->recordReturn($memo->fresh(), $item, $this->installer, 1);
            $this->fail('Pengaju tidak mencatat pengembalian.');
        } catch (InvalidArgumentException) {
        }

        $this->actingAs($this->warehouse, 'api')->postJson("/api/staff/memos/{$memo->id}/handover/{$item->id}/return", ['qty_returned' => 1.5])->assertOk();
        $this->assertEquals(7.5, (float) $this->material->fresh()->current_stock, 'Bahan sisa kembali ke stok.');
    }

    public function test_installers_request_memos_without_the_memo_menu_and_only_see_their_own(): void
    {
        $leader = $this->user('installer_leader', $this->store);
        $this->assertTrue($this->installer->canRequestMemo());
        $this->assertTrue($leader->canRequestMemo());
        $this->assertFalse($this->manager->canRequestMemo());
        $this->assertFalse($this->installer->hasMaterialMemoAccess(), 'Tanpa menu Memo di Hak Akses.');

        $mine = $this->actingAs($this->installer, 'api')->postJson('/api/staff/memos', ['vehicle_brand' => 'Toyota', 'vehicle_model' => 'Avanza', 'vehicle_plate' => 'b 1234 xyz'])
            ->assertCreated()->assertJsonPath('data.request_status', 'pending')->assertJsonPath('data.vehicle_plate', 'B 1234 XYZ')
            ->assertJsonPath('data.vehicle_info', 'Toyota Avanza - B 1234 XYZ')->json('data.id');

        // Merek, tipe, dan plat wajib diisi installer.
        $this->actingAs($this->installer, 'api')->postJson('/api/staff/memos', ['vehicle_info' => 'Avanza'])
            ->assertStatus(422)->assertJsonValidationErrors(['vehicle_brand', 'vehicle_model', 'vehicle_plate']);
        $leadersMemo = $this->actingAs($leader, 'api')->postJson('/api/staff/memos', ['vehicle_brand' => 'Toyota', 'vehicle_model' => 'Innova', 'vehicle_plate' => 'D 1 AB'])->assertCreated()->json('data.id');

        $list = $this->actingAs($this->installer, 'api')->getJson('/api/staff/memos')->assertOk();
        $this->assertSame([$mine], collect($list->json('data'))->pluck('id')->all(), 'Hanya memo sendiri.');
        $this->actingAs($this->installer, 'api')->getJson("/api/staff/memos/{$mine}")->assertOk();
        $this->actingAs($this->installer, 'api')->getJson("/api/staff/memos/{$leadersMemo}")->assertForbidden();

        // Tidak boleh mengubah barang atau memo walau lewat endpoint memo biasa.
        $this->actingAs($this->installer, 'api')->patchJson("/api/staff/memos/{$mine}", ['vehicle_info' => 'Lain'])->assertForbidden();

        // Staf biasa tanpa menu Memo tetap ditolak.
        $plain = $this->user('kasir', $this->store);
        $this->actingAs($plain, 'api')->postJson('/api/staff/memos', [])->assertForbidden();
    }

    public function test_the_api_runs_the_whole_flow_for_a_staff_requester(): void
    {
        $staff = $this->user('kasir', $this->store, ['MaterialMemoResource'], ['MaterialMemoResource' => ['create', 'update', 'view']]);

        $memoId = $this->actingAs($staff, 'api')->postJson('/api/staff/memos', ['vehicle_info' => 'Avanza', 'request_note' => 'PPF'])
            ->assertCreated()->assertJsonPath('data.flow', 'request')->assertJsonPath('data.request_status', 'pending')->json('data.id');

        // Pengaju tidak mencatat barang sendiri.
        $this->actingAs($staff, 'api')->postJson("/api/staff/memos/{$memoId}/items", ['item_type' => 'raw_material', 'item_id' => $this->material->id, 'qty_taken' => 4])->assertStatus(422);

        $this->actingAs($this->manager, 'api')->getJson('/api/staff/memos/inbox')->assertOk()->assertJsonCount(1, 'data.to_approve');
        $this->actingAs($this->warehouse, 'api')->postJson("/api/staff/memos/{$memoId}/approve")->assertStatus(422);
        $this->actingAs($this->manager, 'api')->postJson("/api/staff/memos/{$memoId}/approve")->assertOk()->assertJsonPath('data.request_status', 'approved');

        $this->actingAs($this->warehouse, 'api')->getJson('/api/staff/memos/inbox')->assertOk()->assertJsonCount(1, 'data.to_hand_over');
        $this->actingAs($this->warehouse, 'api')->postJson("/api/staff/memos/{$memoId}/handover", ['item_type' => 'raw_material', 'item_id' => $this->material->id, 'qty' => 3])->assertOk();
        $this->actingAs($this->warehouse, 'api')->postJson("/api/staff/memos/{$memoId}/complete")->assertOk()->assertJsonPath('data.is_locked', true);

        $this->assertEquals(7, (float) $this->material->fresh()->current_stock);

        // Terkunci: pengubahan lewat endpoint memo biasa ditolak, pengembalian bahan tetap bisa.
        $itemId = MaterialMemo::find($memoId)->items()->first()->id;
        $this->actingAs($staff, 'api')->patchJson("/api/staff/memos/{$memoId}/items/{$itemId}", ['qty_taken' => 9])->assertStatus(422);
        $this->actingAs($staff, 'api')->postJson("/api/staff/memos/{$memoId}/items/{$itemId}/return", ['qty_returned' => 1])->assertOk();
        $this->assertEquals(8, (float) $this->material->fresh()->current_stock);
    }
}
