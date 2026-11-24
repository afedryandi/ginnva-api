<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialMemoResource\Pages\ViewMaterialMemo;
use App\Models\MaterialMemo;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\User;
use App\Services\MaterialMemoRequestService;
use App\Services\MaterialMemoStockService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Memo buatan staf gudang sendiri (alur langsung) ditutup setelah pengambilan pertama; pengambilan berikutnya memakai aturan tambahan yang sama:
 * disetujui dan dicatat admin atau spv finance (2026-11-24).
 */
class MemoDirectCloseTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $warehouse;

    private User $admin;

    private User $manager;

    private RawMaterial $material;

    private MaterialMemoRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $menus = ['RawMaterialResource', 'MaterialMemoResource'];
        $permissions = ['RawMaterialResource' => ['update', 'view'], 'MaterialMemoResource' => ['create', 'update', 'view']];
        $this->warehouse = $this->user('kasir', $menus, $permissions);
        $this->admin = $this->user('admin', $menus, $permissions);
        $this->manager = $this->user('store_manager', null, null);
        $this->material = RawMaterial::create(['name' => 'Cairan Slip', 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 10]);
        $this->service = app(MaterialMemoRequestService::class);
    }

    private function user(string $role, ?array $menus, ?array $permissions): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menus, 'menu_permissions' => $permissions, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Memo langsung buatan gudang dengan satu barang sudah diambil. */
    private function directMemoWithItem(): MaterialMemo
    {
        $memo = MaterialMemo::create(['memo_number' => 'MEMO-D-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $this->warehouse->id, 'vehicle_info' => 'Innova']);
        MaterialMemoStockService::addMaterial($this->material, 'raw_material', $memo, 2, $this->warehouse->id, null);

        return $memo->fresh();
    }

    public function test_only_the_creator_can_close_a_direct_memo_that_has_items(): void
    {
        $empty = MaterialMemo::create(['memo_number' => 'MEMO-D-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $this->warehouse->id]);
        $memo = $this->directMemoWithItem();
        $director = $this->user('direksi', null, null);

        $this->assertFalse($this->service->canCloseDirect($this->warehouse, $empty), 'Memo tanpa barang tidak bisa ditutup.');
        $this->assertTrue($this->service->canCloseDirect($this->warehouse, $memo));
        $this->assertFalse($this->service->canCloseDirect($this->admin, $memo), 'Bukan pembuatnya.');
        $this->assertFalse($this->service->canCloseDirect($director, $memo), 'Akses penuh tetap bebas mengubah memo langsungnya.');

        $adminMemo = MaterialMemo::create(['memo_number' => 'MEMO-D-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $director->id]);
        MaterialMemoStockService::addMaterial($this->material->fresh(), 'raw_material', $adminMemo, 1, $director->id, null);
        $this->assertFalse($this->service->canCloseDirect($director, $adminMemo->fresh()));
    }

    public function test_closing_locks_the_memo_and_further_withdrawals_follow_the_addition_rules(): void
    {
        $memo = $this->directMemoWithItem();

        $closed = $this->service->closeDirect($memo, $this->warehouse);

        $this->assertTrue($closed->isRequestFlow());
        $this->assertSame('fulfilled', $closed->request_status);
        $this->assertTrue($closed->isHandoverLocked(), 'Memo terkunci setelah ditutup.');
        $this->assertSame($this->warehouse->id, (int) $closed->fulfilled_by);
        $this->assertCount(1, $closed->items);

        // Tidak bisa ditutup dua kali.
        try {
            $this->service->closeDirect($memo, $this->warehouse);
            $this->fail('Seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah ditutup', $e->getMessage());
        }

        // Pengambilan kedua: Minta Tambahan oleh pembuatnya, disetujui dan dicatat admin, bukan store manager atau gudang.
        $this->service->resubmit($memo, $this->warehouse, 'Kurang satu liter');
        $memo = $memo->fresh();
        $this->assertSame(1, (int) $memo->addition_round);
        $this->assertFalse($this->service->canApprove($this->manager, $memo));

        $this->service->approve($memo, $this->admin);
        $memo = $memo->fresh();
        $this->assertFalse($this->service->canHandOver($this->warehouse, $memo));

        $this->service->addItem($memo, $this->admin, 'raw_material', $this->material->id, 1, null);
        $this->service->complete($memo, $this->admin);

        $this->assertEquals(7.0, (float) $this->material->fresh()->current_stock);
        $this->assertTrue($memo->fresh()->isHandoverLocked());
    }

    public function test_the_api_closes_the_memo_and_reports_can_close(): void
    {
        $memo = $this->directMemoWithItem();

        $this->actingAs($this->warehouse, 'api')->getJson("/api/staff/memos/{$memo->id}")->assertSuccessful()->assertJsonPath('can_close', true);
        $this->actingAs($this->admin, 'api')->getJson("/api/staff/memos/{$memo->id}")->assertSuccessful()->assertJsonPath('can_close', false);

        $this->actingAs($this->admin, 'api')->postJson("/api/staff/memos/{$memo->id}/close")->assertStatus(422);
        $this->actingAs($this->warehouse, 'api')->postJson("/api/staff/memos/{$memo->id}/close")->assertSuccessful();

        $this->assertTrue($memo->fresh()->isHandoverLocked());
        $this->actingAs($this->warehouse, 'api')->getJson("/api/staff/memos/{$memo->id}")->assertJsonPath('can_close', false);
        $this->actingAs($this->warehouse, 'api')->postJson("/api/staff/memos/{$memo->id}/close")->assertStatus(422);
    }

    public function test_the_panel_shows_close_memo_only_to_the_creator_and_closes_it(): void
    {
        $memo = $this->directMemoWithItem();

        $this->actingAs($this->admin, 'web');
        Livewire::test(ViewMaterialMemo::class, ['record' => $memo->getKey()])->assertActionHidden('close_direct');

        $this->actingAs($this->warehouse, 'web');
        Livewire::test(ViewMaterialMemo::class, ['record' => $memo->getKey()])
            ->assertActionVisible('close_direct')
            ->callAction('close_direct');

        $this->assertTrue($memo->fresh()->isHandoverLocked());
    }
}
