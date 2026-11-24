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
 * Kelipatan Pengambilan bahan baku (2026-11-23): pengambilan lewat memo dan Catat Stok Keluar harus kelipatan; pengembalian, stok terbuang,
 * dan opname tidak dicek; akses penuh boleh di luar kelipatan dengan catatan alasan.
 */
class WithdrawalMultipleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function writer(): User
    {
        return $this->user('kasir', ['RawMaterialResource', 'MaterialMemoResource'], ['menu_permissions' => ['RawMaterialResource' => ['create', 'update', 'view'], 'MaterialMemoResource' => ['create', 'update', 'view']]]);
    }

    private function material(?float $multiple, float $stock = 5000): RawMaterial
    {
        return RawMaterial::create(['name' => 'Lem Kelipatan', 'unit' => 'ml', 'current_stock' => $stock, 'reorder_point' => 0, 'withdrawal_multiple' => $multiple, 'received_date' => '2026-11-23', 'created_by' => $this->user('super_admin')->id]);
    }

    public function test_the_helper_accepts_only_whole_multiples_and_free_materials_accept_anything(): void
    {
        $m = $this->material(250);

        foreach ([250, 500, 750, 2500] as $ok) {
            $this->assertNull($m->withdrawalMultipleProblem($ok), "{$ok} harus boleh");
        }
        foreach ([100, 300, 0, 125.5] as $bad) {
            $this->assertNotNull($m->withdrawalMultipleProblem($bad), "{$bad} harus ditolak");
        }
        $this->assertSame('Lem Kelipatan hanya bisa diambil dalam kelipatan 250 ml (contoh: 250, 500, 750).', $m->withdrawalMultipleProblem(300));

        $half = $this->material(0.5);
        $this->assertNull($half->withdrawalMultipleProblem(1.5));
        $this->assertNotNull($half->withdrawalMultipleProblem(0.75));

        $free = $this->material(null);
        $this->assertNull($free->withdrawalMultipleProblem(7.3));
        $zero = $this->material(0);
        $this->assertNull($zero->withdrawalMultipleProblem(7.3), 'Nol sama dengan bebas.');
    }

    public function test_stock_out_through_the_api_must_be_a_multiple_but_stock_in_is_free(): void
    {
        $m = $this->material(250);
        $staff = $this->writer();
        $url = "/api/staff/materials/{$m->id}/movement";

        $this->actingAs($staff, 'api')->postJson($url, ['type' => 'out', 'quantity' => 300])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Lem Kelipatan hanya bisa diambil dalam kelipatan 250 ml (contoh: 250, 500, 750).');
        $this->assertEquals(5000, (float) $m->fresh()->current_stock);

        $this->actingAs($staff, 'api')->postJson($url, ['type' => 'out', 'quantity' => 500])->assertSuccessful();
        $this->assertEquals(4500, (float) $m->fresh()->current_stock);

        $this->actingAs($staff, 'api')->postJson($url, ['type' => 'in', 'quantity' => 130])->assertSuccessful();
        $this->assertEquals(4630, (float) $m->fresh()->current_stock, 'Stok masuk tidak dicek.');
    }

    public function test_full_access_may_go_outside_the_multiple_only_with_a_reason_note(): void
    {
        $m = $this->material(250);
        $admin = $this->user('super_admin');
        $url = "/api/staff/materials/{$m->id}/movement";

        $this->actingAs($admin, 'api')->postJson($url, ['type' => 'out', 'quantity' => 300])
            ->assertStatus(422);
        $this->assertStringContainsString('isi catatan alasan', $this->actingAs($admin, 'api')->postJson($url, ['type' => 'out', 'quantity' => 300])->json('message'));

        $this->actingAs($admin, 'api')->postJson($url, ['type' => 'out', 'quantity' => 300, 'note' => 'Pengambilan darurat'])->assertSuccessful();
        $this->assertEquals(4700, (float) $m->fresh()->current_stock);
    }

    public function test_memo_withdrawals_must_be_a_multiple_for_staff_and_full_access_needs_a_note(): void
    {
        $m = $this->material(250);
        $staff = $this->writer();
        $memo = MaterialMemo::create(['memo_number' => 'MEMO-K-1', 'store_id' => $this->store->id, 'created_by' => $staff->id]);
        $url = "/api/staff/memos/{$memo->id}/items";

        $this->actingAs($staff, 'api')->postJson($url, ['item_type' => 'raw_material', 'item_id' => $m->id, 'qty_taken' => 300])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Lem Kelipatan hanya bisa diambil dalam kelipatan 250 ml (contoh: 250, 500, 750).']);
        $this->assertEquals(0, $memo->items()->count());
        $this->assertEquals(5000, (float) $m->fresh()->current_stock);

        $this->actingAs($staff, 'api')->postJson($url, ['item_type' => 'raw_material', 'item_id' => $m->id, 'qty_taken' => 500])->assertSuccessful();
        $this->assertEquals(4500, (float) $m->fresh()->current_stock);

        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'api')->postJson($url, ['item_type' => 'raw_material', 'item_id' => $m->id, 'qty_taken' => 130])->assertStatus(422);
        $this->actingAs($admin, 'api')->postJson($url, ['item_type' => 'raw_material', 'item_id' => $m->id, 'qty_taken' => 130, 'condition_notes' => 'Sisa tong'])->assertSuccessful();
    }

    public function test_corrections_by_full_access_returns_write_offs_and_opname_are_not_checked(): void
    {
        $m = $this->material(250);
        $admin = $this->user('super_admin');
        $memo = MaterialMemo::create(['memo_number' => 'MEMO-K-2', 'store_id' => $this->store->id, 'created_by' => $admin->id]);

        $row = MaterialMemoStockService::addMaterial($m, 'raw_material', $memo, 500, $admin->id, null);

        // Pengembalian bebas kelipatan.
        MaterialMemoStockService::returnMaterial($row->fresh(), 130, $admin->id, $memo);
        $this->assertEquals(130.0, (float) $row->fresh()->qty_returned);

        // Koreksi jumlah (akses penuh) boleh di luar kelipatan tanpa catatan.
        $open = MaterialMemoStockService::addMaterial($m->fresh(), 'raw_material', $memo, 250, $admin->id, null);
        MaterialMemoStockService::updateMaterialQty($open->fresh(), 300, $admin->id, $memo);
        $this->assertEquals(300.0, (float) $open->fresh()->qty_taken);

        // Stok terbuang (sumber bukan manual) tidak dicek.
        $m->fresh()->recordMovement('out', 100, $admin->id, 'Rusak', sourceType: 'stock_write_off', sourceId: 1);

        // Opname tidak dicek.
        $m->fresh()->adjustStock(1234, $admin->id, 'Hitung ulang');
        $this->assertEquals(1234.0, (float) $m->fresh()->current_stock);
    }

    public function test_the_multiple_is_saved_from_the_panel_form_and_shown_by_the_api(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $m = $this->material(null);

        \Livewire\Livewire::test(\App\Filament\Resources\RawMaterialResource\Pages\EditRawMaterial::class, ['record' => $m->getRouteKey()])
            ->fillForm(['withdrawal_multiple' => 250])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(250.0, (float) $m->fresh()->withdrawal_multiple);

        $detail = $this->actingAs($this->writer(), 'api')->getJson("/api/staff/materials/{$m->id}")->assertSuccessful();
        $this->assertEquals(250.0, (float) $detail->json('data.withdrawal_multiple'));
    }
}
