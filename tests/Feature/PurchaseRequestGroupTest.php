<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequestResource\Pages\ListPurchaseRequests;
use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestGroup;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\User;
use App\Services\PurchaseRequestGroupService;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Permohonan Pembelian banyak barang (2026-11-23): satu nomor induk (PP-yyyymm-NNNN), satu baris PurchaseRequest per barang.
 */
class PurchaseRequestGroupTest extends TestCase
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
        $this->mock(PushNotificationService::class)->shouldReceive('sendToUsers')->andReturnNull();
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function writer(): User
    {
        return $this->user('kasir', ['PurchaseRequestResource'], ['menu_permissions' => ['PurchaseRequestResource' => ['create', 'update', 'view']]]);
    }

    private function material(string $name, string $unit = 'ml'): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'unit' => $unit, 'current_stock' => 1, 'reorder_point' => 0, 'created_by' => $this->user('super_admin')->id]);
    }

    public function test_one_submission_makes_one_parent_number_and_one_line_per_item(): void
    {
        $writer = $this->writer();
        $lem = $this->material('Lem A');
        $solvent = $this->material('Solvent B', 'liter');

        $group = app(PurchaseRequestGroupService::class)->submit($writer, null, 'Stok menipis', [
            ['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 5],
            ['item_type' => 'raw_material', 'item_id' => $solvent->id, 'quantity' => 2.5],
            ['item_type' => 'other', 'item_name' => 'Kuas', 'unit' => 'buah', 'quantity' => 10],
            ['item_type' => 'asset', 'item_name' => 'Kompresor', 'quantity' => 1],
        ]);

        $this->assertMatchesRegularExpression('/^PP-\d{6}-0001$/', $group->group_number);
        $this->assertCount(4, $group->lines);
        $this->assertSame($this->store->id, $group->store_id);

        foreach ($group->lines as $line) {
            $this->assertSame('pending', $line->status);
            $this->assertSame($writer->id, $line->requested_by);
            $this->assertSame('Stok menipis', $line->reason);
            $this->assertNotEmpty($line->request_number);
        }

        $byName = $group->lines->keyBy('item_name');
        $this->assertSame('ml', $byName['Lem A']->unit, 'Satuan katalog diambil dari barangnya.');
        $this->assertSame('buah', $byName['Kuas']->unit);
        $this->assertNull($byName['Kompresor']->unit);

        $second = app(PurchaseRequestGroupService::class)->submit($writer, null, null, [['item_type' => 'other', 'item_name' => 'Lakban', 'quantity' => 3]]);
        $this->assertMatchesRegularExpression('/-0002$/', $second->group_number, 'Nomor urut per bulan.');
    }

    public function test_a_bad_line_rejects_the_whole_request_and_saves_nothing(): void
    {
        $lem = $this->material('Lem A');
        $service = app(PurchaseRequestGroupService::class);
        $writer = $this->writer();

        foreach ([
            [[], 'Tambahkan minimal satu barang.'],
            [[['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 1], ['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 2]], 'dipilih lebih dari satu kali'],
            [[['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 0]], 'jumlah harus lebih dari 0'],
            [[['item_type' => 'raw_material', 'item_id' => 99999, 'quantity' => 1]], 'tidak ditemukan'],
            [[['item_type' => 'other', 'item_name' => '  ', 'quantity' => 1]], 'nama barang wajib diisi'],
            [[['item_type' => 'ngawur', 'quantity' => 1]], 'jenis barang tidak valid'],
        ] as [$lines, $message]) {
            try {
                $service->submit($writer, null, null, $lines);
                $this->fail("Seharusnya ditolak: {$message}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        // Baris kedua bermasalah: baris pertama yang valid tidak ikut tersimpan.
        try {
            $service->submit($writer, null, null, [['item_type' => 'other', 'item_name' => 'Valid', 'quantity' => 1], ['item_type' => 'raw_material', 'item_id' => 99999, 'quantity' => 1]]);
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, PurchaseRequest::withoutGlobalScopes()->count());
        $this->assertSame(0, PurchaseRequestGroup::withoutGlobalScopes()->count());
    }

    public function test_an_item_that_already_has_an_open_request_is_refused(): void
    {
        $lem = $this->material('Lem A');
        $service = app(PurchaseRequestGroupService::class);
        $service->submit($this->writer(), null, null, [['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 1]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sudah ada permohonan untuk Lem A');
        $service->submit($this->writer(), null, null, [['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 1]]);
    }

    public function test_the_api_accepts_several_items_and_still_accepts_the_old_single_item_shape(): void
    {
        $writer = $this->writer();
        $lem = $this->material('Lem A');

        $response = $this->actingAs($writer, 'api')->postJson('/api/staff/purchase-requests', [
            'items' => [
                ['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 4],
                ['item_type' => 'other', 'item_name' => 'Kuas', 'unit' => 'buah', 'quantity' => 6],
            ],
            'reason' => 'Habis',
        ])->assertStatus(201);

        $response->assertJsonPath('success', true);
        $this->assertMatchesRegularExpression('/^PP-/', $response->json('group_number'));
        $this->assertCount(2, $response->json('data'));
        $this->assertSame($response->json('group_number'), $response->json('data.0.group_number'));

        $legacy = $this->actingAs($writer, 'api')->postJson('/api/staff/purchase-requests', ['item_type' => 'asset', 'item_name' => 'Kompresor', 'quantity' => 1])->assertStatus(201);
        $this->assertNull($legacy->json('data.group_number'), 'Bentuk lama tidak membuat induk.');

        $list = $this->actingAs($writer, 'api')->getJson('/api/staff/purchase-requests')->assertSuccessful();
        $this->assertCount(3, $list->json('data'));
        $this->assertSame(2, collect($list->json('data'))->whereNotNull('group_number')->count());
    }

    public function test_the_api_refuses_a_bad_items_payload_and_accounts_without_the_menu(): void
    {
        $this->actingAs($this->writer(), 'api')->postJson('/api/staff/purchase-requests', ['items' => []])->assertStatus(422);
        $this->actingAs($this->writer(), 'api')->postJson('/api/staff/purchase-requests', ['items' => [['item_type' => 'other', 'item_name' => 'X']]])->assertStatus(422);

        $noMenu = $this->user('kasir', []);
        $this->actingAs($noMenu, 'api')->postJson('/api/staff/purchase-requests', ['items' => [['item_type' => 'other', 'item_name' => 'X', 'quantity' => 1]]])->assertStatus(403);

        $viewOnly = $this->user('kasir', ['PurchaseRequestResource'], ['menu_permissions' => ['PurchaseRequestResource' => ['view']]]);
        $this->actingAs($viewOnly, 'api')->postJson('/api/staff/purchase-requests', ['items' => [['item_type' => 'other', 'item_name' => 'X', 'quantity' => 1]]])->assertStatus(403);
    }

    public function test_the_panel_multi_item_action_creates_the_group_and_the_bulk_actions_decide_every_selected_line(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $lem = $this->material('Lem A');

        // Repeater Filament membuat dua baris kosong bawaan dengan kunci uuid; tes mengisi kedua baris itu.
        $component = Livewire::test(ListPurchaseRequests::class)->mountAction('multi');
        $keys = array_keys($component->get('mountedActionsData.0.items'));
        $this->assertCount(2, $keys);

        $component
            ->setActionData([
                'store_id' => $this->store->id,
                'items' => [
                    $keys[0] => ['item_type' => 'raw_material', 'item_id' => $lem->id, 'quantity' => 3],
                    $keys[1] => ['item_type' => 'other', 'item_name' => 'Kuas', 'unit' => 'buah', 'quantity' => 8],
                ],
                'reason' => 'Dari panel',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $group = PurchaseRequestGroup::firstOrFail();
        $lines = $group->lines()->get();
        $this->assertCount(2, $lines);

        Livewire::test(ListPurchaseRequests::class)
            ->callTableBulkAction('approve_selected', $lines)
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(['approved', 'approved'], $lines->fresh()->pluck('status')->all());

        // Yang sudah diproses dilewati, bukan error.
        Livewire::test(ListPurchaseRequests::class)
            ->callTableBulkAction('approve_selected', $lines)
            ->assertHasNoTableBulkActionErrors();
    }

    public function test_bulk_reject_needs_a_reason_and_only_full_access_sees_the_bulk_actions(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $group = app(PurchaseRequestGroupService::class)->submit($this->writer(), null, null, [
            ['item_type' => 'other', 'item_name' => 'A', 'quantity' => 1],
            ['item_type' => 'other', 'item_name' => 'B', 'quantity' => 1],
        ]);
        $lines = $group->lines;

        Livewire::test(ListPurchaseRequests::class)
            ->callTableBulkAction('reject_selected', $lines, data: ['review_note' => ''])
            ->assertHasTableBulkActionErrors(['review_note']);

        Livewire::test(ListPurchaseRequests::class)
            ->callTableBulkAction('reject_selected', $lines, data: ['review_note' => 'Belum perlu'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(['rejected', 'rejected'], PurchaseRequest::withoutGlobalScopes()->whereIn('id', $lines->pluck('id'))->orderBy('id')->pluck('status')->all());

        $this->actingAs($this->writer(), 'web');
        Livewire::test(ListPurchaseRequests::class)
            ->assertTableBulkActionHidden('approve_selected')
            ->assertTableBulkActionHidden('reject_selected');
    }

    public function test_a_catalog_consumable_line_uses_the_item_unit(): void
    {
        $item = ConsumableItem::create(['name' => 'Sarung Tangan', 'unit' => 'box', 'current_stock' => 1, 'reorder_point' => 0, 'created_by' => $this->user('super_admin')->id]);

        $group = app(PurchaseRequestGroupService::class)->submit($this->writer(), null, null, [['item_type' => 'consumable_item', 'item_id' => $item->id, 'quantity' => 2]]);

        $this->assertSame('box', $group->lines->first()->unit);
        $this->assertSame('Sarung Tangan', $group->lines->first()->item_name);
    }
}
