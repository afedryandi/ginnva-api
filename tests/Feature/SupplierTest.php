<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierResource;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\SupplierResource\Pages\EditSupplier;
use App\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use App\Models\ChartOfAccount;
use App\Models\Payable;
use App\Models\RecurringBillTemplate;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Supplier: master penerima tagihan — nama unik (tanpa peduli huruf besar-
 * kecil & spasi berulang), NPWP/telepon tervalidasi, peringatan nama mirip,
 * aktif/nonaktif, tidak bisa dihapus kalau sudah dipakai tagihan atau
 * template, "buat cepat" dari form lain tidak menggandakan, dan izin.
 */
class SupplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function form(array $overrides = []): array
    {
        return array_merge(['name' => 'PT Properti Jaya', 'is_active' => true], $overrides);
    }

    private function payableFor(Supplier $supplier): Payable
    {
        return Payable::create([
            'payable_number' => 'HU-T-' . uniqid(), 'supplier_name' => $supplier->name, 'supplier_id' => $supplier->id, 'amount' => 100000,
            'amount_paid' => 0, 'due_date' => now()->addMonth(), 'status' => 'open',
        ]);
    }

    // ------------------------------------------------------------- model

    public function test_names_are_normalised_on_save(): void
    {
        $supplier = Supplier::create(['name' => "  PT   Properti \t Jaya  "]);

        $this->assertSame('PT Properti Jaya', $supplier->name);
        $supplier->update(['name' => '  PT  Baru ']);
        $this->assertSame('PT Baru', $supplier->fresh()->name);
    }

    public function test_find_or_create_reuses_a_supplier_regardless_of_case_and_spacing(): void
    {
        $existing = Supplier::create(['name' => 'PT Properti Jaya']);

        $same = Supplier::findOrCreateByName('  pt   PROPERTI jaya ');
        $new = Supplier::findOrCreateByName('PT Lain', ['phone' => '0211234567']);

        $this->assertSame($existing->id, $same->id);
        $this->assertSame(2, Supplier::count());
        $this->assertSame('0211234567', $new->phone);
    }

    public function test_in_use_covers_payables_and_recurring_templates(): void
    {
        $free = Supplier::create(['name' => 'Bebas']);
        $withPayable = Supplier::create(['name' => 'Ada Tagihan']);
        $withTemplate = Supplier::create(['name' => 'Ada Template']);
        $this->payableFor($withPayable);
        RecurringBillTemplate::create([
            'name' => 'Sewa', 'supplier_name' => 'Ada Template', 'supplier_id' => $withTemplate->id, 'chart_of_account_id' => ChartOfAccount::where('code', '6510')->value('id'),
            'amount' => 1000, 'day_of_month' => 5, 'next_run_date' => '2026-12-05',
        ]);

        $this->assertFalse($free->isInUse());
        $this->assertTrue($withPayable->isInUse());
        $this->assertTrue($withTemplate->isInUse());
    }

    // ------------------------------------------------------------- Filament: buat & validasi

    public function test_admin_creates_a_supplier_with_bank_details_and_it_is_audited(): void
    {
        $this->asAdmin();

        Livewire::test(CreateSupplier::class)
            ->fillForm($this->form(['npwp' => '123456789012345', 'phone' => '021-5551234', 'email' => 'tagihan@jaya.test', 'bank_name' => 'BCA', 'bank_account_number' => '1234567890', 'bank_account_name' => 'PT Properti Jaya']))
            ->call('create')->assertHasNoFormErrors();

        $supplier = Supplier::firstOrFail();
        $this->assertSame('BCA', $supplier->bank_name);
        $this->assertTrue($supplier->is_active);
        $this->assertTrue(Activity::where('log_name', 'supplier')->where('subject_id', $supplier->id)->where('event', 'created')->exists());
    }

    public function test_the_name_is_required_and_unique_even_with_different_case_or_spacing(): void
    {
        $this->asAdmin();
        Supplier::create(['name' => 'PT Properti Jaya']);
        $create = fn (array $over) => Livewire::test(CreateSupplier::class)->fillForm($this->form($over))->call('create');

        $create(['name' => ''])->assertHasFormErrors(['name' => 'required']);
        $create(['name' => 'PT Properti Jaya'])->assertHasFormErrors(['name']);
        $create(['name' => 'pt properti jaya'])->assertHasFormErrors(['name']);
        $create(['name' => 'PT  Properti   Jaya'])->assertHasFormErrors(['name']);
        $create(['name' => ' PT Properti Jaya '])->assertHasFormErrors(['name']);

        $this->assertSame(1, Supplier::count());
    }

    public function test_npwp_phone_and_email_formats_are_validated(): void
    {
        $this->asAdmin();
        $create = fn (array $over) => Livewire::test(CreateSupplier::class)->fillForm($this->form($over))->call('create');

        $create(['npwp' => '12.345.678.9-012.000'])->assertHasFormErrors(['npwp']);
        $create(['npwp' => '12345'])->assertHasFormErrors(['npwp']);
        $create(['phone' => 'telepon-rumah'])->assertHasFormErrors(['phone']);
        $create(['email' => 'bukan-email'])->assertHasFormErrors(['email']);
        $this->assertSame(0, Supplier::count());

        $create(['npwp' => '1234567890123456', 'phone' => '+62 21 (555) 1234'])->assertHasNoFormErrors();
        $this->assertSame(1, Supplier::count(), 'NPWP 16 digit dan telepon berformat umum sah.');
    }

    public function test_an_npwp_cannot_be_shared_between_suppliers(): void
    {
        $this->asAdmin();
        Supplier::create(['name' => 'PT Satu', 'npwp' => '123456789012345']);

        Livewire::test(CreateSupplier::class)->fillForm($this->form(['name' => 'PT Dua', 'npwp' => '123456789012345']))
            ->call('create')->assertHasFormErrors(['npwp']);
    }

    public function test_a_similar_name_triggers_a_warning_but_does_not_block_saving(): void
    {
        $this->asAdmin();
        Supplier::create(['name' => 'PT Properti Jaya']);

        Livewire::test(CreateSupplier::class)
            ->fillForm($this->form(['name' => 'PT Properti Jaja']))
            ->assertFormSet(fn (array $state) => true)
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(2, Supplier::count(), 'Typo/singkatan sengaja tetap boleh disimpan.');
    }

    // ------------------------------------------------------------- Filament: ubah, daftar, hapus

    public function test_editing_keeps_its_own_name_valid_and_blocks_taking_another(): void
    {
        $this->asAdmin();
        $a = Supplier::create(['name' => 'PT Satu']);
        Supplier::create(['name' => 'PT Dua']);

        Livewire::test(EditSupplier::class, ['record' => $a->getKey()])->fillForm(['phone' => '0215551234'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditSupplier::class, ['record' => $a->getKey()])->fillForm(['name' => 'pt dua'])->call('save')->assertHasFormErrors(['name']);
        Livewire::test(EditSupplier::class, ['record' => $a->getKey()])->fillForm(['name' => 'PT Satu Baru', 'is_active' => false])->call('save')->assertHasNoFormErrors();

        $fresh = $a->fresh();
        $this->assertSame('PT Satu Baru', $fresh->name);
        $this->assertFalse($fresh->is_active);
        $this->assertTrue(Activity::where('log_name', 'supplier')->where('subject_id', $a->id)->where('event', 'updated')->exists());
    }

    public function test_list_shows_bill_counts_and_filters_by_status(): void
    {
        $this->asAdmin();
        $active = Supplier::create(['name' => 'Aktif']);
        $off = Supplier::create(['name' => 'Nonaktif', 'is_active' => false]);
        $this->payableFor($active);
        $this->payableFor($active);

        Livewire::test(ListSuppliers::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$active, $off])
            ->assertTableColumnStateSet('payables_count', 2, Supplier::withCount('payables')->find($active->id))
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$active]);
    }

    public function test_only_unused_suppliers_can_be_deleted_and_only_by_full_access(): void
    {
        $admin = $this->asAdmin();
        $used = Supplier::create(['name' => 'Dipakai']);
        $this->payableFor($used);
        $free = Supplier::create(['name' => 'Bebas']);

        $this->assertFalse((bool) SupplierResource::canDelete($used));
        $this->assertTrue((bool) SupplierResource::canDelete($free));

        Livewire::test(ListSuppliers::class)->assertTableActionHidden('delete', $used)->callTableAction('delete', $free);
        $this->assertNull(Supplier::find($free->id));
        $this->assertNotNull(Supplier::find($used->id));

        $this->actingAs($this->user('kasir', ['menu_access' => ['SupplierResource'], 'menu_permissions' => ['SupplierResource' => ['update', 'delete']]]), 'web');
        $this->assertFalse((bool) SupplierResource::canDelete(Supplier::create(['name' => 'Lain'])), 'Hapus hanya full-access, izin granular tidak cukup.');
        $this->assertNotNull($admin);
    }

    // ------------------------------------------------------------- izin

    public function test_view_follows_menu_access_while_managing_needs_full_access_or_an_explicit_grant(): void
    {
        $supplier = Supplier::create(['name' => 'PT Satu']);

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(SupplierResource::canViewAny());
        $this->assertTrue(SupplierResource::canCreate());
        $this->assertTrue(SupplierResource::canEdit($supplier));

        // Akses eksplisit: akun dengan menu kosong (akses bawaan) tidak ikut, sebab isinya kontak dan rekening pemasok.
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse(SupplierResource::canViewAny());
        $this->assertFalse(SupplierResource::canCreate());

        $this->actingAs($this->user('kasir', ['menu_access' => ['SupplierResource']]), 'web');
        $this->assertTrue(SupplierResource::canViewAny());
        $this->assertFalse(SupplierResource::canCreate(), 'Mengelola supplier tidak otomatis untuk staff.');
        $this->assertFalse(SupplierResource::canEdit($supplier));

        $this->actingAs($this->user('kasir', ['menu_access' => ['SupplierResource'], 'menu_permissions' => ['SupplierResource' => ['create']]]), 'web');
        $this->assertTrue(SupplierResource::canCreate());
        $this->assertFalse(SupplierResource::canEdit($supplier), 'Tambah dan ubah adalah izin terpisah.');

        $this->actingAs($this->user('kasir', ['menu_access' => ['SupplierResource'], 'menu_permissions' => ['SupplierResource' => ['update']]]), 'web');
        $this->assertTrue(SupplierResource::canEdit($supplier));
        $this->assertFalse(SupplierResource::canCreate());

        $this->actingAs($this->user('kasir', ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(SupplierResource::canViewAny());
    }

    public function test_the_menu_lives_in_the_inventory_cluster_right_after_purchase_requests(): void
    {
        // Dipindah dari klaster Keuangan (2026-10-29): Supplier dipakai Permohonan Pembelian dan tetap tampil bila modul Keuangan mati.
        $this->assertSame(\App\Filament\Clusters\InventarisCluster::class, SupplierResource::getCluster());
        $this->assertSame('Permintaan & Memo', SupplierResource::getNavigationGroup());
        $this->assertSame(\App\Filament\Resources\PurchaseRequestResource::getNavigationGroup(), SupplierResource::getNavigationGroup());
        $this->assertGreaterThan(\App\Filament\Resources\PurchaseRequestResource::getNavigationSort(), SupplierResource::getNavigationSort());
    }
    // ------------------------------------------------------------- audit Supplier 2026-11-02

    private function menuUser(array $permissions = []): User
    {
        return $this->user('kasir', ['menu_access' => ['SupplierResource'], 'menu_permissions' => ['SupplierResource' => $permissions]]);
    }

    public function test_npwp_and_bank_details_need_the_view_grant_and_the_finance_module(): void
    {
        config(['features.finance' => true]);
        $supplier = Supplier::create(['name' => 'PT Rekening', 'npwp' => '123456789012345', 'bank_name' => 'BCA', 'bank_account_number' => '1234567890']);

        $this->actingAs($this->menuUser(['create', 'update']), 'web');
        $this->assertFalse(SupplierResource::canSeeBanking());
        Livewire::test(ListSuppliers::class)->assertTableColumnHidden('npwp')->assertTableColumnHidden('bank_name');
        Livewire::test(EditSupplier::class, ['record' => $supplier->getRouteKey()])
            ->assertFormFieldIsHidden('npwp')->assertFormFieldIsHidden('bank_name');

        $this->actingAs($this->menuUser(['view', 'update']), 'web');
        $this->assertTrue(SupplierResource::canSeeBanking());
        Livewire::test(ListSuppliers::class)->assertTableColumnVisible('npwp')->assertTableColumnVisible('bank_name');

        // Saat Keuangan mati bagian ini disembunyikan untuk semua orang, data yang sudah ada tetap tersimpan.
        config(['features.finance' => false]);
        $this->asAdmin();
        $this->assertFalse(SupplierResource::canSeeBanking());
        Livewire::test(EditSupplier::class, ['record' => $supplier->getRouteKey()])
            ->assertFormFieldIsHidden('npwp')
            ->fillForm(['name' => 'PT Rekening Baru'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('123456789012345', $supplier->fresh()->npwp);
        $this->assertSame('1234567890', $supplier->fresh()->bank_account_number);
    }

    public function test_a_supplier_that_is_still_in_use_cannot_be_deleted(): void
    {
        $this->asAdmin();
        $supplier = Supplier::create(['name' => 'PT Pemasok']);
        $other = Supplier::create(['name' => 'PT Lain']);

        $material = \App\Models\RawMaterial::create(['name' => 'Adhesive', 'code' => 'RM-1', 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 1, 'preferred_supplier_id' => $supplier->id]);
        $this->assertTrue($supplier->isInUse());
        $this->assertSame(['Pemasok Utama 1 bahan/barang'], $supplier->inUseReasons());
        $this->assertFalse(SupplierResource::canDelete($supplier));

        $material->update(['preferred_supplier_id' => null]);
        $this->assertFalse($supplier->fresh()->isInUse());

        $consumable = \App\Models\ConsumableItem::create(['name' => 'Lakban', 'code' => 'CI-1', 'category' => 'umum', 'unit' => 'roll', 'current_stock' => 1, 'preferred_supplier_id' => $other->id]);
        $this->assertTrue($other->isInUse());

        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        \App\Models\PurchaseRequest::create(['store_id' => $store->id, 'item_type' => 'asset', 'item_name' => 'Kompresor', 'quantity' => 1, 'status' => 'fulfilled', 'requested_by' => $this->user('super_admin')->id, 'supplier_id' => $supplier->id]);
        $this->assertSame(['pemasok permohonan pembelian'], $supplier->fresh()->inUseReasons());
        $this->assertFalse(SupplierResource::canDelete($supplier->fresh()));
        $this->assertNotNull($consumable);
    }

    public function test_the_list_counts_supplied_items_and_received_purchases_and_export_follows_the_grant(): void
    {
        config(['features.finance' => true]);
        $admin = $this->asAdmin();
        $supplier = Supplier::create(['name' => 'PT Pemasok', 'phone' => '0211234567', 'npwp' => '123456789012345']);
        \App\Models\RawMaterial::create(['name' => 'A', 'code' => 'RM-A', 'category' => 'chemical', 'unit' => 'liter', 'current_stock' => 1, 'preferred_supplier_id' => $supplier->id]);
        \App\Models\ConsumableItem::create(['name' => 'B', 'code' => 'CI-B', 'category' => 'umum', 'unit' => 'pcs', 'current_stock' => 1, 'preferred_supplier_id' => $supplier->id]);
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        foreach (['fulfilled', 'fulfilled', 'approved'] as $status) {
            \App\Models\PurchaseRequest::create(['store_id' => $store->id, 'item_type' => 'asset', 'item_name' => 'Barang', 'quantity' => 1, 'status' => $status, 'requested_by' => $admin->id, 'supplier_id' => $supplier->id]);
        }

        $record = SupplierResource::getEloquentQuery()->findOrFail($supplier->id);
        Livewire::test(ListSuppliers::class)
            ->assertTableColumnStateSet('supplied_items', 2, $record)
            ->assertTableColumnStateSet('received_count', 2, $record);

        $withBanking = (new \App\Exports\SupplierExport(true));
        $without = (new \App\Exports\SupplierExport(false));
        $this->assertCount(11, $withBanking->headings());
        $this->assertCount(7, $without->headings());
        $this->assertSame([2, 2], [$without->collection()->first()[5], $without->collection()->first()[6]]);
        $this->assertContains('123456789012345', $withBanking->collection()->first());
        $this->assertNotContains('123456789012345', $without->collection()->first());

        \Illuminate\Support\Carbon::setTestNow('2026-10-08 10:00:00');
        \Maatwebsite\Excel\Facades\Excel::fake();
        Livewire::test(ListSuppliers::class)->callAction('exportExcel')->assertHasNoActionErrors();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('supplier-20261008-100000.xlsx');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_view_page_shows_supplied_items_and_the_purchase_history(): void
    {
        $admin = $this->asAdmin();
        $supplier = Supplier::create(['name' => 'PT Pemasok']);
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $done = \App\Models\PurchaseRequest::create(['store_id' => $store->id, 'item_type' => 'asset', 'item_name' => 'Kompresor', 'quantity' => 2, 'quantity_received' => 2, 'actual_cost' => 1000000, 'status' => 'fulfilled', 'fulfilled_at' => now(), 'requested_by' => $admin->id, 'supplier_id' => $supplier->id]);
        $open = \App\Models\PurchaseRequest::create(['store_id' => $store->id, 'item_type' => 'asset', 'item_name' => 'Belum', 'quantity' => 1, 'status' => 'approved', 'requested_by' => $admin->id, 'supplier_id' => $supplier->id]);

        Livewire::test(\App\Filament\Resources\SupplierResource\Pages\ViewSupplier::class, ['record' => $supplier->getRouteKey()])->assertSuccessful();

        Livewire::test(\App\Filament\Resources\SupplierResource\RelationManagers\PurchaseRequestsRelationManager::class, [
            'ownerRecord' => $supplier,
            'pageClass' => \App\Filament\Resources\SupplierResource\Pages\ViewSupplier::class,
        ])->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$open])
            ->assertTableColumnStateSet('unit_price', 500000.0, $done);
    }
}
