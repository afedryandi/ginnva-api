<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerGroupResource;
use App\Filament\Resources\CustomerGroupResource\Pages\CreateCustomerGroup;
use App\Filament\Resources\CustomerGroupResource\Pages\EditCustomerGroup;
use App\Filament\Resources\CustomerGroupResource\Pages\ListCustomerGroups;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\FilmProduct;
use App\Models\FilmProductGroupPrice;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Grup Pelanggan: hak akses (hapus diblokir selagi masih dipakai pelanggan), daftar urut + jumlah pelanggan/harga khusus,
 * tambah / ubah (nama unik tanpa spasi tepi, urutan tidak negatif), hapus satuan dan massal (yang masih dipakai dilewati,
 * harga khusus ikut terhapus) serta jejak aktivitas.
 */
class CustomerGroupResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CustomerGroup::query()->delete();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function group(string $name, array $extra = []): CustomerGroup
    {
        return CustomerGroup::create(array_merge(['name' => $name, 'is_active' => true, 'sort_order' => 0], $extra));
    }

    private function member(CustomerGroup $group): Customer
    {
        return Customer::create(['name' => 'Budi ' . uniqid(), 'phone_number' => '0812' . random_int(10000000, 99999999), 'customer_group_id' => $group->id]);
    }

    private function price(CustomerGroup $group, string $sku = 'PPF-GRP-1'): FilmProductGroupPrice
    {
        $product = FilmProduct::create(['sku' => $sku, 'name' => 'PPF ' . $sku, 'product_type' => 'ppf', 'base_price' => 1000000]);

        return $product->groupPrices()->create(['customer_group_id' => $group->id, 'price' => 800000]);
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules_and_delete_is_blocked_while_the_group_is_in_use(): void
    {
        $free = $this->group('Kosong');
        $used = $this->group('Terpakai');
        $this->member($used);

        $this->as($this->user('super_admin'));
        $this->assertTrue(CustomerGroupResource::canViewAny());
        $this->assertTrue(CustomerGroupResource::canCreate());
        $this->assertTrue(CustomerGroupResource::canEdit($free));
        $this->assertTrue(CustomerGroupResource::canDelete($free));
        $this->assertFalse(CustomerGroupResource::canDelete($used));
        $this->assertTrue(CustomerGroupResource::canDeleteAny());

        $this->as($this->user('kasir'));
        $this->assertTrue(CustomerGroupResource::canViewAny());
        $this->assertTrue(CustomerGroupResource::canCreate());
        $this->assertTrue(CustomerGroupResource::canDelete($free));
        $this->assertFalse(CustomerGroupResource::canDelete($used));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(CustomerGroupResource::canViewAny());
        $this->assertFalse(CustomerGroupResource::canCreate());
        $this->assertFalse(CustomerGroupResource::canEdit($free));
        $this->assertFalse(CustomerGroupResource::canDeleteAny());
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_ordered_and_shows_usage_counts(): void
    {
        $second = $this->group('Reseller', ['sort_order' => 2]);
        $first = $this->group('Member', ['sort_order' => 1, 'description' => 'Pelanggan tetap']);
        $this->member($first);
        $this->member($first);
        $this->price($first);
        $this->price($first, 'PPF-GRP-2');

        $this->as($this->user('kasir'));
        $withCounts = CustomerGroup::withCount(['customers', 'groupPrices'])->findOrFail($first->id);

        Livewire::test(ListCustomerGroups::class)
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->assertTableColumnStateSet('customers_count', 2, record: $withCounts)
            ->assertTableColumnStateSet('group_prices_count', 2, record: $withCounts)
            ->assertTableColumnStateSet('description', 'Pelanggan tetap', record: $first);
    }

    public function test_search_and_active_filter(): void
    {
        $active = $this->group('Member');
        $inactive = $this->group('Lama', ['is_active' => false]);

        $this->as($this->user('kasir'));
        Livewire::test(ListCustomerGroups::class)->searchTable('Lama')->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active]);
        Livewire::test(ListCustomerGroups::class)->filterTable('is_active', true)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive]);
        Livewire::test(ListCustomerGroups::class)->filterTable('is_active', false)->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active]);
    }

    // ------------------------------------------------------------- tambah & ubah

    public function test_create_a_group_and_log_it(): void
    {
        $user = $this->as($this->user('kasir'));

        Livewire::test(CreateCustomerGroup::class)
            ->fillForm(['name' => 'Korporat', 'description' => 'Pelanggan perusahaan', 'sort_order' => 3, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = CustomerGroup::where('name', 'Korporat')->firstOrFail();
        $this->assertSame('Pelanggan perusahaan', $group->description);
        $this->assertSame(3, $group->sort_order);
        $this->assertTrue($group->is_active);
        $this->assertSame($user->id, Activity::where('log_name', 'customer_group')->latest('id')->firstOrFail()->causer_id);
    }

    public function test_create_validation(): void
    {
        $this->group('Member');
        $this->as($this->user('kasir'));

        Livewire::test(CreateCustomerGroup::class)->fillForm(['name' => ''])->call('create')->assertHasFormErrors(['name' => 'required']);
        Livewire::test(CreateCustomerGroup::class)->fillForm(['name' => 'Member'])->call('create')->assertHasFormErrors(['name' => 'unique']);
        Livewire::test(CreateCustomerGroup::class)->fillForm(['name' => '  Member  '])->call('create')->assertHasFormErrors(['name']);
        Livewire::test(CreateCustomerGroup::class)->fillForm(['name' => str_repeat('a', 101)])->call('create')->assertHasFormErrors(['name' => 'max']);
        Livewire::test(CreateCustomerGroup::class)->fillForm(['name' => 'Baru', 'sort_order' => -1])->call('create')->assertHasFormErrors(['sort_order']);

        $this->assertSame(1, CustomerGroup::count());

        Livewire::test(CreateCustomerGroup::class)->fillForm(['name' => '  Baru  '])->call('create')->assertHasNoFormErrors();
        $this->assertSame('Baru', CustomerGroup::latest('id')->firstOrFail()->name, 'Spasi tepi dibuang saat disimpan.');
    }

    public function test_edit_keeps_its_own_name_and_can_deactivate_a_used_group(): void
    {
        $group = $this->group('Member');
        $customer = $this->member($group);
        $this->group('Reseller');
        $this->as($this->user('kasir'));

        Livewire::test(EditCustomerGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm(['name' => 'Member', 'description' => 'Diubah', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $group->fresh();
        $this->assertSame('Diubah', $fresh->description);
        $this->assertFalse($fresh->is_active);
        $this->assertSame($group->id, $customer->fresh()->customer_group_id, 'Menonaktifkan tidak mengubah pelanggan yang memakainya.');

        Livewire::test(EditCustomerGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm(['name' => 'Reseller'])
            ->call('save')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    // ------------------------------------------------------------- hapus

    public function test_deleting_an_unused_group_removes_its_special_prices(): void
    {
        $group = $this->group('Kosong');
        $this->price($group);
        $this->as($this->user('kasir'));

        Livewire::test(ListCustomerGroups::class)
            ->assertTableActionVisible('delete', $group)
            ->callTableAction('delete', $group);

        $this->assertNull(CustomerGroup::find($group->id));
        $this->assertSame(0, FilmProductGroupPrice::where('customer_group_id', $group->id)->count());
    }

    public function test_a_group_in_use_cannot_be_deleted_from_the_row_or_the_edit_page(): void
    {
        $used = $this->group('Terpakai');
        $this->member($used);
        $this->as($this->user('kasir'));

        Livewire::test(ListCustomerGroups::class)->assertTableActionHidden('delete', $used);
        Livewire::test(EditCustomerGroup::class, ['record' => $used->getRouteKey()])->assertActionHidden('delete');

        $this->assertNotNull(CustomerGroup::find($used->id));
    }

    public function test_bulk_delete_skips_groups_still_in_use(): void
    {
        $free = $this->group('Kosong');
        $used = $this->group('Terpakai');
        $customer = $this->member($used);
        $this->as($this->user('kasir'));

        Livewire::test(ListCustomerGroups::class)
            ->callTableBulkAction('delete', [$free, $used])
            ->assertNotified('1 grup dihapus, 1 tidak bisa dihapus');

        $this->assertNull(CustomerGroup::find($free->id));
        $this->assertNotNull(CustomerGroup::find($used->id));
        $this->assertSame($used->id, $customer->fresh()->customer_group_id);

        Livewire::test(ListCustomerGroups::class)
            ->callTableBulkAction('delete', [$used])
            ->assertNotified('Tidak ada grup yang bisa dihapus');
        $this->assertNotNull(CustomerGroup::find($used->id));
    }

    public function test_bulk_delete_of_only_free_groups_confirms_the_count(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $this->as($this->user('kasir'));

        Livewire::test(ListCustomerGroups::class)
            ->callTableBulkAction('delete', [$a, $b])
            ->assertNotified('2 grup dihapus');

        $this->assertSame(0, CustomerGroup::count());
    }
}
