<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialMemoResource\Pages\EditMaterialMemo;
use App\Filament\Resources\MaterialMemoResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ScrollCodeResource;
use App\Filament\Resources\ScrollCodeResource\Pages\ViewScrollCode;
use App\Filament\Resources\ScrollCodeResource\RelationManagers\OffcutsRelationManager;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\ScrollCode;
use App\Models\ScrollCodeOffcut;
use App\Models\Store;
use App\Models\User;
use App\Services\MaterialMemoStockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Potongan Sisa (wasted) PPF: disimpan per potongan tanpa ukuran, terikat ke satu kode roll, diambil lewat baris memo beserta bagian
 * mobilnya, dan bukan stok (sisa meter roll tidak berubah). Pengelolaannya oleh staf gudang; membuang hanya akses penuh.
 */
class ScrollCodeOffcutTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, ?array $menuAccess = null, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    /** Staf gudang: boleh mengubah Kode Gulungan dan Memo. */
    private function warehouse(?Store $store = null): User
    {
        return $this->user('kasir', ['ScrollCodeResource', 'MaterialMemoResource'], $store, ['menu_permissions' => [
            'ScrollCodeResource' => ['view', 'update'],
            'MaterialMemoResource' => ['create', 'update', 'view'],
        ]]);
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function roll(string $code = 'ROLL-1', ?Store $store = null, float $remaining = 2): ScrollCode
    {
        return ScrollCode::create(['code' => $code, 'store_id' => ($store ?? $this->storeA)->id, 'status' => 'allocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => $remaining]);
    }

    private function memo(?Store $store = null): MaterialMemo
    {
        return MaterialMemo::create(['memo_number' => 'MEMO-T-' . uniqid(), 'store_id' => ($store ?? $this->storeA)->id, 'created_by' => $this->user('super_admin')->id]);
    }

    private function memoManager(MaterialMemo $memo)
    {
        return Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $memo, 'pageClass' => EditMaterialMemo::class]);
    }

    public function test_offcuts_are_stored_per_piece_without_touching_roll_stock(): void
    {
        $roll = $this->roll();
        $created = $roll->storeOffcuts(3, ' Rak B2 ', 'Sisa panel besar', $this->user('super_admin')->id);

        $this->assertCount(3, $created);
        $this->assertSame(3, $roll->offcuts()->stored()->count());
        $first = $roll->offcuts()->first();
        $this->assertSame('Rak B2', $first->location);
        $this->assertSame($this->storeA->id, $first->store_id);
        $this->assertEquals(2, (float) $roll->fresh()->remaining_length_meters, 'Potongan bukan stok: sisa meter roll tidak berubah.');

        $this->expectException(\InvalidArgumentException::class);
        $roll->storeOffcuts(0, null, null, null);
    }

    public function test_a_roll_with_offcuts_cannot_be_deleted(): void
    {
        $roll = $this->roll();
        $this->assertSame([], $roll->deleteBlockers());

        $roll->storeOffcuts(1, null, null, null);
        $this->assertContains('1 potongan sisa', $roll->deleteBlockers());
    }

    public function test_taking_an_offcut_in_a_memo_records_the_car_part_and_marks_it_used(): void
    {
        $roll = $this->roll();
        $offcut = $roll->storeOffcuts(1, 'Rak A', null, null)->first();
        $memo = $this->memo();
        $user = $this->user('super_admin');

        $item = MaterialMemoStockService::addOffcut($offcut, $memo, ' Dasbor ', $user->id, 'Untuk interior');

        $this->assertSame('offcut', $item->item_type);
        $this->assertSame('Dasbor', $item->car_part);
        $this->assertEquals(1, (float) $item->qty_taken);
        $this->assertStringContainsString('ROLL-1', $item->item_name);

        $offcut->refresh();
        $this->assertSame(ScrollCodeOffcut::USED, $offcut->status);
        $this->assertSame($item->id, $offcut->used_in_memo_item_id);
        $this->assertSame('Dasbor', $offcut->car_part);
        $this->assertEquals(2, (float) $roll->fresh()->remaining_length_meters);

        // Tidak bisa diambil dua kali.
        $this->expectException(\InvalidArgumentException::class);
        MaterialMemoStockService::addOffcut($offcut, $this->memo(), 'Pintu', $user->id, null);
    }

    public function test_removing_the_memo_line_returns_the_offcut_to_storage(): void
    {
        $offcut = $this->roll()->storeOffcuts(1, null, null, null)->first();
        $memo = $this->memo();
        $user = $this->user('super_admin');
        $item = MaterialMemoStockService::addOffcut($offcut, $memo, 'Dasbor', $user->id, null);

        MaterialMemoStockService::reverseItem($item, $user->id, $memo);
        $item->delete();

        $offcut->refresh();
        $this->assertSame(ScrollCodeOffcut::STORED, $offcut->status);
        $this->assertNull($offcut->used_in_memo_item_id);
        $this->assertNull($offcut->car_part);
    }

    public function test_the_car_part_is_required_and_other_stores_offcuts_are_refused(): void
    {
        $user = $this->user('super_admin');
        $offcut = $this->roll()->storeOffcuts(1, null, null, null)->first();

        try {
            MaterialMemoStockService::addOffcut($offcut, $this->memo(), '   ', $user->id, null);
            $this->fail('Bagian mobil kosong harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Bagian mobil', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        MaterialMemoStockService::addOffcut($offcut, $this->memo($this->storeB), 'Dasbor', $user->id, null);
    }

    public function test_the_memo_panel_takes_an_offcut_and_shows_the_car_part(): void
    {
        $offcut = $this->roll()->storeOffcuts(1, null, null, null)->first();
        $memo = $this->memo();
        $this->as($this->warehouse());

        $this->memoManager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'offcut', 'offcut_id' => $offcut->id, 'car_part' => 'Panel pintu'])
            ->assertHasNoTableActionErrors();

        $row = $memo->items()->firstOrFail();
        $this->assertSame('offcut', $row->item_type);
        $this->assertSame('Panel pintu', $row->car_part);
        $this->assertSame(ScrollCodeOffcut::USED, $offcut->fresh()->status);

        // Bagian mobil wajib diisi untuk potongan; baris potongan tidak punya koreksi jumlah.
        $other = $this->roll('ROLL-2')->storeOffcuts(1, null, null, null)->first();
        $this->memoManager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'offcut', 'offcut_id' => $other->id])
            ->assertHasTableActionErrors(['car_part']);
        $this->memoManager($memo)->assertTableActionHidden('edit_qty', $row);
    }

    public function test_the_memo_roll_row_can_store_offcuts_only_for_those_who_manage_rolls(): void
    {
        $roll = $this->roll();
        $item = InventoryItem::create(['code' => 'INV-' . uniqid(), 'name' => 'Film ROLL-1', 'category' => 'PPF', 'scroll_code_id' => $roll->id, 'status' => 'in_stock']);
        $memo = $this->memo();
        $this->as($this->user('super_admin'));
        MaterialMemoStockService::addInventory($item, $memo, 1.5, auth()->id(), null);
        $row = $memo->items()->firstOrFail();

        $this->as($this->warehouse());
        $this->memoManager($memo)
            ->callTableAction('store_offcuts', $row, data: ['count' => 2, 'location' => 'Rak C1'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(2, $roll->offcuts()->count());
        $this->assertSame($memo->id, $roll->offcuts()->first()->origin_memo_id);

        // Staf memo tanpa izin mengubah Kode Gulungan tidak mendapat tombolnya.
        $this->as($this->user('kasir', ['MaterialMemoResource'], null, ['menu_permissions' => ['MaterialMemoResource' => ['create', 'update', 'view']]]));
        $this->memoManager($memo)->assertTableActionHidden('store_offcuts', $row);
    }

    public function test_the_roll_page_lists_offcuts_and_only_full_access_discards(): void
    {
        $roll = $this->roll();
        $offcut = $roll->storeOffcuts(1, null, null, null)->first();

        $this->as($this->warehouse());
        $this->assertTrue(ScrollCodeOffcut::canManage(auth()->user()));

        Livewire::test(OffcutsRelationManager::class, ['ownerRecord' => $roll, 'pageClass' => ViewScrollCode::class])
            ->assertCanSeeTableRecords([$offcut])
            ->assertTableActionHidden('discard', $offcut)
            ->callTableAction('store_offcuts', data: ['count' => 2])
            ->assertHasNoTableActionErrors();
        $this->assertSame(3, $roll->offcuts()->count());

        $this->as($this->user('super_admin'));
        Livewire::test(OffcutsRelationManager::class, ['ownerRecord' => $roll, 'pageClass' => ViewScrollCode::class])
            ->callTableAction('discard', $offcut, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason'])
            ->callTableAction('discard', $offcut, data: ['reason' => 'Rusak terkena cairan'])
            ->assertHasNoTableActionErrors();

        $offcut->refresh();
        $this->assertSame(ScrollCodeOffcut::DISCARDED, $offcut->status);
        $this->assertSame('Rusak terkena cairan', $offcut->discard_reason);

        // Yang sudah dibuang tidak bisa diambil di memo.
        $this->expectException(\InvalidArgumentException::class);
        MaterialMemoStockService::addOffcut($offcut, $this->memo(), 'Dasbor', auth()->id(), null);
    }

    public function test_a_plain_member_without_roll_rights_cannot_manage_offcuts(): void
    {
        $this->assertFalse(ScrollCodeOffcut::canManage($this->user('kasir', ['BookingResource'])));
        $this->assertFalse(ScrollCodeOffcut::canManage(null));
        $this->assertTrue(ScrollCodeOffcut::canManage($this->user('super_admin')));
        $this->assertTrue(class_exists(ScrollCodeResource::class));
    }
}
