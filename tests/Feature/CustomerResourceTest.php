<?php

namespace Tests\Feature;

use App\Exports\CustomerExport;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Partner;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Daftar Pelanggan: akun dibuat lewat app (bukan admin), daftar + pencarian + filter status akun (akun yang dihapus
 * anonim tetap bisa dilihat lewat filter), aksi Data Pribadi / Set Referral / Atur Grup hanya untuk yang berhak ubah,
 * hapus (soft delete), halaman lihat dan ekspor (hanya full-access, tercatat). "Hari ini" dibekukan di 8 Oktober 2026.
 */
class CustomerResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function customer(string $name, array $extra = []): Customer
    {
        return Customer::create(array_merge(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@test.local', 'phone_number' => '0812' . random_int(10000000, 99999999)], $extra));
    }

    private function deletedCustomer(): Customer
    {
        $customer = $this->customer('Akan Dihapus');
        $customer->update(['name' => null, 'email' => null, 'phone_number' => null]);
        $customer->delete();

        return $customer;
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules(): void
    {
        $record = new Customer();

        $this->as($this->user('super_admin'));
        $this->assertTrue(CustomerResource::canViewAny());
        $this->assertTrue(CustomerResource::canView($record));
        $this->assertTrue(CustomerResource::canEdit($record));
        $this->assertTrue(CustomerResource::canDelete($record));
        $this->assertFalse(CustomerResource::canCreate(), 'Akun dibuat pelanggan lewat app, bukan admin.');

        $this->as($this->user('kasir'));
        $this->assertTrue(CustomerResource::canViewAny());
        $this->assertTrue(CustomerResource::canView($record));
        $this->assertTrue(CustomerResource::canEdit($record));
        $this->assertFalse(CustomerResource::canDelete($record));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(CustomerResource::canViewAny());
        $this->assertFalse(CustomerResource::canView($record));
    }

    // ------------------------------------------------------------- daftar & filter

    public function test_list_defaults_to_active_accounts_and_the_filter_reveals_deleted_ones(): void
    {
        $active = $this->customer('Budi Santoso');
        $deleted = $this->deletedCustomer();

        $this->as($this->user('super_admin'));
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$deleted]);

        Livewire::test(ListCustomers::class)
            ->filterTable('deleted_at', true)
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$active])
            ->assertTableColumnFormattedStateSet('name', '(Akun Dihapus)', record: $deleted)
            ->assertTableActionHidden('delete', $deleted)
            ->assertTableActionHidden('personalData', $deleted)
            ->assertTableActionHidden('setReferral', $deleted)
            ->assertTableActionVisible('view', $deleted);
    }

    public function test_search_and_columns(): void
    {
        $budi = $this->customer('Budi Santoso', ['email' => 'budi@test.local', 'phone_number' => '081211112222', 'gender' => 'male', 'address' => 'Jl. Merdeka 1']);
        $siti = $this->customer('Siti Aminah', ['email' => 'siti@test.local', 'phone_number' => '081233334444']);
        $this->customer('Teman Budi', ['referred_by_customer_id' => $budi->id]);
        $this->customer('Teman Budi Dua', ['referred_by_customer_id' => $budi->id]);

        $this->as($this->user('super_admin'));
        foreach (['Santoso', 'siti@test.local', '081211112222', $budi->referral_code, 'Merdeka'] as $term) {
            $expected = str_contains($term, 'siti') || $term === 'Siti' ? $siti : $budi;
            $other = $expected->is($budi) ? $siti : $budi;
            Livewire::test(ListCustomers::class)->searchTable($term)->assertCanSeeTableRecords([$expected])->assertCanNotSeeTableRecords([$other]);
        }

        $withCounts = CustomerResource::getEloquentQuery()->withCount(['referrals', 'bookings', 'warranties'])->findOrFail($budi->id);
        Livewire::test(ListCustomers::class)
            ->assertTableColumnFormattedStateSet('gender', 'Laki-Laki', record: $budi)
            ->assertTableColumnFormattedStateSet('gender', '—', record: $siti)
            ->assertTableColumnStateSet('referrals_count', 2, record: $withCounts)
            ->assertTableColumnStateSet('bookings_count', 0, record: $withCounts);
    }

    // ------------------------------------------------------------- Data Pribadi

    public function test_personal_data_is_saved_validated_and_logged(): void
    {
        $customer = $this->customer('Budi Santoso');
        $admin = $this->as($this->user('super_admin'));

        Livewire::test(ListCustomers::class)
            ->callTableAction('personalData', $customer, data: ['gender' => 'female', 'address' => 'Jl. Baru 5'])
            ->assertHasNoTableActionErrors();

        $fresh = $customer->fresh();
        $this->assertSame('female', $fresh->gender);
        $this->assertSame('Jl. Baru 5', $fresh->address);
        $log = Activity::where('log_name', 'customer')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);

        Livewire::test(ListCustomers::class)
            ->callTableAction('personalData', $customer, data: ['gender' => 'robot', 'address' => 'x'])
            ->assertHasTableActionErrors(['gender']);
        Livewire::test(ListCustomers::class)
            ->callTableAction('personalData', $customer, data: ['gender' => null, 'address' => str_repeat('a', 501)])
            ->assertHasTableActionErrors(['address']);
        $this->assertSame('female', $customer->fresh()->gender);

        Livewire::test(ListCustomers::class)
            ->callTableAction('personalData', $customer, data: ['gender' => null, 'address' => null])
            ->assertHasNoTableActionErrors();
        $this->assertNull($customer->fresh()->gender);
    }

    public function test_staff_with_menu_access_can_edit_but_not_delete(): void
    {
        $customer = $this->customer('Budi Santoso');

        $this->as($this->user('kasir'));
        Livewire::test(ListCustomers::class)
            ->assertTableActionVisible('view', $customer)
            ->assertTableActionVisible('personalData', $customer)
            ->assertTableActionVisible('setReferral', $customer)
            ->assertTableActionHidden('delete', $customer)
            ->assertTableBulkActionVisible('setCustomerGroup');

        $this->as($this->user('kasir', null, ['menu_permissions' => ['CustomerResource' => ['delete']]]));
        Livewire::test(ListCustomers::class)->assertTableActionVisible('delete', $customer);
    }

    // ------------------------------------------------------------- Set Referral

    public function test_set_referral_to_a_customer_or_a_partner(): void
    {
        $referrer = $this->customer('Pengajak');
        $friend = $this->customer('Teman');
        $partner = Partner::createAccount(['business_name' => 'Mitra Bengkel', 'email' => 'mitra@example.com', 'password' => 'rahasia123']);
        $this->as($this->user('super_admin'));

        Livewire::test(ListCustomers::class)
            ->callTableAction('setReferral', $friend, data: ['referred_by_customer_id' => $referrer->id, 'referred_by_partner_id' => null])
            ->assertHasNoTableActionErrors();
        $this->assertSame($referrer->id, $friend->fresh()->referred_by_customer_id);
        $this->assertNull($friend->fresh()->referred_by_partner_id);

        Livewire::test(ListCustomers::class)
            ->callTableAction('setReferral', $friend, data: ['referred_by_customer_id' => null, 'referred_by_partner_id' => $partner->id])
            ->assertHasNoTableActionErrors();
        $this->assertNull($friend->fresh()->referred_by_customer_id);
        $this->assertSame($partner->id, $friend->fresh()->referred_by_partner_id);
    }

    public function test_only_one_referral_source_survives_when_both_are_sent(): void
    {
        $referrer = $this->customer('Pengajak');
        $friend = $this->customer('Teman');
        $partner = Partner::createAccount(['business_name' => 'Mitra Bengkel', 'email' => 'mitra@example.com', 'password' => 'rahasia123']);
        $this->as($this->user('super_admin'));

        $friend->update(['referred_by_partner_id' => $partner->id]);

        Livewire::test(ListCustomers::class)
            ->callTableAction('setReferral', $friend, data: ['referred_by_customer_id' => $referrer->id, 'referred_by_partner_id' => $partner->id]);

        $fresh = $friend->fresh();
        $this->assertSame($referrer->id, $fresh->referred_by_customer_id, 'Yang baru diisi dimenangkan.');
        $this->assertNull($fresh->referred_by_partner_id);
    }

    public function test_a_customer_cannot_refer_themselves(): void
    {
        $customer = $this->customer('Budi Santoso');
        $this->as($this->user('super_admin'));

        Livewire::test(ListCustomers::class)
            ->callTableAction('setReferral', $customer, data: ['referred_by_customer_id' => $customer->id, 'referred_by_partner_id' => null])
            ->assertNotified('Pelanggan tidak bisa mereferensikan dirinya sendiri.');

        $this->assertNull($customer->fresh()->referred_by_customer_id);
    }

    // ------------------------------------------------------------- Atur Grup

    public function test_bulk_group_assignment(): void
    {
        $group = CustomerGroup::create(['name' => 'VIP', 'is_active' => true, 'sort_order' => 1]);
        $inactive = CustomerGroup::create(['name' => 'Lama', 'is_active' => false, 'sort_order' => 2]);
        $a = $this->customer('Budi Santoso');
        $b = $this->customer('Siti Aminah');
        $c = $this->customer('Tidak Dipilih');
        $this->as($this->user('kasir'));

        Livewire::test(ListCustomers::class)
            ->callTableBulkAction('setCustomerGroup', [$a, $b], data: ['customer_group_id' => $group->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($group->id, $a->fresh()->customer_group_id);
        $this->assertSame($group->id, $b->fresh()->customer_group_id);
        $this->assertNull($c->fresh()->customer_group_id);

        Livewire::test(ListCustomers::class)
            ->callTableBulkAction('setCustomerGroup', [$a], data: ['customer_group_id' => $inactive->id])
            ->assertHasTableBulkActionErrors(['customer_group_id']);
        $this->assertSame($group->id, $a->fresh()->customer_group_id, 'Grup nonaktif ditolak.');

        Livewire::test(ListCustomers::class)
            ->callTableBulkAction('setCustomerGroup', [$a, $b], data: ['customer_group_id' => null])
            ->assertHasNoTableBulkActionErrors();
        $this->assertNull($a->fresh()->customer_group_id);
        $this->assertNull($b->fresh()->customer_group_id);
    }

    // ------------------------------------------------------------- hapus

    public function test_admin_delete_is_a_soft_delete_and_the_row_stays_reachable(): void
    {
        $customer = $this->customer('Budi Santoso');
        $this->as($this->user('super_admin'));

        Livewire::test(ListCustomers::class)
            ->assertTableActionVisible('delete', $customer)
            ->callTableAction('delete', $customer);

        $this->assertNull(Customer::find($customer->id));
        $this->assertNotNull(Customer::withTrashed()->find($customer->id)->deleted_at);

        Livewire::test(ListCustomers::class)
            ->filterTable('deleted_at', true)
            ->assertCanSeeTableRecords([Customer::withTrashed()->find($customer->id)]);
    }

    // ------------------------------------------------------------- halaman lihat

    public function test_the_view_page_shows_the_account_and_the_referral_action(): void
    {
        $referrer = $this->customer('Pengajak');
        $customer = $this->customer('Budi Santoso', ['gender' => 'male', 'address' => 'Jl. Merdeka 1', 'referred_by_customer_id' => $referrer->id]);

        $this->as($this->user('kasir'));
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet(['name' => 'Budi Santoso', 'email' => 'budi.santoso@test.local'])
            ->assertSee('Laki-Laki')
            ->assertSee('Jl. Merdeka 1')
            ->assertSee($referrer->referral_code)
            ->assertActionVisible('setReferral');
    }

    public function test_a_deleted_account_can_still_be_opened_without_edit_actions(): void
    {
        $deleted = $this->deletedCustomer();

        $this->as($this->user('super_admin'));
        Livewire::test(ViewCustomer::class, ['record' => $deleted->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('setReferral');
    }

    // ------------------------------------------------------------- ekspor

    public function test_export_is_for_full_access_only_and_is_logged(): void
    {
        $this->customer('Budi Santoso', ['gender' => 'male', 'address' => 'Jl. Merdeka 1']);
        $this->deletedCustomer();

        $this->as($this->user('kasir'));
        Livewire::test(ListCustomers::class)->assertTableActionHidden('export');

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        Livewire::test(ListCustomers::class)->callTableAction('export')->assertHasNoTableActionErrors();
        Excel::assertDownloaded('customers-20261008.xlsx');

        $log = Activity::where('log_name', 'report_export')->latest('id')->firstOrFail();
        $this->assertSame('customer', $log->properties['report']);
        $this->assertSame($admin->id, $log->causer_id);

        $export = new CustomerExport();
        $rows = $export->query()->get();
        $this->assertCount(1, $rows, 'Akun yang dihapus tidak ikut diekspor.');
        $this->assertSame(['Budi Santoso', 'budi.santoso@test.local', $rows->first()->phone_number, 'Laki-Laki', 'Jl. Merdeka 1', 'Belum', 0, 0, '08/10/2026 10:00'], $export->map($rows->first()));
    }
}
