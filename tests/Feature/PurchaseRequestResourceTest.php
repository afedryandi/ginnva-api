<?php

namespace Tests\Feature;

use App\Exports\PurchaseRequestExport;
use App\Filament\Resources\PurchaseRequestResource;
use App\Filament\Resources\PurchaseRequestResource\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequestResource\Pages\EditPurchaseRequest;
use App\Filament\Resources\PurchaseRequestResource\Pages\ListPurchaseRequests;
use App\Models\ChartOfAccount;
use App\Models\ConsumableItem;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PushNotificationService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Permohonan Pembelian: hak akses (ubah hanya selagi menunggu, hapus hanya full-access), staf hanya melihat tokonya, ajukan
 * (toko dipaksa, nama/satuan diambil dari katalog, validasi), setujui / tolak, tandai terpenuhi (jurnal Persediaan/Aset vs
 * Hutang Usaha + tagihan), hapus, dan ekspor. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class PurchaseRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->mock(PushNotificationService::class)->shouldReceive('sendToUsers')->andReturnNull();
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

    /** Staf permohonan pembelian: menu dicentang dengan izin Tambah, Ubah, dan Lihat (harga). Akses bawaan tidak lagi membuka menu ini. */
    private function writer(?Store $store = null, array $permissions = ['create', 'update', 'view']): User
    {
        return $this->user('kasir', ['PurchaseRequestResource'], $store, ['menu_permissions' => ['PurchaseRequestResource' => $permissions]]);
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function request(Store $store, array $extra = []): PurchaseRequest
    {
        return PurchaseRequest::create(array_merge(['store_id' => $store->id, 'item_type' => 'asset', 'item_name' => 'Kompresor', 'quantity' => 1, 'status' => 'pending', 'requested_by' => $this->user('super_admin')->id], $extra));
    }

    private function material(string $name = 'Adhesive'): RawMaterial
    {
        return RawMaterial::create(['name' => $name, 'code' => 'RM-' . uniqid(), 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 5]);
    }

    // ------------------------------------------------------------- akses & cakupan toko

    public function test_access_rules(): void
    {
        $pending = new PurchaseRequest(['status' => 'pending']);
        $approved = new PurchaseRequest(['status' => 'approved']);

        $this->as($this->user('super_admin'));
        $this->assertTrue(PurchaseRequestResource::canViewAny());
        $this->assertTrue(PurchaseRequestResource::canCreate());
        $this->assertTrue(PurchaseRequestResource::canEdit($pending));
        $this->assertFalse(PurchaseRequestResource::canEdit($approved), 'Yang sudah diproses terkunci.');
        $this->assertTrue(PurchaseRequestResource::canDelete($pending));

        // Akses bawaan (menu tidak dicentang) tertutup; harus dicentang eksplisit.
        $this->as($this->user('kasir'));
        $this->assertFalse(PurchaseRequestResource::canViewAny());
        $this->assertFalse(PurchaseRequestResource::canCreate());

        $this->as($this->writer());
        $this->assertTrue(PurchaseRequestResource::canViewAny());
        $this->assertTrue(PurchaseRequestResource::canCreate());
        $this->assertTrue(PurchaseRequestResource::canEdit($pending));
        $this->assertFalse(PurchaseRequestResource::canEdit(new PurchaseRequest(['status' => 'fulfilled'])));
        $this->assertFalse(PurchaseRequestResource::canDelete($pending));
        $this->assertTrue(PurchaseRequestResource::canSeeCost());

        // Menu dicentang tanpa izin tambah/ubah/lihat: hanya membaca, tanpa harga.
        $this->as($this->writer(null, []));
        $this->assertTrue(PurchaseRequestResource::canViewAny());
        $this->assertFalse(PurchaseRequestResource::canCreate());
        $this->assertFalse(PurchaseRequestResource::canEdit($pending));
        $this->assertFalse(PurchaseRequestResource::canSeeCost());

        $this->as($this->writer(null, ['delete']));
        $this->assertTrue(PurchaseRequestResource::canDelete($pending));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(PurchaseRequestResource::canViewAny());
        $this->assertFalse(PurchaseRequestResource::canCreate());
    }

    public function test_staff_only_see_their_own_store(): void
    {
        $mine = $this->request($this->storeA, ['item_name' => 'Punya A']);
        $theirs = $this->request($this->storeB, ['item_name' => 'Punya B']);

        $this->as($this->writer($this->storeA));
        Livewire::test(ListPurchaseRequests::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableFilterHidden('store_id');

        try {
            Livewire::test(EditPurchaseRequest::class, ['record' => $theirs->getRouteKey()]);
            $this->fail('Permohonan toko lain tidak boleh bisa dibuka.');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }

        $this->as($this->user('super_admin'));
        Livewire::test(ListPurchaseRequests::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->assertTableFilterVisible('store_id')
            ->filterTable('store_id', $this->storeB->id)
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$mine]);
    }

    public function test_list_filters_search_and_navigation_badge(): void
    {
        $pending = $this->request($this->storeA, ['item_name' => 'Kompresor Besar']);
        $approved = $this->request($this->storeA, ['item_name' => 'Lampu', 'status' => 'approved']);
        $material = $this->request($this->storeA, ['item_type' => 'raw_material', 'item_name' => 'Adhesive']);
        $this->request($this->storeB, ['item_name' => 'Toko B']);

        $this->as($this->writer($this->storeA));
        Livewire::test(ListPurchaseRequests::class)
            ->filterTable('status', 'approved')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending, $material]);
        Livewire::test(ListPurchaseRequests::class)
            ->filterTable('item_type', 'raw_material')
            ->assertCanSeeTableRecords([$material])
            ->assertCanNotSeeTableRecords([$pending, $approved]);
        Livewire::test(ListPurchaseRequests::class)
            ->searchTable('Kompresor')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved]);

        $this->assertSame('2', PurchaseRequestResource::getNavigationBadge(), 'Hanya yang menunggu di toko sendiri.');
    }

    // ------------------------------------------------------------- mengajukan

    public function test_staff_create_snapshots_catalog_data_and_forces_own_store(): void
    {
        $staff = $this->as($this->writer($this->storeA));
        $material = $this->material('Slip Solution');

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['store_id' => $this->storeB->id, 'item_type' => 'raw_material', 'item_id' => $material->id, 'quantity' => 3, 'reason' => 'Stok menipis'])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = PurchaseRequest::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->storeA->id, $request->store_id, 'Staff tidak bisa mengajukan atas nama toko lain.');
        $this->assertSame('pending', $request->status);
        $this->assertSame($staff->id, $request->requested_by);
        $this->assertSame('Slip Solution', $request->item_name);
        $this->assertSame('liter', $request->unit);
        $this->assertEquals(3, (float) $request->quantity);
        $this->assertMatchesRegularExpression('/^PR-202610-[A-Z0-9]{4}$/', $request->request_number);
    }

    public function test_creating_a_consumable_and_an_asset_request(): void
    {
        $this->as($this->user('super_admin'));
        $consumable = ConsumableItem::create(['name' => 'Sarung Tangan', 'code' => 'CI-' . uniqid(), 'category' => 'umum', 'unit' => 'box', 'current_stock' => 1]);

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['store_id' => $this->storeB->id, 'item_type' => 'consumable_item', 'item_id' => $consumable->id, 'quantity' => 2])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['store_id' => $this->storeA->id, 'item_type' => 'asset', 'item_name' => 'Kompresor Baru', 'quantity' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        $c = PurchaseRequest::withoutGlobalScopes()->where('item_type', 'consumable_item')->firstOrFail();
        $this->assertSame($this->storeB->id, $c->store_id, 'Admin boleh memilih toko.');
        $this->assertSame('Sarung Tangan', $c->item_name);
        $this->assertSame('box', $c->unit);

        $a = PurchaseRequest::withoutGlobalScopes()->where('item_type', 'asset')->firstOrFail();
        $this->assertSame('Kompresor Baru', $a->item_name);
        $this->assertNull($a->item_id);
        $this->assertNull($a->unit);
    }

    public function test_create_validation(): void
    {
        $this->as($this->writer($this->storeA));
        $material = $this->material();

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['item_type' => 'raw_material', 'item_id' => $material->id, 'quantity' => 0])
            ->call('create')
            ->assertHasFormErrors(['quantity']);

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['item_type' => 'raw_material', 'item_id' => 999999, 'quantity' => 1])
            ->call('create')
            ->assertHasFormErrors(['item_id']);

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['item_type' => 'asset', 'item_name' => '', 'quantity' => 1])
            ->call('create')
            ->assertHasFormErrors(['item_name' => 'required']);

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['item_type' => 'laptop', 'quantity' => 1])
            ->call('create')
            ->assertHasFormErrors(['item_type']);

        $this->assertSame(0, PurchaseRequest::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------- mengubah

    public function test_editing_a_pending_request_keeps_the_review_fields(): void
    {
        $staff = $this->as($this->writer($this->storeA));
        $request = $this->request($this->storeA, ['requested_by' => $staff->id]);

        Livewire::test(EditPurchaseRequest::class, ['record' => $request->getRouteKey()])
            ->fillForm(['item_name' => 'Kompresor 2 PK', 'quantity' => 2, 'store_id' => $this->storeB->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $request->fresh();
        $this->assertSame('Kompresor 2 PK', $fresh->item_name);
        $this->assertEquals(2, (float) $fresh->quantity);
        $this->assertSame($this->storeA->id, $fresh->store_id);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_a_processed_request_cannot_be_opened_for_editing(): void
    {
        $this->as($this->user('super_admin'));
        $request = $this->request($this->storeA, ['status' => 'approved']);

        $this->get(PurchaseRequestResource::getUrl('edit', ['record' => $request]))->assertForbidden();

        Livewire::test(ListPurchaseRequests::class)
            ->assertTableActionHidden('edit', $request)
            ->assertTableActionHidden('delete', $request);
    }

    // ------------------------------------------------------------- setujui / tolak

    public function test_approve_is_for_full_access_only_and_only_while_pending(): void
    {
        $request = $this->request($this->storeA, ['requested_by' => $this->writer()->id]);

        $this->as($this->writer($this->storeA));
        Livewire::test(ListPurchaseRequests::class)
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('reject', $request);

        $admin = $this->as($this->user('super_admin'));
        Livewire::test(ListPurchaseRequests::class)
            ->assertTableActionVisible('approve', $request)
            ->callTableAction('approve', $request)
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);

        Livewire::test(ListPurchaseRequests::class)
            ->assertTableActionHidden('approve', $fresh)
            ->assertTableActionHidden('reject', $fresh);
    }

    public function test_reject_requires_a_note(): void
    {
        $request = $this->request($this->storeA);
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('reject', $request, data: ['review_note' => ''])
            ->assertHasTableActionErrors(['review_note' => 'required']);
        $this->assertSame('pending', $request->fresh()->status);

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('reject', $request, data: ['review_note' => 'Belum perlu'])
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Belum perlu', $fresh->review_note);
        $this->assertSame($admin->id, $fresh->reviewed_by);
    }

    // ------------------------------------------------------------- terpenuhi

    public function test_fulfilling_posts_the_journal_and_creates_the_payable(): void
    {
        $requester = $this->writer($this->storeA);
        $material = $this->material();
        $request = $this->request($this->storeA, ['item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Adhesive', 'unit' => 'liter', 'quantity' => 5, 'status' => 'approved', 'requested_by' => $requester->id]);
        $supplier = Supplier::create(['name' => 'PT Kimia Jaya']);

        $this->as($requester);
        Livewire::test(ListPurchaseRequests::class)
            ->assertTableActionVisible('fulfill', $request)
            ->callTableAction('fulfill', $request, data: ['actual_cost' => 250000, 'supplier_id' => $supplier->id, 'due_date' => '2026-11-01'])
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('fulfilled', $fresh->status);
        $this->assertEquals(250000, (float) $fresh->actual_cost);
        $this->assertNotNull($fresh->fulfilled_at);

        $entry = JournalEntry::with('lines')->findOrFail($fresh->journal_entry_id);
        $this->assertTrue($entry->isPosted());
        $this->assertTrue($entry->isBalanced());
        $inventory = ChartOfAccount::where('code', '1130')->value('id');
        $payable = ChartOfAccount::where('code', '2110')->value('id');
        $this->assertEquals(250000, (float) $entry->lines->firstWhere('chart_of_account_id', $inventory)->debit);
        $this->assertEquals(250000, (float) $entry->lines->firstWhere('chart_of_account_id', $payable)->credit);

        $bill = Payable::withoutGlobalScopes()->where('source_type', 'purchase_request')->where('source_id', $request->id)->firstOrFail();
        $this->assertEquals(250000, (float) $bill->amount);
        $this->assertSame($supplier->id, $bill->supplier_id);
        $this->assertSame($entry->id, $bill->journal_entry_id);
        $this->assertSame($this->storeA->id, $bill->store_id);

        Livewire::test(ListPurchaseRequests::class)->assertTableActionHidden('fulfill', $fresh);
    }

    public function test_fulfilling_can_record_the_received_quantity_as_stock(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material();
        $request = $this->request($this->storeA, ['item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Adhesive', 'unit' => 'liter', 'quantity' => 5, 'status' => 'approved', 'requested_by' => $admin->id]);
        $supplier = Supplier::create(['name' => 'PT Kimia Jaya']);

        $this->assertTrue(PurchaseRequestResource::canRecordStock($request));

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['quantity_received' => 4, 'actual_cost' => 200000, 'supplier_id' => $supplier->id, 'record_stock' => true, 'expiry_date' => '2027-05-01'])
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertNotNull($fresh->stock_recorded_at);
        $this->assertEquals(9, (float) $material->fresh()->current_stock, 'Stok 5 + 4 diterima.');

        $batch = $material->batches()->latest('id')->firstOrFail();
        $this->assertEquals(4, (float) $batch->quantity);
        $this->assertEquals(50000, (float) $batch->unit_cost, 'Total biaya aktual dibagi jumlah diterima.');
        $this->assertSame('2027-05-01', $batch->expiry_date->toDateString());

        $movement = $material->movements()->where('type', 'in')->latest('id')->firstOrFail();
        $this->assertSame($this->storeA->id, $movement->store_id);
        $this->assertStringContainsString($fresh->request_number, (string) $movement->note);

        $this->assertFalse(PurchaseRequestResource::canRecordStock($fresh), 'Sudah dicatat: tidak ditawarkan lagi.');
    }

    public function test_fulfilling_without_the_stock_toggle_or_the_stock_permission_leaves_the_stock_alone(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $material = $this->material();
        $supplier = Supplier::create(['name' => 'PT Kimia Jaya']);
        $off = $this->request($this->storeA, ['item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Adhesive', 'unit' => 'liter', 'quantity' => 5, 'status' => 'approved', 'requested_by' => $admin->id]);

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $off, data: ['quantity_received' => 5, 'actual_cost' => 100000, 'supplier_id' => $supplier->id, 'record_stock' => false])
            ->assertHasNoTableActionErrors();
        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $this->assertNull($off->fresh()->stock_recorded_at);

        // Pemohon tanpa izin mengubah stok bahan baku: toggle tidak ditawarkan dan dipaksa ikut pun diabaikan.
        $requester = $this->user('kasir', ['PurchaseRequestResource'], $this->storeA);
        $other = $this->request($this->storeA, ['item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Adhesive', 'unit' => 'liter', 'quantity' => 3, 'status' => 'approved', 'requested_by' => $requester->id]);
        $this->as($requester);
        $this->assertFalse(PurchaseRequestResource::canRecordStock($other));

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $other, data: ['quantity_received' => 3, 'actual_cost' => 90000, 'supplier_id' => $supplier->id, 'record_stock' => true])
            ->assertHasNoTableActionErrors();
        $this->assertSame('fulfilled', $other->fresh()->status);
        $this->assertEquals(5, (float) $material->fresh()->current_stock);
        $this->assertNull($other->fresh()->stock_recorded_at);
    }

    public function test_fulfilling_a_consumable_request_can_record_the_received_quantity_as_stock(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $item = ConsumableItem::create(['name' => 'Lakban', 'code' => 'CI-' . uniqid(), 'category' => 'umum', 'unit' => 'roll', 'current_stock' => 5]);
        $request = $this->request($this->storeA, ['item_type' => 'consumable_item', 'item_id' => $item->id, 'item_name' => 'Lakban', 'unit' => 'roll', 'quantity' => 5, 'status' => 'approved', 'requested_by' => $admin->id]);
        $supplier = Supplier::create(['name' => 'Toko Perlengkapan']);

        $this->assertTrue(PurchaseRequestResource::canRecordStock($request));

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['quantity_received' => 4, 'actual_cost' => 200000, 'supplier_id' => $supplier->id, 'record_stock' => true])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($request->fresh()->stock_recorded_at);
        $this->assertEquals(9, (float) $item->fresh()->current_stock, 'Stok 5 + 4 diterima.');
        $movement = $item->movements()->where('type', 'in')->latest('id')->firstOrFail();
        $this->assertSame($this->storeA->id, $movement->store_id);
        $this->assertEquals(50000, (float) $movement->unit_cost, 'Total biaya aktual dibagi jumlah diterima.');
        $this->assertFalse(PurchaseRequestResource::canRecordStock($request->fresh()));
    }

    public function test_only_the_requester_or_full_access_can_fulfill(): void
    {
        $requester = $this->writer($this->storeA);
        $request = $this->request($this->storeA, ['status' => 'approved', 'requested_by' => $requester->id]);

        // Staf berizin Ubah di toko yang sama (gudang) ikut boleh menerima; yang hanya boleh mengajukan tidak.
        $this->as($this->writer($this->storeA, ['create']));
        Livewire::test(ListPurchaseRequests::class)->assertTableActionHidden('fulfill', $request);
        $this->as($this->writer($this->storeA));
        Livewire::test(ListPurchaseRequests::class)->assertTableActionVisible('fulfill', $request);
        $this->as($this->writer($this->storeB));
        Livewire::test(ListPurchaseRequests::class)->assertTableActionHidden('fulfill', $request);

        $this->as($this->user('super_admin'));
        Livewire::test(ListPurchaseRequests::class)->assertTableActionVisible('fulfill', $request);

        $pending = $this->request($this->storeA, ['requested_by' => $requester->id]);
        $this->as($requester);
        Livewire::test(ListPurchaseRequests::class)->assertTableActionHidden('fulfill', $pending);
    }

    public function test_fulfilling_an_asset_needs_a_fixed_asset_account(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $request = $this->request($this->storeA, ['status' => 'approved', 'requested_by' => $admin->id]);
        $supplier = Supplier::create(['name' => 'PT Mesin']);
        $account = ChartOfAccount::whereHas('parent', fn ($q) => $q->where('code', '1200'))->orderBy('code')->firstOrFail();

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['actual_cost' => 5000000, 'supplier_id' => $supplier->id])
            ->assertHasTableActionErrors(['chart_of_account_id' => 'required']);
        $this->assertSame('approved', $request->fresh()->status);

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['actual_cost' => 5000000, 'supplier_id' => $supplier->id, 'chart_of_account_id' => $account->id])
            ->assertHasNoTableActionErrors();

        $entry = JournalEntry::with('lines')->findOrFail($request->fresh()->journal_entry_id);
        $this->assertEquals(5000000, (float) $entry->lines->firstWhere('chart_of_account_id', $account->id)->debit);
    }

    public function test_fulfill_validation(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $request = $this->request($this->storeA, ['status' => 'approved', 'requested_by' => $admin->id, 'item_type' => 'raw_material']);
        $supplier = Supplier::create(['name' => 'PT Kimia']);

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['actual_cost' => 0, 'supplier_id' => $supplier->id])
            ->assertHasTableActionErrors(['actual_cost']);
        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['actual_cost' => 1000, 'supplier_id' => null])
            ->assertHasTableActionErrors(['supplier_id' => 'required']);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(0, Payable::withoutGlobalScopes()->count());
    }

    public function test_a_failed_journal_rolls_everything_back(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $request = $this->request($this->storeA, ['status' => 'approved', 'requested_by' => $admin->id, 'item_type' => 'raw_material']);
        $supplier = Supplier::create(['name' => 'PT Kimia']);
        ChartOfAccount::where('code', '1130')->update(['is_active' => false]);

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['actual_cost' => 1000, 'supplier_id' => $supplier->id])
            ->assertNotified('Gagal menandai terpenuhi');

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status, 'Status ikut dibatalkan kalau jurnal gagal.');
        $this->assertNull($fresh->journal_entry_id);
        $this->assertSame(0, Payable::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------- hapus

    public function test_only_full_access_can_delete_and_only_while_pending(): void
    {
        $request = $this->request($this->storeA);

        $this->as($this->writer($this->storeA));
        Livewire::test(ListPurchaseRequests::class)->assertTableActionHidden('delete', $request);

        $this->as($this->user('super_admin'));
        Livewire::test(ListPurchaseRequests::class)
            ->assertTableActionVisible('delete', $request)
            ->callTableAction('delete', $request);

        $this->assertNull(PurchaseRequest::withoutGlobalScopes()->find($request->id));
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_is_scoped_and_downloads(): void
    {
        $this->request($this->storeA, ['item_name' => 'Barang A', 'quantity' => 2.5, 'unit' => 'liter', 'item_type' => 'raw_material', 'actual_cost' => 1000, 'status' => 'fulfilled']);
        $this->request($this->storeB, ['item_name' => 'Barang B']);

        $this->as($this->writer($this->storeA));
        $export = new PurchaseRequestExport();
        $rows = $export->collection();
        $this->assertSame(['No. Permohonan', 'Barang', 'Jenis', 'Jumlah', 'Toko', 'Status', 'Biaya Aktual', 'Diajukan Oleh', 'Tanggal'], $export->headings());
        $this->assertCount(1, $rows);
        $this->assertSame(['Barang A', 'Bahan Baku', '2.5 liter', 'Toko A', 'Terpenuhi', 1000.0], [$rows[0][1], $rows[0][2], $rows[0][3], $rows[0][4], $rows[0][5], $rows[0][6]]);

        $this->as($this->user('super_admin'));
        $this->assertCount(2, (new PurchaseRequestExport())->collection());

        Excel::fake();
        $page = Livewire::test(ListPurchaseRequests::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('permohonan-pembelian-20261008-100000.xlsx');
        $page->callAction('exportPdf')->assertFileDownloaded('permohonan-pembelian-20261008-100000.pdf');
    }
    // ------------------------------------------------------------- jumlah disetujui, batal, penolakan duplikat

    public function test_approver_can_approve_a_smaller_quantity_and_fulfil_defaults_to_it(): void
    {
        $request = $this->request($this->storeA, ['quantity' => 10, 'unit' => 'pcs']);

        $this->as($this->user('super_admin'));
        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('approve', $request, data: ['quantity_approved' => 6])
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertEquals(6, (float) $fresh->quantity_approved);
        $this->assertEquals(6, $fresh->effectiveQuantity());

        // Lebih dari yang diminta ditolak; sama dengan yang diminta berarti tidak ada pengurangan.
        $other = $this->request($this->storeA, ['quantity' => 4, 'item_name' => 'Lampu']);
        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('approve', $other, data: ['quantity_approved' => 5])
            ->assertHasTableActionErrors(['quantity_approved']);
        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('approve', $other->fresh(), data: ['quantity_approved' => 4])
            ->assertHasNoTableActionErrors();
        $this->assertNull($other->fresh()->quantity_approved);
    }

    public function test_shortfall_is_measured_against_the_approved_quantity(): void
    {
        $request = $this->request($this->storeA, ['quantity' => 10, 'quantity_approved' => 6, 'quantity_received' => 6, 'status' => 'fulfilled']);
        $short = $this->request($this->storeA, ['item_name' => 'Kurang', 'quantity' => 10, 'quantity_approved' => 6, 'quantity_received' => 4, 'status' => 'fulfilled']);

        $this->assertEquals(0, $request->fresh()->shortfall());
        $this->assertEquals(2, $short->fresh()->shortfall());

        $this->as($this->user('super_admin'));
        Livewire::test(ListPurchaseRequests::class)
            ->filterTable('short_received', true)
            ->assertCanSeeTableRecords([$short])
            ->assertCanNotSeeTableRecords([$request]);
    }

    public function test_requester_can_cancel_a_pending_request_but_not_someone_elses(): void
    {
        $mine = $this->writer($this->storeA);
        $request = $this->request($this->storeA, ['requested_by' => $mine->id]);
        $theirs = $this->request($this->storeA, ['item_name' => 'Punya orang lain', 'requested_by' => $this->writer($this->storeA)->id]);
        $approved = $this->request($this->storeA, ['item_name' => 'Sudah disetujui', 'status' => 'approved', 'requested_by' => $mine->id]);

        $this->as($mine);
        Livewire::test(ListPurchaseRequests::class)
            ->assertTableActionVisible('cancel', $request->fresh())
            ->assertTableActionHidden('cancel', $theirs->fresh())
            ->assertTableActionHidden('cancel', $approved->fresh())
            ->callTableAction('cancel', $request->fresh());

        $this->assertSame('cancelled', $request->fresh()->status);

        $this->as($this->user('super_admin'));
        Livewire::test(ListPurchaseRequests::class)->assertTableActionVisible('cancel', $theirs->fresh());
    }

    public function test_a_second_open_request_for_the_same_item_is_refused(): void
    {
        $material = $this->material('Slip Solution');
        $this->request($this->storeA, ['item_type' => 'raw_material', 'item_id' => $material->id, 'item_name' => 'Slip Solution', 'unit' => 'liter']);

        $this->as($this->writer($this->storeA));
        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['item_type' => 'raw_material', 'item_id' => $material->id, 'quantity' => 2])
            ->call('create')
            ->assertHasFormErrors(['item_id']);

        $this->assertSame(1, PurchaseRequest::withoutGlobalScopes()->count());
    }

    public function test_an_inactive_catalogue_item_cannot_be_requested(): void
    {
        $material = $this->material('Lama');
        $material->update(['is_active' => false]);

        $this->as($this->writer($this->storeA));
        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['item_type' => 'raw_material', 'item_id' => $material->id, 'quantity' => 2])
            ->call('create')
            ->assertHasFormErrors(['item_id']);

        $this->assertSame(0, PurchaseRequest::withoutGlobalScopes()->count());
    }

    public function test_request_detail_page_is_readable_and_hides_cost_without_the_view_grant(): void
    {
        $request = $this->request($this->storeA, ['status' => 'fulfilled', 'actual_cost' => 123000, 'quantity_received' => 1]);

        $this->as($this->writer($this->storeA));
        Livewire::test(\App\Filament\Resources\PurchaseRequestResource\Pages\ViewPurchaseRequest::class, ['record' => $request->getRouteKey()])
            ->assertSuccessful();

        $this->as($this->writer($this->storeA, ['create']));
        Livewire::test(ListPurchaseRequests::class)->assertTableColumnHidden('actual_cost');
        $this->as($this->writer($this->storeA));
        Livewire::test(ListPurchaseRequests::class)->assertTableColumnVisible('actual_cost');
    }

    // ------------------------------------------------------------- aset per unit

    public function test_fulfilling_an_asset_request_registers_one_asset_per_unit(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $request = $this->request($this->storeA, ['item_name' => 'Kompresor', 'quantity' => 3, 'status' => 'approved', 'requested_by' => $admin->id]);
        $supplier = Supplier::create(['name' => 'PT Mesin']);
        $account = ChartOfAccount::whereHas('parent', fn ($q) => $q->where('code', '1200'))->orderBy('code')->firstOrFail();

        $this->assertTrue(PurchaseRequestResource::canRegisterAsset($request));

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['quantity_received' => 3, 'register_asset' => true, 'actual_cost' => 3000000, 'supplier_id' => $supplier->id, 'chart_of_account_id' => $account->id])
            ->assertHasNoTableActionErrors();

        $assets = \App\Models\Asset::query()->where('notes', 'like', "%{$request->request_number}%")->orderBy('name')->get();
        $this->assertCount(3, $assets);
        $this->assertSame(['Kompresor #1', 'Kompresor #2', 'Kompresor #3'], $assets->pluck('name')->all());
        $this->assertEquals(1000000, (float) $assets->first()->purchase_cost);
        $this->assertSame($this->storeA->id, $assets->first()->store_id);
        $this->assertNotNull($request->fresh()->stock_recorded_at);
        $this->assertFalse(PurchaseRequestResource::canRegisterAsset($request->fresh()), 'Tidak bisa didaftarkan dua kali.');
    }

    public function test_fulfilling_an_asset_request_without_the_toggle_registers_nothing(): void
    {
        $admin = $this->as($this->user('super_admin'));
        $request = $this->request($this->storeA, ['quantity' => 2, 'status' => 'approved', 'requested_by' => $admin->id]);
        $supplier = Supplier::create(['name' => 'PT Mesin']);
        $account = ChartOfAccount::whereHas('parent', fn ($q) => $q->where('code', '1200'))->orderBy('code')->firstOrFail();

        Livewire::test(ListPurchaseRequests::class)
            ->callTableAction('fulfill', $request, data: ['quantity_received' => 2, 'register_asset' => false, 'actual_cost' => 2000000, 'supplier_id' => $supplier->id, 'chart_of_account_id' => $account->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, \App\Models\Asset::query()->count());
        $this->assertNull($request->fresh()->stock_recorded_at);
    }

    // ------------------------------------------------------------- pemberitahuan & pengingat

    public function test_new_requests_notify_approvers_and_pending_ones_are_reminded_daily(): void
    {
        $admin = $this->user('super_admin');
        $requester = $this->writer($this->storeA);

        $notifier = \Mockery::mock(\App\Services\StaffNotifier::class);
        $notifier->shouldReceive('toUsers')->once()->withArgs(fn ($ids, $title) => $ids->contains($admin->id) && ! $ids->contains($requester->id) && $title === 'Permohonan Pembelian Baru');
        $this->app->instance(\App\Services\StaffNotifier::class, $notifier);

        $request = $this->request($this->storeA, ['requested_by' => $requester->id]);
        app(\App\Services\PurchaseRequestNotifier::class)->notifyNew($request);

        // Pengingat: hanya yang menunggu lebih dari sehari, dan sekali per hari.
        $old = $this->request($this->storeA, ['item_name' => 'Lama', 'requested_by' => $requester->id]);
        PurchaseRequest::withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => now()->subDays(2)]);

        $reminder = \Mockery::mock(\App\Services\StaffNotifier::class);
        $reminder->shouldReceive('toUsers')->once()->withArgs(fn ($ids, $title) => $title === 'Permohonan Pembelian Menunggu');
        $this->app->instance(\App\Services\StaffNotifier::class, $reminder);

        $service = app(\App\Services\PurchaseRequestNotifier::class);
        $this->assertSame(1, $service->remindPending());
        $this->assertNotNull($old->fresh()->last_reminded_at);
        $this->assertSame(0, $service->remindPending(), 'Hari yang sama tidak diingatkan dua kali.');

        // Sehari kemudian permohonan baru tadi pun sudah lewat 1 hari: keduanya diingatkan lagi.
        Carbon::setTestNow(now()->addDay()->addHour());
        $again = \Mockery::mock(\App\Services\StaffNotifier::class);
        $again->shouldReceive('toUsers')->once();
        $this->app->instance(\App\Services\StaffNotifier::class, $again);
        $this->assertSame(2, app(\App\Services\PurchaseRequestNotifier::class)->remindPending());
    }

    // ------------------------------------------------------------- ekspor tanpa harga

    public function test_export_drops_the_cost_column_without_the_view_grant(): void
    {
        $this->request($this->storeA, ['item_name' => 'Barang A', 'actual_cost' => 1000, 'status' => 'fulfilled']);

        $this->as($this->writer($this->storeA, ['create']));
        $export = new PurchaseRequestExport(PurchaseRequestResource::canSeeCost());
        $this->assertNotContains('Biaya Aktual', $export->headings());
        $this->assertCount(8, $export->collection()->first());
    }
}
