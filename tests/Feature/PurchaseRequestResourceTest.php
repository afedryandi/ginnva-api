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

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function request(Store $store, array $extra = []): PurchaseRequest
    {
        return PurchaseRequest::create(array_merge(['store_id' => $store->id, 'item_type' => 'asset', 'item_name' => 'Kompresor', 'quantity' => 1, 'status' => 'pending'], $extra));
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

        $this->as($this->user('kasir'));
        $this->assertTrue(PurchaseRequestResource::canViewAny());
        $this->assertTrue(PurchaseRequestResource::canCreate());
        $this->assertTrue(PurchaseRequestResource::canEdit($pending));
        $this->assertFalse(PurchaseRequestResource::canEdit(new PurchaseRequest(['status' => 'fulfilled'])));
        $this->assertFalse(PurchaseRequestResource::canDelete($pending));

        $this->as($this->user('kasir', null, null, ['menu_permissions' => ['PurchaseRequestResource' => ['delete']]]));
        $this->assertTrue(PurchaseRequestResource::canDelete($pending));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(PurchaseRequestResource::canViewAny());
        $this->assertFalse(PurchaseRequestResource::canCreate());
    }

    public function test_staff_only_see_their_own_store(): void
    {
        $mine = $this->request($this->storeA, ['item_name' => 'Punya A']);
        $theirs = $this->request($this->storeB, ['item_name' => 'Punya B']);

        $this->as($this->user('kasir', null, $this->storeA));
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

        $this->as($this->user('kasir', null, $this->storeA));
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
        $staff = $this->as($this->user('kasir', null, $this->storeA));
        $material = $this->material('Slip Solution');

        Livewire::test(CreatePurchaseRequest::class)
            ->fillForm(['store_id' => $this->storeB->id, 'item_type' => 'raw_material', 'item_id' => $material->id, 'quantity' => 3, 'reason' => 'Stok menipis'])
            ->call('create')
            ->assertHasNoFormErrors();

        $request = PurchaseRequest::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->storeA->id, $request->store_id, 'Staf tidak bisa mengajukan atas nama toko lain.');
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
        $this->as($this->user('kasir', null, $this->storeA));
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
        $staff = $this->as($this->user('kasir', null, $this->storeA));
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
        $request = $this->request($this->storeA, ['requested_by' => $this->user('kasir')->id]);

        $this->as($this->user('kasir', null, $this->storeA));
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
        $requester = $this->user('kasir', null, $this->storeA);
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

    public function test_only_the_requester_or_full_access_can_fulfill(): void
    {
        $requester = $this->user('kasir', null, $this->storeA);
        $request = $this->request($this->storeA, ['status' => 'approved', 'requested_by' => $requester->id]);

        $this->as($this->user('kasir', null, $this->storeA));
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

        $this->as($this->user('kasir', null, $this->storeA));
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

        $this->as($this->user('kasir', null, $this->storeA));
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
}
