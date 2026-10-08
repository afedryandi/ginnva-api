<?php

namespace Tests\Feature;

use App\Exports\MaterialMemoItemExport;
use App\Filament\Resources\MaterialMemoResource;
use App\Filament\Resources\MaterialMemoResource\Pages\CreateMaterialMemo;
use App\Filament\Resources\MaterialMemoResource\Pages\EditMaterialMemo;
use App\Filament\Resources\MaterialMemoResource\Pages\ListMaterialMemos;
use App\Filament\Resources\MaterialMemoResource\RelationManagers\ItemsRelationManager;
use App\Models\Booking;
use App\Models\ConsumableItem;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\FilmProductRecipeItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Memo Pengambilan/Pengembalian: hak akses, staf hanya melihat memo tokonya, buat memo (toko dipaksa, nomor urut aman setelah
 * ada yang dihapus, 1 booking = 1 memo), tambah / kembalikan / koreksi / hapus barang beserta efek stoknya, isi dari Master
 * Resep, hapus memo membalik stok, dan ekspor. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class MaterialMemoResourceTest extends TestCase
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
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function memo(Store $store, array $extra = []): MaterialMemo
    {
        return MaterialMemo::create(array_merge(['memo_number' => 'MEMO-T-' . uniqid(), 'store_id' => $store->id, 'created_by' => $this->user('super_admin')->id], $extra));
    }

    private function material(string $name, float $stock = 10): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => $stock]);
    }

    private function consumable(string $name, float $stock = 10): ConsumableItem
    {
        return ConsumableItem::create(['name' => $name, 'code' => 'CI-' . uniqid(), 'category' => 'umum', 'unit' => 'pcs', 'current_stock' => $stock]);
    }

    private function roll(string $code, float $remaining = 15): InventoryItem
    {
        $scroll = ScrollCode::create(['code' => $code, 'store_id' => $this->storeA->id, 'status' => 'allocated', 'usage_count' => 0, 'total_length_meters' => 15, 'remaining_length_meters' => $remaining, 'allocated_at' => now()]);

        return InventoryItem::create(['code' => 'INV-' . uniqid(), 'name' => 'Film ' . $code, 'category' => 'PPF', 'scroll_code_id' => $scroll->id, 'status' => 'in_stock']);
    }

    private function booking(Store $store): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(['booking_number' => 'BKG-T-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id, 'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => '2026-10-09', 'status' => 'confirmed']);
    }

    private function manager(MaterialMemo $memo)
    {
        return Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $memo, 'pageClass' => EditMaterialMemo::class]);
    }

    // ------------------------------------------------------------- akses & cakupan toko

    public function test_access_rules(): void
    {
        $this->as($this->user('super_admin'));
        $this->assertTrue(MaterialMemoResource::canViewAny());
        $this->assertTrue(MaterialMemoResource::canCreate());
        $this->assertTrue(MaterialMemoResource::canEdit(new MaterialMemo()));

        $this->as($this->user('kasir'));
        $this->assertTrue(MaterialMemoResource::canViewAny());
        $this->assertTrue(MaterialMemoResource::canCreate());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(MaterialMemoResource::canViewAny());
        $this->assertFalse(MaterialMemoResource::canCreate());
        $this->assertFalse(MaterialMemoResource::canEdit(new MaterialMemo()));
    }

    public function test_staff_only_see_their_own_store_and_cannot_open_other_memos(): void
    {
        $mine = $this->memo($this->storeA, ['memo_number' => 'MEMO-MINE']);
        $theirs = $this->memo($this->storeB, ['memo_number' => 'MEMO-THEIRS']);

        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(ListMaterialMemos::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableFilterHidden('store_id');

        try {
            Livewire::test(EditMaterialMemo::class, ['record' => $theirs->getRouteKey()]);
            $this->fail('Memo toko lain tidak boleh bisa dibuka.');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }

        $this->as($this->user('super_admin'));
        Livewire::test(ListMaterialMemos::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->assertTableFilterVisible('store_id')
            ->filterTable('store_id', $this->storeB->id)
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$mine]);
    }

    public function test_list_search_and_items_count(): void
    {
        $memo = $this->memo($this->storeA, ['memo_number' => 'MEMO-CARI-1', 'vehicle_info' => 'Avanza B 1 XYZ']);
        $other = $this->memo($this->storeA, ['memo_number' => 'MEMO-LAIN-2']);
        MaterialMemoItem::create(['material_memo_id' => $memo->id, 'item_type' => 'raw_material', 'item_id' => 1, 'item_name' => 'X', 'unit' => 'liter', 'qty_taken' => 1]);

        $this->as($this->user('super_admin'));
        Livewire::test(ListMaterialMemos::class)
            ->searchTable('CARI')
            ->assertCanSeeTableRecords([$memo])
            ->assertCanNotSeeTableRecords([$other]);
    }

    // ------------------------------------------------------------- membuat memo

    public function test_staff_create_forces_own_store_and_sets_creator_and_number(): void
    {
        $staff = $this->as($this->user('kasir', null, $this->storeA));

        Livewire::test(CreateMaterialMemo::class)
            ->fillForm(['store_id' => $this->storeB->id, 'vehicle_info' => 'Avanza B 1 XYZ', 'spk_number' => 'SPK-1', 'notes' => 'Catatan'])
            ->call('create')
            ->assertHasNoFormErrors();

        $memo = MaterialMemo::withoutGlobalScopes()->where('vehicle_info', 'Avanza B 1 XYZ')->firstOrFail();
        $this->assertSame($this->storeA->id, $memo->store_id, 'Staf tidak bisa membuat memo untuk toko lain.');
        $this->assertSame($staff->id, $memo->created_by);
        $this->assertSame('MEMO-20261008-0001', $memo->memo_number);
    }

    public function test_admin_can_choose_the_store(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(CreateMaterialMemo::class)
            ->fillForm(['store_id' => $this->storeB->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->storeB->id, MaterialMemo::withoutGlobalScopes()->firstOrFail()->store_id);
    }

    public function test_store_is_required_for_admin(): void
    {
        $this->as($this->user('super_admin'));

        Livewire::test(CreateMaterialMemo::class)
            ->fillForm(['store_id' => null])
            ->call('create')
            ->assertHasFormErrors(['store_id' => 'required']);
    }

    public function test_memo_number_does_not_collide_after_a_memo_is_deleted(): void
    {
        $this->as($this->user('super_admin'));

        $numbers = [];
        foreach (range(1, 3) as $i) {
            $numbers[] = MaterialMemo::create(['memo_number' => MaterialMemo::generateMemoNumber(), 'store_id' => $this->storeA->id, 'created_by' => $this->user('super_admin')->id])->memo_number;
        }
        $this->assertSame(['MEMO-20261008-0001', 'MEMO-20261008-0002', 'MEMO-20261008-0003'], $numbers);

        MaterialMemo::where('memo_number', 'MEMO-20261008-0001')->delete();

        $this->assertSame('MEMO-20261008-0004', MaterialMemo::generateMemoNumber(), 'Nomor berikutnya mengikuti urutan terbesar, bukan jumlah baris.');

        Livewire::test(CreateMaterialMemo::class)
            ->fillForm(['store_id' => $this->storeA->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(3, MaterialMemo::count());
        $this->assertTrue(MaterialMemo::where('memo_number', 'MEMO-20261008-0004')->exists());
    }

    public function test_one_booking_can_only_have_one_memo(): void
    {
        $booking = $this->booking($this->storeA);
        $this->memo($this->storeA, ['booking_id' => $booking->id]);

        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(CreateMaterialMemo::class)
            ->fillForm(['booking_id' => $booking->id])
            ->call('create')
            ->assertHasFormErrors(['booking_id' => 'unique']);

        $this->assertSame(1, MaterialMemo::count());
    }

    public function test_edit_updates_the_memo_and_keeps_its_own_booking(): void
    {
        $booking = $this->booking($this->storeA);
        $memo = $this->memo($this->storeA, ['booking_id' => $booking->id]);

        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(EditMaterialMemo::class, ['record' => $memo->getRouteKey()])
            ->fillForm(['vehicle_info' => 'Innova', 'notes' => 'Diubah'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Innova', $memo->fresh()->vehicle_info);
        $this->assertSame($booking->id, $memo->fresh()->booking_id, 'Booking sendiri tidak dianggap duplikat.');
    }

    // ------------------------------------------------------------- tambah barang

    public function test_adding_raw_material_and_consumable_reduces_stock(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $consumable = $this->consumable('Sarung Tangan', 20);

        $this->as($this->user('kasir', null, $this->storeA));
        $this->manager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'raw_material', 'raw_material_id' => $material->id, 'qty_taken' => 2.5, 'condition_notes' => 'Untuk dashboard'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('add_item', data: ['item_type' => 'consumable_item', 'consumable_item_id' => $consumable->id, 'qty_taken' => 4])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(7.5, (float) $material->fresh()->current_stock);
        $this->assertEquals(16, (float) $consumable->fresh()->current_stock);
        $this->assertSame(2, $memo->items()->count());

        $item = $memo->items()->where('item_type', 'raw_material')->firstOrFail();
        $this->assertSame('Adhesive', $item->item_name);
        $this->assertEquals(2.5, (float) $item->qty_taken);
        $this->assertSame('Untuk dashboard', $item->condition_notes);

        $movement = $material->fresh()->movements()->where('type', 'out')->firstOrFail();
        $this->assertSame($this->storeA->id, $movement->store_id);
    }

    public function test_adding_more_than_the_stock_is_rejected_without_side_effects(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 3);

        $this->as($this->user('kasir', null, $this->storeA));
        $this->manager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'raw_material', 'raw_material_id' => $material->id, 'qty_taken' => 5])
            ->assertNotified('Tidak bisa menambah barang');

        $this->assertEquals(3, (float) $material->fresh()->current_stock);
        $this->assertSame(0, $memo->items()->count());
    }

    public function test_add_item_validates_the_form(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);

        $this->as($this->user('kasir', null, $this->storeA));
        $this->manager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'raw_material', 'raw_material_id' => $material->id, 'qty_taken' => 0])
            ->assertHasTableActionErrors(['qty_taken']);
        $this->manager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'raw_material', 'qty_taken' => 1])
            ->assertHasTableActionErrors(['raw_material_id' => 'required']);
        $this->manager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'inventory_item', 'meters_used' => 0])
            ->assertHasTableActionErrors(['inventory_item_id', 'meters_used']);

        $this->assertSame(0, $memo->items()->count());
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
    }

    public function test_adding_a_roll_uses_meters_and_rejects_more_than_remaining(): void
    {
        $memo = $this->memo($this->storeA);
        $item = $this->roll('SC-MEMO-1', 15);
        $scroll = $item->scrollCode ?? ScrollCode::find($item->scroll_code_id);

        $this->as($this->user('kasir', null, $this->storeA));
        $this->manager($memo)
            ->callTableAction('add_item', data: ['item_type' => 'inventory_item', 'inventory_item_id' => $item->id, 'meters_used' => 4.5])
            ->assertHasNoTableActionErrors()
            ->callTableAction('add_item', data: ['item_type' => 'inventory_item', 'inventory_item_id' => $item->id, 'meters_used' => 50])
            ->assertNotified('Tidak bisa menambah barang');

        $scroll = $scroll->fresh();
        $this->assertEquals(10.5, (float) $scroll->remaining_length_meters);
        $this->assertSame(1, $scroll->usage_count);
        $row = $memo->items()->firstOrFail();
        $this->assertSame('inventory_item', $row->item_type);
        $this->assertEquals(4.5, (float) $row->meters_used);
        $this->assertNotNull($row->scroll_code_usage_id);
        $this->assertSame(1, $memo->items()->count());
    }

    // ------------------------------------------------------------- pengembalian

    public function test_recording_a_return_puts_stock_back_and_can_only_be_done_once(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $this->as($this->user('kasir', null, $this->storeA));
        $row = \App\Services\MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 5, auth()->id(), null);
        $this->assertEquals(5, (float) $material->fresh()->current_stock);

        $this->manager($memo)
            ->callTableAction('return_item', $row, data: ['qty_returned' => 6])
            ->assertHasTableActionErrors(['qty_returned']);
        $this->assertEquals(5, (float) $material->fresh()->current_stock);

        $this->manager($memo)
            ->callTableAction('return_item', $row, data: ['qty_returned' => 2])
            ->assertHasNoTableActionErrors();

        $row = $row->fresh();
        $this->assertEquals(2, (float) $row->qty_returned);
        $this->assertEquals(3, (float) $row->qty_used);
        $this->assertEquals(7, (float) $material->fresh()->current_stock);

        $this->manager($memo)->assertTableActionHidden('return_item', $row)->assertTableActionHidden('edit_qty', $row);
    }

    public function test_returning_zero_records_it_without_stock_movement(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $this->as($this->user('kasir', null, $this->storeA));
        $row = \App\Services\MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 5, auth()->id(), null);

        $this->manager($memo)->callTableAction('return_item', $row, data: ['qty_returned' => 0])->assertHasNoTableActionErrors();

        $this->assertEquals(0, (float) $row->fresh()->qty_returned);
        $this->assertEquals(5, (float) $row->fresh()->qty_used);
        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $this->assertSame(0, $material->movements()->where('type', 'in')->count());
    }

    public function test_rolls_have_no_return_action(): void
    {
        $memo = $this->memo($this->storeA);
        $item = $this->roll('SC-MEMO-2');
        $this->as($this->user('kasir', null, $this->storeA));
        $row = \App\Services\MaterialMemoStockService::addInventory($item, $memo, 2, auth()->id(), null);

        $this->manager($memo)->assertTableActionHidden('return_item', $row)->assertTableActionVisible('edit_qty', $row);
    }

    // ------------------------------------------------------------- koreksi jumlah

    public function test_correcting_the_quantity_moves_stock_by_the_difference(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $this->as($this->user('kasir', null, $this->storeA));
        $row = \App\Services\MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 4, auth()->id(), null);

        $this->manager($memo)->callTableAction('edit_qty', $row, data: ['qty' => 6])->assertHasNoTableActionErrors();
        $this->assertEquals(4, (float) $material->fresh()->current_stock);
        $this->assertEquals(6, (float) $row->fresh()->qty_taken);

        $this->manager($memo)->callTableAction('edit_qty', $row->fresh(), data: ['qty' => 1])->assertHasNoTableActionErrors();
        $this->assertEquals(9, (float) $material->fresh()->current_stock);
        $this->assertEquals(1, (float) $row->fresh()->qty_taken);

        $this->manager($memo)->callTableAction('edit_qty', $row->fresh(), data: ['qty' => 50])->assertNotified('Tidak bisa mengoreksi jumlah');
        $this->assertEquals(9, (float) $material->fresh()->current_stock);
        $this->assertEquals(1, (float) $row->fresh()->qty_taken);

        $this->manager($memo)->callTableAction('edit_qty', $row->fresh(), data: ['qty' => 0])->assertHasTableActionErrors(['qty']);
    }

    public function test_correcting_roll_meters_adjusts_the_remaining_length(): void
    {
        $memo = $this->memo($this->storeA);
        $item = $this->roll('SC-MEMO-3', 15);
        $this->as($this->user('kasir', null, $this->storeA));
        $row = \App\Services\MaterialMemoStockService::addInventory($item, $memo, 5, auth()->id(), null);

        $this->manager($memo)->callTableAction('edit_qty', $row, data: ['qty' => 8])->assertHasNoTableActionErrors();
        $this->assertEquals(7, (float) ScrollCode::find($item->scroll_code_id)->remaining_length_meters);
        $this->assertEquals(8, (float) $row->fresh()->meters_used);

        $this->manager($memo)->callTableAction('edit_qty', $row->fresh(), data: ['qty' => 2])->assertHasNoTableActionErrors();
        $this->assertEquals(13, (float) ScrollCode::find($item->scroll_code_id)->remaining_length_meters);

        $this->manager($memo)->callTableAction('edit_qty', $row->fresh(), data: ['qty' => 40])->assertNotified('Tidak bisa mengoreksi jumlah');
        $this->assertEquals(13, (float) ScrollCode::find($item->scroll_code_id)->remaining_length_meters);
    }

    // ------------------------------------------------------------- hapus barang / hapus memo

    public function test_deleting_a_row_gives_the_stock_back(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $roll = $this->roll('SC-MEMO-4', 15);
        $this->as($this->user('kasir', null, $this->storeA));
        $rowA = \App\Services\MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 4, auth()->id(), null);
        $rowB = \App\Services\MaterialMemoStockService::addInventory($roll, $memo, 3, auth()->id(), null);

        $this->manager($memo)->callTableAction('delete_item', $rowA)->assertHasNoTableActionErrors();
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $this->assertNull(MaterialMemoItem::find($rowA->id));

        $this->manager($memo)->callTableAction('delete_item', $rowB)->assertHasNoTableActionErrors();
        $scroll = ScrollCode::find($roll->scroll_code_id);
        $this->assertEquals(15, (float) $scroll->remaining_length_meters);
        $this->assertSame(0, $scroll->usage_count);
        $this->assertNull(MaterialMemoItem::find($rowB->id));
    }

    public function test_deleting_a_partly_returned_row_only_gives_back_what_is_still_out(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $this->as($this->user('kasir', null, $this->storeA));
        $row = \App\Services\MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 5, auth()->id(), null);
        \App\Services\MaterialMemoStockService::returnMaterial($row, 2, auth()->id(), $memo);
        $this->assertEquals(7, (float) $material->fresh()->current_stock);

        $this->manager($memo)->callTableAction('delete_item', $row->fresh())->assertHasNoTableActionErrors();

        $this->assertEquals(10, (float) $material->fresh()->current_stock, 'Yang sudah dikembalikan tidak dikembalikan dua kali.');
    }

    public function test_only_full_access_can_delete_a_memo_and_it_reverses_everything(): void
    {
        $memo = $this->memo($this->storeA);
        $material = $this->material('Adhesive', 10);
        $roll = $this->roll('SC-MEMO-5', 15);
        $admin = $this->user('super_admin');
        \App\Services\MaterialMemoStockService::addMaterial($material, 'raw_material', $memo, 4, $admin->id, null);
        \App\Services\MaterialMemoStockService::addInventory($roll, $memo, 6, $admin->id, null);

        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(EditMaterialMemo::class, ['record' => $memo->getRouteKey()])->assertActionHidden('delete');

        $this->as($admin);
        Livewire::test(EditMaterialMemo::class, ['record' => $memo->getRouteKey()])
            ->assertActionVisible('delete')
            ->callAction('delete');

        $this->assertNull(MaterialMemo::withoutGlobalScopes()->find($memo->id));
        $this->assertSame(0, MaterialMemoItem::count());
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $this->assertEquals(15, (float) ScrollCode::find($roll->scroll_code_id)->remaining_length_meters);
    }

    // ------------------------------------------------------------- isi dari Master Resep

    public function test_filling_from_the_recipe_adds_materials_once_and_skips_rolls(): void
    {
        $material = $this->material('Slip Solution', 10);
        $consumable = $this->consumable('Squeegee', 5);
        $product = FilmProduct::create(['sku' => 'PPF-RESEP-1', 'name' => 'PPF Resep', 'product_type' => 'ppf', 'position' => 'front', 'base_price' => 1, 'is_active' => true]);
        FilmProductRecipeItem::create(['film_product_id' => $product->id, 'item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => $material->name, 'unit' => 'liter', 'standard_qty' => 2]);
        FilmProductRecipeItem::create(['film_product_id' => $product->id, 'item_type' => 'consumable_item', 'item_id' => $consumable->id, 'item_name' => $consumable->name, 'unit' => 'pcs', 'standard_qty' => 1]);
        FilmProductRecipeItem::create(['film_product_id' => $product->id, 'item_type' => 'film_roll', 'item_id' => 999, 'item_name' => 'Roll', 'unit' => 'meter', 'standard_qty' => 5]);

        $booking = $this->booking($this->storeA);
        $booking->update(['film_product_id' => $product->id]);
        $memo = $this->memo($this->storeA, ['booking_id' => $booking->id]);

        $this->as($this->user('kasir', null, $this->storeA));
        $this->manager($memo)->callTableAction('isi_dari_resep')->assertHasNoTableActionErrors();
        $this->manager($memo)->callTableAction('isi_dari_resep')->assertHasNoTableActionErrors();

        $this->assertSame(2, $memo->items()->count(), 'Dijalankan dua kali tidak menggandakan, roll dilewati.');
        $this->assertEquals(8, (float) $material->fresh()->current_stock);
        $this->assertEquals(4, (float) $consumable->fresh()->current_stock);
    }

    public function test_fill_from_recipe_is_hidden_without_a_booking(): void
    {
        $memo = $this->memo($this->storeA);
        $this->as($this->user('kasir', null, $this->storeA));

        $this->manager($memo)->assertTableActionHidden('isi_dari_resep');
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_rows_are_scoped_and_the_download_is_logged(): void
    {
        $mine = $this->memo($this->storeA, ['memo_number' => 'MEMO-EXP-A', 'vehicle_info' => 'Avanza']);
        $theirs = $this->memo($this->storeB, ['memo_number' => 'MEMO-EXP-B']);
        MaterialMemoItem::create(['material_memo_id' => $mine->id, 'item_type' => 'raw_material', 'item_id' => 1, 'item_name' => 'Adhesive', 'unit' => 'liter', 'qty_taken' => 5, 'qty_returned' => 2, 'qty_used' => 3]);
        MaterialMemoItem::create(['material_memo_id' => $mine->id, 'item_type' => 'inventory_item', 'item_id' => 2, 'item_name' => 'Film (SC-1)', 'unit' => 'meter', 'meters_used' => 4]);
        MaterialMemoItem::create(['material_memo_id' => $theirs->id, 'item_type' => 'raw_material', 'item_id' => 1, 'item_name' => 'Adhesive B', 'unit' => 'liter', 'qty_taken' => 1]);

        $this->as($this->user('super_admin'));
        $all = (new MaterialMemoItemExport())->query()->get();
        $this->assertCount(3, $all);
        $scoped = (new MaterialMemoItemExport($this->storeA->id))->query()->get();
        $this->assertCount(2, $scoped);
        $this->assertSame(['MEMO-EXP-A'], $scoped->pluck('memo.memo_number')->unique()->values()->all());

        $export = new MaterialMemoItemExport($this->storeA->id);
        $rows = $scoped->map(fn ($item) => $export->map($item))->keyBy(8);
        $this->assertSame('5.00 liter', $rows['Adhesive'][9]);
        $this->assertSame('2.00 liter', $rows['Adhesive'][10]);
        $this->assertSame('3.00 liter', $rows['Adhesive'][11]);
        $this->assertSame('Bahan Baku', $rows['Adhesive'][7]);
        $this->assertSame('PPF/WF', $rows['Film (SC-1)'][7]);
        $this->assertSame('4.00 meter', $rows['Film (SC-1)'][12]);

        Excel::fake();
        $this->as($this->user('kasir', null, $this->storeA));
        Livewire::test(ListMaterialMemos::class)->callTableAction('export')->assertHasNoTableActionErrors();
        Excel::assertDownloaded('memo-pengambilan-pengembalian-20261008.xlsx');

        $log = Activity::where('log_name', 'report_export')->latest('id')->firstOrFail();
        $this->assertSame('material_memo', $log->properties['report']);
        $this->assertSame($this->storeA->id, $log->properties['store_id']);
    }
}
