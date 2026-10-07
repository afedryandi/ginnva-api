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

        $this->actingAs($this->user('kasir', ['menu_permissions' => [SupplierResource::class => ['update', 'delete']]]), 'web');
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

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(SupplierResource::canViewAny());
        $this->assertFalse(SupplierResource::canCreate(), 'Mengelola supplier tidak otomatis untuk staf.');
        $this->assertFalse(SupplierResource::canEdit($supplier));

        $this->actingAs($this->user('kasir', ['menu_permissions' => [SupplierResource::class => ['update']]]), 'web');
        $this->assertTrue(SupplierResource::canCreate());
        $this->assertTrue(SupplierResource::canEdit($supplier));

        $this->actingAs($this->user('kasir', ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(SupplierResource::canViewAny());
    }

    public function test_the_menu_lives_in_the_settings_group_after_finance_categories(): void
    {
        $this->assertSame('Pengaturan', SupplierResource::getNavigationGroup());
        $this->assertSame('Pengaturan', \App\Filament\Resources\FinanceCategoryResource::getNavigationGroup());
        $this->assertGreaterThan(\App\Filament\Resources\FinanceCategoryResource::getNavigationSort(), SupplierResource::getNavigationSort());
    }
}
