<?php

namespace Tests\Feature;

use App\Models\MaterialMemo;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\User;
use App\Services\MaterialMemoStockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Memo yang dibuat staf gudang sendiri (alur langsung): store manager toko itu diberi tahu satu kali per memo (2026-11-24).
 */
class MemoDirectNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, ?Store $store = null): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function material(string $name): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'unit' => 'ml', 'current_stock' => 1000, 'reorder_point' => 0, 'created_by' => $this->user('super_admin')->id]);
    }

    private function memo(User $creator, array $extra = []): MaterialMemo
    {
        return MaterialMemo::create(array_merge(['memo_number' => 'MEMO-N-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $creator->id, 'vehicle_info' => 'Innova B 1234 XYZ'], $extra));
    }

    private function inboxCount(User $user): int
    {
        return $user->notifications()->where('data->title', 'Memo Barang Dibuat Gudang')->count();
    }

    public function test_the_store_manager_is_told_once_per_memo_a_warehouse_staff_made_himself(): void
    {
        $warehouse = $this->user('kasir');
        $manager = $this->user('store_manager');
        $otherManager = $this->user('store_manager', $this->otherStore);
        $memo = $this->memo($warehouse);

        MaterialMemoStockService::addMaterial($this->material('Lem A'), 'raw_material', $memo, 10, $warehouse->id, null);
        MaterialMemoStockService::addMaterial($this->material('Lem B'), 'raw_material', $memo->fresh(), 5, $warehouse->id, null);

        $this->assertSame(1, $this->inboxCount($manager), 'Satu pemberitahuan per memo, bukan per barang.');
        $this->assertSame(0, $this->inboxCount($otherManager), 'Store manager toko lain tidak diberi tahu.');
        $this->assertSame(0, $this->inboxCount($warehouse), 'Pembuat memo tidak diberi tahu.');

        $body = $manager->notifications()->first()->data['body'] ?? '';
        $this->assertStringContainsString($warehouse->name, $body);
        $this->assertStringContainsString('Lem A', $body);
        $this->assertStringContainsString('Toko A', $body);
        $this->assertStringContainsString('Innova B 1234 XYZ', $body);

        // Memo lain dari gudang yang sama: pemberitahuan baru.
        $second = $this->memo($warehouse);
        MaterialMemoStockService::addMaterial($this->material('Lem C'), 'raw_material', $second, 3, $warehouse->id, null);
        $this->assertSame(2, $this->inboxCount($manager));
    }

    public function test_no_notification_for_request_flow_memos_or_memos_made_by_a_store_manager_or_full_access(): void
    {
        $warehouse = $this->user('kasir');
        $manager = $this->user('store_manager');
        $material = $this->material('Lem A');

        $requestMemo = $this->memo($warehouse, ['flow' => MaterialMemo::FLOW_REQUEST, 'request_status' => MaterialMemo::REQ_APPROVED]);
        MaterialMemoStockService::addMaterial($material, 'raw_material', $requestMemo, 10, $warehouse->id, null);

        $byManager = $this->memo($this->user('store_manager'));
        MaterialMemoStockService::addMaterial($material->fresh(), 'raw_material', $byManager, 10, $manager->id, null);

        $admin = $this->user('super_admin');
        $byAdmin = $this->memo($admin);
        MaterialMemoStockService::addMaterial($material->fresh(), 'raw_material', $byAdmin, 10, $admin->id, null);

        $this->assertSame(0, $this->inboxCount($manager));
    }
}
