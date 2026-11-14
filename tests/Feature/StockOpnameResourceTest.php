<?php

namespace Tests\Feature;

use App\Filament\Resources\StockOpnameResource;
use App\Filament\Resources\StockOpnameResource\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnameResource\Pages\ViewStockOpname;
use App\Filament\Resources\StockOpnameResource\RelationManagers\ItemsRelationManager;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Store;
use App\Models\User;
use App\Services\StockOpnameService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stok Opname: daftar + lihat saja, staf hanya melihat sesi tokonya, sesi dibuat lewat aksi header (toko dipaksa untuk staf,
 * stok disesuaikan lewat adjustStock + movement bertanda toko, baris item menyimpan stok sistem/hasil hitung/selisih),
 * penomoran aman lintas toko, validasi (kosong, item ganda, negatif, item tidak ada) dan rincian item di halaman lihat.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class StockOpnameResourceTest extends TestCase
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
        RawMaterial::query()->delete();
        ConsumableItem::query()->delete();
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->storeA)->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    /** Penghitung: menu Stok Opname dicentang dengan izin Tambah. */
    private function counter(Store $store, array $extra = []): User
    {
        return $this->user('kasir', $store, ['StockOpnameResource'], array_merge(['menu_permissions' => ['StockOpnameResource' => ['create']]], $extra));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function material(string $name = 'Adhesive', float $stock = 10): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => 'RM-' . strtoupper(uniqid()), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => $stock]);
    }

    private function consumable(string $name = 'Sarung Tangan', float $stock = 10): ConsumableItem
    {
        return ConsumableItem::create(['name' => $name, 'code' => 'CI-' . strtoupper(uniqid()), 'category' => 'umum', 'unit' => 'pcs', 'current_stock' => $stock]);
    }

    private function opname(Store $store, array $extra = []): StockOpname
    {
        return StockOpname::create(array_merge(['opname_number' => StockOpname::generateOpnameNumber(), 'store_id' => $store->id, 'opname_date' => '2026-10-08'], $extra));
    }

    private function formData(Store $store, array $items, array $extra = []): array
    {
        return array_merge(['store_id' => $store->id, 'opname_date' => '2026-10-08', 'notes' => null, 'items' => $items], $extra);
    }

    // ------------------------------------------------------------- akses & cakupan toko

    public function test_access_is_view_only_and_follows_the_menu_checkbox(): void
    {
        $record = new StockOpname();

        $this->as($this->user('super_admin'));
        $this->assertTrue(StockOpnameResource::canAccess());
        $this->assertTrue(StockOpnameResource::canViewAny());
        $this->assertTrue(StockOpnameResource::canView($record));
        $this->assertFalse(StockOpnameResource::canCreate());
        $this->assertFalse(StockOpnameResource::canEdit($record));
        $this->assertFalse(StockOpnameResource::canDelete($record));

        // Akses eksplisit: akun dengan menu kosong (akses bawaan) tidak ikut; izin Tambah diperlukan untuk menyetor hasil hitung.
        $this->as($this->user('kasir'));
        $this->assertFalse(StockOpnameResource::canAccess());

        $this->as($this->user('kasir', null, ['StockOpnameResource']));
        $this->assertTrue(StockOpnameResource::canAccess());
        $this->assertFalse(StockOpnameResource::canSubmit());

        $this->as($this->counter($this->storeA));
        $this->assertTrue(StockOpnameResource::canSubmit());

        $this->as($this->user('super_admin'));
        $this->assertTrue(StockOpnameResource::canSubmit());

        $this->as($this->user('kasir', null, ['BookingResource']));
        $this->assertFalse(StockOpnameResource::canAccess());
        $this->assertFalse(StockOpnameResource::canView($record));
    }

    public function test_staff_only_see_and_open_sessions_of_their_own_store(): void
    {
        $mine = $this->opname($this->storeA);
        $theirs = $this->opname($this->storeB);

        $this->as($this->counter($this->storeA));
        Livewire::test(ListStockOpnames::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        try {
            Livewire::test(ViewStockOpname::class, ['record' => $theirs->getRouteKey()]);
            $this->fail('Sesi toko lain tidak boleh bisa dibuka.');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }

        $this->as($this->user('super_admin'));
        Livewire::test(ListStockOpnames::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_list_search_and_item_count(): void
    {
        $material = $this->material();
        $one = $this->opname($this->storeA);
        StockOpnameItem::create(['stock_opname_id' => $one->id, 'item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Adhesive', 'unit' => 'liter', 'system_quantity' => 10, 'actual_quantity' => 9, 'delta' => -1]);
        $other = $this->opname($this->storeA);

        $this->as($this->user('super_admin'));
        Livewire::test(ListStockOpnames::class)
            ->searchTable($one->opname_number)
            ->assertCanSeeTableRecords([$one])
            ->assertCanNotSeeTableRecords([$other]);
    }

    // ------------------------------------------------------------- membuat sesi

    public function test_staff_create_adjusts_stock_tags_the_store_and_snapshots_the_delta(): void
    {
        $material = $this->material('Adhesive', 10);
        $consumable = $this->consumable('Sarung Tangan', 5);
        $staff = $this->as($this->counter($this->storeA));

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeB, [
                ['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 7.5],
                ['item_type' => 'consumable_item', 'item_id' => $consumable->id, 'actual_quantity' => 8],
            ], ['notes' => 'Opname bulanan']))
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Hasil hitung dicatat sebagai draf OPN-20261008-0001');

        $opname = StockOpname::withoutGlobalScopes()->with('items')->firstOrFail();
        $this->assertSame('draft', $opname->status, 'Hasil hitung staf tersimpan sebagai draf.');
        $this->assertEquals(10, (float) $material->fresh()->current_stock, 'Stok belum berubah sebelum diterapkan.');
        $this->assertEquals(5, (float) $consumable->fresh()->current_stock);
        $this->assertSame(0, $material->movements()->where('type', 'adjustment')->count());

        // Akses penuh menerapkannya.
        $this->as($this->user('super_admin'));
        Livewire::test(\App\Filament\Resources\StockOpnameResource\Pages\ListStockOpnames::class)->callTableAction('apply', $opname)->assertHasNoTableActionErrors();
        $opname = StockOpname::withoutGlobalScopes()->with('items')->firstOrFail();
        $this->assertSame('applied', $opname->status);
        $this->assertSame($this->storeA->id, $opname->store_id, 'Staff tidak bisa membuat sesi untuk toko lain.');
        $this->assertSame($staff->id, $opname->created_by);
        $this->assertSame('OPN-20261008-0001', $opname->opname_number);
        $this->assertSame('Opname bulanan', $opname->notes);
        $this->assertSame('2026-10-08', $opname->opname_date->toDateString());

        $this->assertEquals(7.5, (float) $material->fresh()->current_stock);
        $this->assertEquals(8, (float) $consumable->fresh()->current_stock);

        $rawRow = $opname->items->firstWhere('item_type', 'raw_material');
        $this->assertEquals([10.0, 7.5, -2.5], [(float) $rawRow->system_quantity, (float) $rawRow->actual_quantity, (float) $rawRow->delta]);
        $this->assertSame('Adhesive', $rawRow->item_name);
        $this->assertSame('liter', $rawRow->unit);
        $consumableRow = $opname->items->firstWhere('item_type', 'consumable_item');
        $this->assertEquals(3.0, (float) $consumableRow->delta);

        $movement = $material->movements()->where('type', 'adjustment')->firstOrFail();
        $this->assertSame($this->storeA->id, $movement->store_id);
        $this->assertStringContainsString('OPN-20261008-0001', $movement->note);
    }

    public function test_admin_can_choose_the_store(): void
    {
        $material = $this->material();
        $this->as($this->user('super_admin'));

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeB, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9]]))
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame($this->storeB->id, StockOpname::withoutGlobalScopes()->firstOrFail()->store_id);
    }

    public function test_numbers_stay_unique_across_stores_on_the_same_day(): void
    {
        $material = $this->material();

        $this->as($this->counter($this->storeA));
        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9]]))
            ->callMountedTableAction()
            ->assertNotified();

        $this->as($this->counter($this->storeB));
        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeB, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 8]]))
            ->callMountedTableAction()
            ->assertNotified();

        $numbers = StockOpname::withoutGlobalScopes()->orderBy('id')->pluck('opname_number')->all();
        $this->assertSame(['OPN-20261008-0001', 'OPN-20261008-0002'], $numbers, 'Staff toko B tidak menghasilkan nomor yang sama dengan toko A.');
    }

    public function test_an_item_with_no_discrepancy_is_recorded_without_a_movement(): void
    {
        $material = $this->material('Adhesive', 10);
        $this->as($this->counter($this->storeA));

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 10]]))
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $row = StockOpnameItem::firstOrFail();
        $this->assertEquals(0, (float) $row->delta);
        $this->assertSame(0, $material->movements()->count());
    }

    // ------------------------------------------------------------- validasi

    public function test_form_validation(): void
    {
        $material = $this->material();
        $this->as($this->counter($this->storeA));

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, []))
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['items']);

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => -1]]))
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['items.0.actual_quantity']);

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => null]]))
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['items.0.actual_quantity' => 'required']);

        $this->assertSame(0, StockOpname::withoutGlobalScopes()->count());
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
    }

    public function test_a_duplicate_item_or_missing_item_rolls_the_whole_session_back(): void
    {
        $material = $this->material('Adhesive', 10);
        $other = $this->material('Slip', 4);
        $this->as($this->counter($this->storeA));

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [
                ['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9],
                ['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 8],
            ]))
            ->callMountedTableAction()
            ->assertNotified('Item yang sama tidak boleh dihitung dua kali dalam satu sesi.');

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [
                ['item_type' => 'raw_material', 'item_id' => $other->id, 'actual_quantity' => 1],
                ['item_type' => 'raw_material', 'item_id' => 999999, 'actual_quantity' => 1],
            ]))
            ->callMountedTableAction();

        $this->assertSame(0, StockOpname::withoutGlobalScopes()->count());
        $this->assertSame(0, StockOpnameItem::count());
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $this->assertEquals(4, (float) $other->fresh()->current_stock, 'Item yang sudah diproses ikut dibatalkan.');
    }

    public function test_a_staff_account_without_a_store_cannot_create_a_session(): void
    {
        $material = $this->material();
        $this->as(tap(User::create(['name' => 'Tanpa Toko', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => null, 'is_active' => true, 'menu_access' => ['StockOpnameResource'], 'menu_permissions' => ['StockOpnameResource' => ['create']]]), fn (User $u) => $u->assignRole('kasir')));

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9]]))
            ->callMountedTableAction()
            ->assertNotified('Akun ini belum terikat ke toko, sesi tidak bisa dibuat.');

        $this->assertSame(0, StockOpname::withoutGlobalScopes()->count());
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
    }

    public function test_the_service_rejects_unknown_item_types_and_empty_sessions(): void
    {
        $service = app(StockOpnameService::class);

        try {
            $service->create($this->storeA->id, '2026-10-08', null, [], null);
            $this->fail('Sesi kosong harus ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('minimal 1 item', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jenis item tidak dikenal');
        $service->create($this->storeA->id, '2026-10-08', null, [['item_type' => 'asset', 'item_id' => 1, 'actual_quantity' => 1]], null);
    }

    // ------------------------------------------------------------- rincian

    public function test_the_view_page_shows_the_session_and_its_items(): void
    {
        $material = $this->material('Adhesive', 10);
        $consumable = $this->consumable('Sarung Tangan', 5);
        $admin = $this->as($this->user('super_admin'));

        $opname = app(StockOpnameService::class)->create($this->storeA->id, '2026-10-08', 'Opname rutin', [
            ['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 7],
            ['item_type' => 'consumable_item', 'item_id' => $consumable->id, 'actual_quantity' => 8],
        ], $admin->id);

        Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($opname->opname_number)
            ->assertSee('Toko A')
            ->assertSee('Opname rutin');

        $rows = $opname->items;
        $adhesive = $rows->firstWhere('item_type', 'raw_material');
        $gloves = $rows->firstWhere('item_type', 'consumable_item');

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $opname, 'pageClass' => ViewStockOpname::class])
            ->assertCanSeeTableRecords([$adhesive, $gloves])
            ->assertTableColumnFormattedStateSet('system_quantity', '10.00 liter', record: $adhesive)
            ->assertTableColumnFormattedStateSet('actual_quantity', '7.00 liter', record: $adhesive)
            ->assertTableColumnFormattedStateSet('delta', '-3.00 liter', record: $adhesive)
            ->assertTableColumnFormattedStateSet('delta', '+3.00 pcs', record: $gloves)
            ->assertTableColumnFormattedStateSet('item_type', 'Barang Habis Pakai', record: $gloves);
    }
    // ------------------------------------------------------------- audit 2026-11-03: alur dua tahap, lembar hitung, impor

    public function test_only_full_access_applies_and_the_creator_or_full_access_discards_a_draft(): void
    {
        $material = $this->material('Adhesive', 10);
        $creator = $this->counter($this->storeA);
        $draft = app(StockOpnameService::class)->submit($this->storeA->id, '2026-10-08', null, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 6]], $creator->id);

        $this->as($creator);
        Livewire::test(ListStockOpnames::class)
            ->assertTableActionHidden('apply', $draft)
            ->assertTableActionVisible('discard', $draft);

        $this->as($this->counter($this->storeA));
        Livewire::test(ListStockOpnames::class)->assertTableActionHidden('discard', $draft);

        $this->as($this->user('super_admin'));
        Livewire::test(ListStockOpnames::class)
            ->assertTableActionVisible('apply', $draft)
            ->callTableAction('discard', $draft, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        Livewire::test(ListStockOpnames::class)->callTableAction('discard', $draft, data: ['reason' => 'Salah toko'])->assertHasNoTableActionErrors();

        $fresh = StockOpname::withoutGlobalScopes()->findOrFail($draft->id);
        $this->assertSame(['cancelled', 'Salah toko'], [$fresh->status, $fresh->cancel_reason]);
        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        Livewire::test(ListStockOpnames::class)->assertTableActionHidden('apply', $fresh)->assertTableActionHidden('discard', $fresh);
    }

    public function test_admin_can_apply_immediately_from_the_form_and_staff_cannot(): void
    {
        $material = $this->material('Adhesive', 10);

        $this->as($this->user('super_admin'));
        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 4]], ['apply_now' => true]))
            ->callMountedTableAction()
            ->assertNotified('Sesi Stok Opname dicatat, stok disesuaikan.');
        $this->assertEquals(4, (float) $material->fresh()->current_stock);
        $this->assertSame('applied', StockOpname::withoutGlobalScopes()->firstOrFail()->status);

        // Staf yang memaksa apply_now dari klien tetap hanya menghasilkan draf.
        $other = $this->material('Slip', 10);
        $this->as($this->counter($this->storeA));
        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $other->id, 'actual_quantity' => 1]], ['apply_now' => true]))
            ->callMountedTableAction();
        $this->assertEquals(10, (float) $other->fresh()->current_stock);
        $this->assertSame(1, StockOpname::withoutGlobalScopes()->where('status', 'draft')->count());
    }

    public function test_the_session_form_needs_a_note_for_large_differences_and_refuses_a_future_date(): void
    {
        $material = $this->material('Adhesive', 10);
        $material->update(['adjustment_note_threshold' => 2]);
        $this->as($this->counter($this->storeA));

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 3]]))
            ->callMountedTableAction()
            ->assertNotified();
        $this->assertSame(0, StockOpname::withoutGlobalScopes()->count());

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 3, 'note' => 'Tumpah']]))
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        $this->assertSame('Tumpah', \App\Models\StockOpnameItem::firstOrFail()->note);

        Livewire::test(ListStockOpnames::class)
            ->mountTableAction('create_opname')
            ->set('mountedTableActionsData.0.items', [])
            ->setTableActionData($this->formData($this->storeA, [['item_type' => 'raw_material', 'item_id' => $material->id, 'actual_quantity' => 9]], ['opname_date' => '2026-10-20']))
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['opname_date']);
    }

    public function test_item_search_is_blind_active_only_and_limited(): void
    {
        $this->material('Adhesive Aktif', 10);
        RawMaterial::create(['name' => 'Adhesive Mati Kosong', 'code' => 'RM-MK', 'unit' => 'liter', 'current_stock' => 0, 'is_active' => false]);
        RawMaterial::create(['name' => 'Adhesive Mati Berstok', 'code' => 'RM-MB', 'unit' => 'liter', 'current_stock' => 3, 'is_active' => false]);

        $labels = array_values(StockOpnameResource::searchItems('raw_material', 'adhesive'));

        $this->assertCount(2, $labels);
        $this->assertStringNotContainsString('stok sistem', implode(' ', $labels));
        $this->assertSame([], StockOpnameResource::searchItems('asset', 'x'));
    }

    public function test_the_count_sheet_is_blind_and_an_filled_sheet_is_imported_as_a_draft(): void
    {
        $adhesive = $this->material('Adhesive', 10);
        $gloves = $this->consumable('Sarung Tangan', 5);
        $rows = \App\Exports\StockOpnameSheetExport::rows();
        $this->assertCount(2, $rows);

        $sheet = new \App\Exports\StockOpnameSheetExport($rows);
        $this->assertSame(['Jenis', 'ID', 'Kode', 'Nama', 'Kategori', 'Satuan', 'Hasil Hitung', 'Catatan'], $sheet->headings());
        $this->assertNotContains('Stok Sistem', $sheet->headings());
        $this->assertSame([null, null], array_slice($sheet->array()[0], 6), 'Hasil Hitung dan Catatan kosong.');

        // Berkas yang sudah diisi (CSV): satu baris dihitung, satu dikosongkan, satu angka rusak.
        $path = sys_get_temp_dir() . '/lembar-' . uniqid() . '.csv';
        file_put_contents($path, implode("\n", [
            'Jenis,ID,Kode,Nama,Kategori,Satuan,Hasil Hitung,Catatan',
            "Bahan Baku,{$adhesive->id},X,Adhesive,chemical,liter,7.5,Tumpah",
            "Barang Habis Pakai,{$gloves->id},X,Sarung,umum,pcs,,",
        ]));
        $parsed = StockOpnameResource::parseCountSheet($path);
        @unlink($path);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([['item_type' => 'raw_material', 'item_id' => $adhesive->id, 'actual_quantity' => 7.5, 'note' => 'Tumpah']], $parsed['items']);

        $bad = sys_get_temp_dir() . '/lembar-' . uniqid() . '.csv';
        file_put_contents($bad, "Jenis,ID,Kode,Nama,Kategori,Satuan,Hasil Hitung,Catatan\nBahan Baku,{$adhesive->id},X,A,c,l,banyak,\nLain,1,X,A,c,l,3,\n");
        $errors = StockOpnameResource::parseCountSheet($bad)['errors'];
        @unlink($bad);
        $this->assertCount(2, $errors);

        $noHeader = sys_get_temp_dir() . '/lembar-' . uniqid() . '.csv';
        file_put_contents($noHeader, "a,b\n1,2\n");
        $this->assertStringContainsString('tidak ditemukan', StockOpnameResource::parseCountSheet($noHeader)['errors'][0]);
        @unlink($noHeader);
    }

    public function test_the_view_page_summarises_the_session_and_the_items_filter_and_exports_work(): void
    {
        $same = $this->material('Cocok', 10);
        $off = $this->material('Selisih', 10);
        $admin = $this->as($this->user('super_admin'));
        $opname = app(StockOpnameService::class)->submit($this->storeA->id, '2026-10-08', 'Rak A', [
            ['item_type' => 'raw_material', 'item_id' => $same->id, 'actual_quantity' => 10],
            ['item_type' => 'raw_material', 'item_id' => $off->id, 'actual_quantity' => 7, 'note' => 'Tumpah'],
        ], $admin->id);

        Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('2 item dihitung, 1 berselisih')
            ->assertSee('Draf (menunggu diterapkan)')
            ->assertActionVisible('apply');

        $rows = $opname->items;
        $cocok = $rows->firstWhere('item_name', 'Cocok');
        $selisih = $rows->firstWhere('item_name', 'Selisih');
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $opname, 'pageClass' => ViewStockOpname::class])
            ->assertCanSeeTableRecords([$cocok, $selisih])
            ->filterTable('has_difference', true)
            ->assertCanSeeTableRecords([$selisih])->assertCanNotSeeTableRecords([$cocok]);

        $export = new \App\Exports\StockOpnameExport($opname->load('items', 'store'));
        $this->assertContains(['Selisih', 'Bahan Baku', 10.0, 7.0, -3.0, 'liter', 'Tumpah'], $export->array());
        $this->assertStringContainsString($opname->opname_number, $export->headings()[0][0]);

        Livewire::test(ListStockOpnames::class)
            ->assertTableColumnStateSet('diff_count', 1, StockOpnameResource::getEloquentQuery()->findOrFail($opname->id))
            ->filterTable('status', 'draft')->assertCanSeeTableRecords([$opname])
            ->filterTable('status', 'applied')->assertCanNotSeeTableRecords([$opname]);
    }
}
