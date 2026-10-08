<?php

namespace Tests\Feature;

use App\Filament\Resources\FinanceCategoryResource;
use App\Filament\Resources\FinanceCategoryResource\Pages\CreateFinanceCategory;
use App\Filament\Resources\FinanceCategoryResource\Pages\EditFinanceCategory;
use App\Filament\Resources\FinanceCategoryResource\Pages\ListFinanceCategories;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kategori Keuangan: akun Bagan Akun yang valid per tipe (aktif, bisa diposting, sesuai klasifikasi), kunci tipe &
 * akun setelah dipakai transaksi, hierarki 1 tingkat (grup / anak), penghapusan yang aman, serta layar admin
 * (form, keunikan nama per tipe, kolom, filter, aksi hapus, peringatan menonaktifkan, izin).
 */
class FinanceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function admin(): User
    {
        return tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x']), fn (User $u) => $u->assignRole('super_admin'));
    }

    /** Login sebagai admin supaya guard model (hanya aktif saat ada user login) berjalan seperti lewat UI. */
    private function asAdmin(): User
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function acct(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    private function category(array $overrides = []): FinanceCategory
    {
        return FinanceCategory::create(array_merge([
            'name' => 'Kategori ' . uniqid(), 'type' => 'out', 'chart_of_account_id' => $this->acct('6510'), 'is_active' => true,
        ], $overrides));
    }

    private function group(array $overrides = []): FinanceCategory
    {
        return FinanceCategory::create(array_merge(['name' => 'Grup ' . uniqid(), 'type' => 'out', 'is_group' => true, 'is_active' => true], $overrides));
    }

    private function useIn(FinanceCategory $category): FinanceTransaction
    {
        return FinanceTransaction::create(['type' => $category->type, 'finance_category_id' => $category->id, 'store_id' => $this->store->id, 'amount' => 1000, 'transaction_date' => now()->toDateString()]);
    }

    private function assertRefused(callable $action, string $fragment): void
    {
        try {
            $action();
            $this->fail('Seharusnya ditolak: ' . $fragment);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // ------------------------------------------------------------- akun

    public function test_account_validation_messages(): void
    {
        $inactive = ChartOfAccount::where('code', '6120')->firstOrFail();
        $inactive->update(['is_active' => false]);
        $header = ChartOfAccount::where('is_postable', false)->where('type', 'beban_operasional')->firstOrFail();

        $this->assertNull(FinanceCategory::validateAccount(null, 'out'));
        $this->assertNull(FinanceCategory::validateAccount($this->acct('6510'), 'out'));
        $this->assertNull(FinanceCategory::validateAccount($this->acct('8100'), 'out'), 'Pajak boleh untuk pengeluaran.');
        $this->assertNull(FinanceCategory::validateAccount($this->acct('7100'), 'in'), 'Pendapatan lain boleh untuk pemasukan.');
        $this->assertSame('Akun harus aktif dan bisa diposting.', FinanceCategory::validateAccount($inactive->id, 'out'));
        $this->assertSame('Akun harus aktif dan bisa diposting.', FinanceCategory::validateAccount($header->id, 'out'));
        $this->assertSame('Akun harus aktif dan bisa diposting.', FinanceCategory::validateAccount(999999, 'out'));
        $this->assertSame('Kategori pemasukan harus memakai akun pendapatan.', FinanceCategory::validateAccount($this->acct('6510'), 'in'));
        $this->assertSame('Kategori pengeluaran harus memakai akun beban atau pajak.', FinanceCategory::validateAccount($this->acct('4100'), 'out'));
        $this->assertSame('Kategori pengeluaran harus memakai akun beban atau pajak.', FinanceCategory::validateAccount($this->acct('1101'), 'out'));
    }

    public function test_the_model_enforces_a_valid_account_when_a_user_is_logged_in(): void
    {
        $this->asAdmin();

        $this->assertRefused(fn () => $this->category(['chart_of_account_id' => null]), 'wajib dihubungkan ke akun');
        $this->assertRefused(fn () => $this->category(['chart_of_account_id' => $this->acct('4100')]), 'harus memakai akun beban atau pajak');
        $this->assertRefused(fn () => $this->category(['type' => 'in', 'chart_of_account_id' => $this->acct('6510')]), 'harus memakai akun pendapatan');
        $this->assertSame(0, FinanceCategory::count());

        $this->assertSame('in', $this->category(['type' => 'in', 'chart_of_account_id' => $this->acct('4400')])->type);
    }

    public function test_seeders_and_jobs_without_a_logged_in_user_bypass_the_guards(): void
    {
        $legacy = FinanceCategory::create(['name' => 'Kategori Lama', 'type' => 'out', 'is_active' => true]);

        $this->assertNull($legacy->chart_of_account_id);
    }

    // ------------------------------------------------------------- kunci setelah dipakai

    public function test_type_and_account_are_locked_once_a_transaction_uses_the_category(): void
    {
        $this->asAdmin();
        $category = $this->category();
        $this->useIn($category);

        // Salinan baru tiap percobaan: model yang gagal disimpan tetap membawa atribut yang sudah diubah.
        $attempt = fn (array $attributes) => FinanceCategory::find($category->id)->update($attributes);

        $this->assertRefused(fn () => $attempt(['chart_of_account_id' => $this->acct('6110')]), 'tipe dan akunnya tidak boleh diubah');
        $this->assertRefused(fn () => $attempt(['type' => 'in', 'chart_of_account_id' => $this->acct('4400')]), 'tipe dan akunnya tidak boleh diubah');
        $this->assertRefused(fn () => $attempt(['is_group' => true, 'chart_of_account_id' => null]), 'sudah dipakai transaksi');

        $attempt(['name' => 'Nama Baru', 'sort_order' => 9, 'is_active' => false]);
        $this->assertSame('Nama Baru', $category->fresh()->name);
        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame($this->acct('6510'), $category->fresh()->chart_of_account_id);
    }

    public function test_an_unused_category_can_change_account_and_type(): void
    {
        $this->asAdmin();
        $category = $this->category();

        $category->update(['chart_of_account_id' => $this->acct('6110')]);
        $category->update(['type' => 'in', 'chart_of_account_id' => $this->acct('4400')]);

        $this->assertSame('in', $category->fresh()->type);
    }

    // ------------------------------------------------------------- hierarki

    public function test_hierarchy_rules(): void
    {
        $this->asAdmin();
        $group = $this->group();
        $otherTypeGroup = $this->group(['type' => 'in']);
        $plain = $this->category();

        $child = $this->category(['parent_id' => $group->id]);
        $this->assertSame($group->id, $child->parent_id);
        $this->assertSame([$child->id], $group->children()->pluck('id')->all());

        $this->assertRefused(fn () => $this->category(['parent_id' => $plain->id]), 'Induk harus berupa kategori grup');
        $this->assertRefused(fn () => $this->category(['parent_id' => $otherTypeGroup->id]), 'Induk harus bertipe sama');
        $this->assertRefused(fn () => $this->category(['parent_id' => 999999]), 'Induk harus berupa kategori grup');
        $this->assertRefused(fn () => $this->group(['parent_id' => $group->id]), 'hierarki maksimal 1 tingkat');
        $this->assertRefused(fn () => $this->group(['chart_of_account_id' => $this->acct('6510')]), 'Kategori grup tidak memakai akun');
    }

    public function test_a_group_with_children_cannot_become_plain_change_type_or_be_deleted(): void
    {
        $this->asAdmin();
        $group = $this->group();
        $this->category(['parent_id' => $group->id]);

        $again = fn () => FinanceCategory::find($group->id);

        $this->assertRefused(fn () => $again()->update(['is_group' => false, 'chart_of_account_id' => $this->acct('6510')]), 'masih punya kategori anak');
        $this->assertRefused(fn () => $again()->update(['type' => 'in']), 'tipenya tidak bisa diubah');
        $this->assertRefused(fn () => $again()->delete(), 'masih punya kategori anak');
        $this->assertNotNull(FinanceCategory::find($group->id));
        $this->assertTrue($group->hasChildren());

        $empty = $this->group();
        $empty->update(['type' => 'in']);
        $empty->delete();
        $this->assertNull(FinanceCategory::find($empty->id));
    }

    public function test_a_used_category_cannot_become_a_group(): void
    {
        $this->asAdmin();
        $category = $this->category();
        $this->useIn($category);

        $this->assertRefused(fn () => $category->update(['is_group' => true]), 'sudah dipakai transaksi');
    }

    public function test_the_database_blocks_deleting_a_category_that_has_transactions(): void
    {
        $category = $this->category();
        $this->useIn($category);

        $this->expectException(QueryException::class);

        $category->delete();
    }

    // ------------------------------------------------------------- izin

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $category = $this->category();

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue((bool) FinanceCategoryResource::canViewAny());
        $this->assertTrue((bool) FinanceCategoryResource::canCreate());
        $this->assertTrue((bool) FinanceCategoryResource::canEdit($category));
        $this->assertTrue((bool) FinanceCategoryResource::canDelete($category));

        $ticked = tap(User::create(['name' => 'Kasir A', 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => ['FinanceCategoryResource']]), fn ($u) => $u->assignRole('kasir'));
        $this->actingAs($ticked, 'web');
        $this->assertTrue((bool) FinanceCategoryResource::canViewAny());

        $unticked = tap(User::create(['name' => 'Kasir B', 'email' => uniqid() . '@test.local', 'password' => 'x', 'menu_access' => ['BookingResource']]), fn ($u) => $u->assignRole('kasir'));
        $this->actingAs($unticked, 'web');
        $this->assertFalse((bool) FinanceCategoryResource::canViewAny());
        $this->assertFalse((bool) FinanceCategoryResource::canCreate());
        $this->assertFalse((bool) FinanceCategoryResource::canEdit($category));
        $this->assertFalse((bool) FinanceCategoryResource::canDelete($category));
    }

    public function test_delete_is_denied_for_used_categories_and_groups_with_children(): void
    {
        $this->asAdmin();
        $used = $this->category();
        $this->useIn($used);
        $group = $this->group();
        $this->category(['parent_id' => $group->id]);

        $this->assertFalse(FinanceCategoryResource::canDelete($used));
        $this->assertFalse(FinanceCategoryResource::canDelete($group));
        $this->assertTrue(FinanceCategoryResource::canDelete($this->category()));
        $this->assertTrue(FinanceCategoryResource::canDelete($this->group()));
    }

    // ------------------------------------------------------------- form tambah

    public function test_creating_a_category_through_the_form(): void
    {
        $this->asAdmin();

        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => 'Sewa Toko', 'code' => 'OPS-01', 'type' => 'out', 'chart_of_account_id' => $this->acct('6510'), 'sort_order' => 5, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();

        $category = FinanceCategory::firstOrFail();
        $this->assertSame('Sewa Toko', $category->name);
        $this->assertSame('OPS-01', $category->code);
        $this->assertFalse($category->is_group);
    }

    public function test_the_form_requires_name_type_and_an_account_for_new_plain_categories(): void
    {
        $this->asAdmin();

        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => '', 'type' => 'out', 'chart_of_account_id' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'chart_of_account_id' => 'required']);
        $this->assertSame(0, FinanceCategory::count());
    }

    public function test_the_form_rejects_an_account_of_the_wrong_class(): void
    {
        $this->asAdmin();

        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => 'Salah Akun', 'type' => 'out', 'chart_of_account_id' => $this->acct('4100')])
            ->call('create')
            ->assertHasFormErrors(['chart_of_account_id']);
        $this->assertSame(0, FinanceCategory::count());
    }

    public function test_names_are_unique_per_type_and_codes_are_unique(): void
    {
        $this->asAdmin();
        $this->category(['name' => 'Listrik', 'code' => 'OPS-01']);

        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => 'Listrik', 'type' => 'out', 'chart_of_account_id' => $this->acct('6510')])
            ->call('create')->assertHasFormErrors(['name']);
        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => 'Lain', 'code' => 'OPS-01', 'type' => 'out', 'chart_of_account_id' => $this->acct('6510')])
            ->call('create')->assertHasFormErrors(['code']);
        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => 'Listrik', 'type' => 'in', 'chart_of_account_id' => $this->acct('4400')])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(2, FinanceCategory::where('name', 'Listrik')->count());
    }

    public function test_a_group_needs_no_account_and_the_default_order_follows_the_highest_existing(): void
    {
        $this->asAdmin();
        $this->category(['sort_order' => 7]);

        $page = Livewire::test(CreateFinanceCategory::class);
        $this->assertSame(8, (int) $page->get('data.sort_order'));

        $page->fillForm(['name' => 'Beban Toko', 'type' => 'out', 'is_group' => true])->call('create')->assertHasNoFormErrors();

        $group = FinanceCategory::where('name', 'Beban Toko')->firstOrFail();
        $this->assertTrue($group->is_group);
        $this->assertNull($group->chart_of_account_id);
    }

    public function test_a_child_can_be_filed_under_a_group_of_the_same_type(): void
    {
        $this->asAdmin();
        $group = $this->group(['name' => 'Beban Toko']);

        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => 'Sewa', 'type' => 'out', 'parent_id' => $group->id, 'chart_of_account_id' => $this->acct('6510')])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($group->id, FinanceCategory::where('name', 'Sewa')->value('parent_id'));
    }

    public function test_a_tampered_parent_of_another_type_is_refused_without_crashing(): void
    {
        $this->asAdmin();
        $incomeGroup = $this->group(['type' => 'in']);

        Livewire::test(CreateFinanceCategory::class)
            ->fillForm(['name' => 'Salah Induk', 'type' => 'out', 'parent_id' => $incomeGroup->id, 'chart_of_account_id' => $this->acct('6510')])
            ->call('create');

        $this->assertNull(FinanceCategory::where('name', 'Salah Induk')->first(), 'Model menolak, halaman tidak 500.');
    }

    // ------------------------------------------------------------- form ubah

    public function test_editing_the_name_and_sort_order_of_a_used_category(): void
    {
        $this->asAdmin();
        $category = $this->category(['name' => 'Lama', 'sort_order' => 1]);
        $this->useIn($category);

        Livewire::test(EditFinanceCategory::class, ['record' => $category->getKey()])
            ->fillForm(['name' => 'Baru', 'sort_order' => 3])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('Baru', $category->fresh()->name);
        $this->assertSame(3, $category->fresh()->sort_order);
    }

    public function test_tampering_with_the_locked_type_or_account_of_a_used_category_changes_nothing(): void
    {
        $this->asAdmin();
        $category = $this->category();
        $this->useIn($category);

        Livewire::test(EditFinanceCategory::class, ['record' => $category->getKey()])
            ->fillForm(['type' => 'in', 'chart_of_account_id' => $this->acct('4400')])
            ->call('save');

        $fresh = $category->fresh();
        $this->assertSame('out', $fresh->type);
        $this->assertSame($this->acct('6510'), $fresh->chart_of_account_id);
    }

    public function test_deactivating_a_used_category_saves_and_warns(): void
    {
        $this->asAdmin();
        $category = $this->category();
        $this->useIn($category);

        Livewire::test(EditFinanceCategory::class, ['record' => $category->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')->assertHasNoFormErrors();

        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame(1, $category->fresh()->transactions()->count(), 'Riwayat tetap utuh.');
    }

    public function test_a_legacy_category_without_an_account_can_be_fixed_but_not_saved_with_a_wrong_one(): void
    {
        $legacy = FinanceCategory::create(['name' => 'Kategori Lama', 'type' => 'out', 'is_active' => true]);
        $this->asAdmin();

        Livewire::test(EditFinanceCategory::class, ['record' => $legacy->getKey()])
            ->fillForm(['chart_of_account_id' => $this->acct('4100')])
            ->call('save')->assertHasFormErrors(['chart_of_account_id']);
        $this->assertNull($legacy->fresh()->chart_of_account_id);

        Livewire::test(EditFinanceCategory::class, ['record' => $legacy->getKey()])
            ->fillForm(['chart_of_account_id' => $this->acct('6510')])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($this->acct('6510'), $legacy->fresh()->chart_of_account_id);
    }

    // ------------------------------------------------------------- daftar

    public function test_list_columns_filters_and_search(): void
    {
        // Kategori lama tanpa akun hanya bisa ada lewat jalur tanpa user login (seeder/migrasi): buat sebelum login.
        $legacy = FinanceCategory::create(['name' => 'Tanpa Akun', 'type' => 'out', 'is_active' => true, 'sort_order' => 5]);
        $this->asAdmin();
        $expense = $this->category(['name' => 'Listrik Toko', 'sort_order' => 2]);
        $income = $this->category(['name' => 'Pendapatan X', 'type' => 'in', 'chart_of_account_id' => $this->acct('4400'), 'sort_order' => 1]);
        $inactive = $this->category(['name' => 'Nonaktif', 'is_active' => false, 'sort_order' => 3]);
        $group = $this->group(['name' => 'Grup Beban', 'sort_order' => 4]);
        $this->useIn($expense);
        $this->useIn($expense);

        Livewire::test(ListFinanceCategories::class)
            ->assertCanSeeTableRecords([$income, $expense, $inactive, $group, $legacy], inOrder: true)
            ->assertTableColumnStateSet('transactions_count', 2, $expense)
            ->assertTableColumnStateSet('account.display_name', '6510 — ' . ChartOfAccount::where('code', '6510')->value('name'), $expense)
            ->filterTable('type', 'in')->assertCanSeeTableRecords([$income])->assertCanNotSeeTableRecords([$expense, $group]);
        Livewire::test(ListFinanceCategories::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$expense]);
        Livewire::test(ListFinanceCategories::class)->filterTable('tanpa_akun', true)->assertCanSeeTableRecords([$legacy])->assertCanNotSeeTableRecords([$expense, $group]);
        Livewire::test(ListFinanceCategories::class)->searchTable('Listrik')->assertCanSeeTableRecords([$expense])->assertCanNotSeeTableRecords([$income]);
    }

    public function test_delete_action_visibility_and_effect(): void
    {
        $this->asAdmin();
        $used = $this->category();
        $this->useIn($used);
        $group = $this->group();
        $child = $this->category(['parent_id' => $group->id]);
        $free = $this->category(['name' => 'Bisa Dihapus']);

        Livewire::test(ListFinanceCategories::class)
            ->assertTableActionHidden('delete', $used)
            ->assertTableActionHidden('delete', $group)
            ->assertTableActionVisible('delete', $free)
            ->callTableAction('delete', $free);

        $this->assertNull(FinanceCategory::find($free->id));
        $this->assertNotNull(FinanceCategory::find($used->id));
        $this->assertNotNull(FinanceCategory::find($group->id));
        $this->assertSame($group->id, $child->fresh()->parent_id, 'Anak tetap di bawah grupnya.');
    }
}
