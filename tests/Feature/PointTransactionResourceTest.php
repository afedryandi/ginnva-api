<?php

namespace Tests\Feature;

use App\Filament\Resources\PointTransactionResource;
use App\Filament\Resources\PointTransactionResource\Pages\CreatePointTransaction;
use App\Filament\Resources\PointTransactionResource\Pages\ListPointTransactions;
use App\Filament\Resources\PointTransactionResource\Pages\ViewPointTransaction;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Riwayat Poin Customer (ledger): hanya baca dan tambah (tidak ada ubah / hapus), daftar + pencarian + filter, akun terhapus
 * tetap tampil, entri manual admin (tambah / kurangi poin: saldo ikut berubah, saldo tidak boleh minus, pelaku tercatat,
 * pelanggan diberi notifikasi tanpa menggantungkan entri pada notifikasi), dan validasi form. "Hari ini" dibekukan di
 * 8 Oktober 2026.
 */
class PointTransactionResourceTest extends TestCase
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

    private function customer(string $name = 'Budi Santoso', int $points = 100): Customer
    {
        return Customer::create(['name' => $name, 'phone_number' => '0812' . random_int(10000000, 99999999), 'loyalty_points' => $points]);
    }

    private function tx(Customer $customer, string $type = 'earn', int $points = 10, array $extra = []): PointTransaction
    {
        return PointTransaction::create(array_merge(['customer_id' => $customer->id, 'type' => $type, 'points' => $points, 'description' => 'Poin booking', 'reference_type' => 'booking'], $extra));
    }

    private function quietPush(): void
    {
        $this->mock(PushNotificationService::class)->shouldReceive('sendToCustomer')->andReturnNull();
    }

    private function valid(Customer $customer, array $extra = []): array
    {
        return array_merge(['customer_id' => $customer->id, 'type' => 'earn', 'points' => 50, 'description' => 'Kompensasi keterlambatan'], $extra);
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules(): void
    {
        $record = new PointTransaction();

        $this->as($this->user('super_admin'));
        $this->assertTrue(PointTransactionResource::canViewAny());
        $this->assertTrue(PointTransactionResource::canView($record));
        $this->assertTrue(PointTransactionResource::canCreate());
        $this->assertFalse(PointTransactionResource::canEdit($record), 'Ledger tidak bisa diubah.');
        $this->assertFalse(PointTransactionResource::canDelete($record), 'Ledger tidak bisa dihapus.');

        $this->as($this->user('kasir'));
        $this->assertTrue(PointTransactionResource::canViewAny());
        $this->assertTrue(PointTransactionResource::canView($record));
        $this->assertFalse(PointTransactionResource::canCreate(), 'Entri manual default hanya full-access.');

        $this->as($this->user('kasir', null, ['menu_permissions' => ['PointTransactionResource' => ['create']]]));
        $this->assertTrue(PointTransactionResource::canCreate());

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(PointTransactionResource::canViewAny());
        $this->assertFalse(PointTransactionResource::canView($record));
    }

    public function test_staff_without_the_create_right_cannot_open_the_create_page(): void
    {
        $this->as($this->user('kasir'));
        $this->get(PointTransactionResource::getUrl('create'))->assertForbidden();
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_labels_actor_and_deleted_accounts(): void
    {
        $budi = $this->customer('Budi Santoso');
        $admin = $this->user('super_admin');
        $earn = $this->tx($budi, 'earn', 50, ['reference_type' => 'manual', 'description' => 'Bonus', 'created_by' => $admin->id]);
        $spend = $this->tx($budi, 'spend', 20, ['reference_type' => 'reward_redemption', 'description' => 'Tukar reward']);
        $gone = $this->customer('Akan Dihapus');
        $ofGone = $this->tx($gone);
        $gone->update(['name' => null]);
        $gone->delete();

        $this->as($this->user('kasir'));
        Livewire::test(ListPointTransactions::class)
            ->assertCanSeeTableRecords([$earn, $spend, $ofGone])
            ->assertTableColumnFormattedStateSet('type', 'Dapat Poin', record: $earn)
            ->assertTableColumnFormattedStateSet('type', 'Pakai Poin', record: $spend)
            ->assertTableColumnFormattedStateSet('reference_type', 'Entri Manual Admin', record: $earn)
            ->assertTableColumnFormattedStateSet('reference_type', 'Tukar Reward', record: $spend)
            ->assertTableColumnStateSet('createdBy.name', $admin->name, record: $earn)
            ->assertTableColumnFormattedStateSet('customer.name', '(Akun Dihapus)', record: $ofGone)
            ->assertTableColumnFormattedStateSet('customer.name', 'Budi Santoso', record: $earn);
    }

    public function test_search_and_filters(): void
    {
        $budi = $this->customer('Budi Santoso');
        $siti = $this->customer('Siti Aminah');
        $a = $this->tx($budi, 'earn', 10, ['reference_type' => 'booking', 'description' => 'Poin booking BKG-1']);
        $b = $this->tx($siti, 'spend', 5, ['reference_type' => 'warranty', 'description' => 'Registrasi garansi']);

        $this->as($this->user('kasir'));
        Livewire::test(ListPointTransactions::class)->searchTable('Siti')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListPointTransactions::class)->searchTable('BKG-1')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListPointTransactions::class)->filterTable('type', 'spend')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListPointTransactions::class)->filterTable('reference_type', 'booking')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }

    public function test_the_view_page_shows_the_translated_details(): void
    {
        $customer = $this->customer('Budi Santoso');
        $tx = $this->tx($customer, 'earn', 75, ['reference_type' => 'customer_referral', 'description' => 'Bonus ajak teman']);

        $this->as($this->user('kasir'));
        Livewire::test(ViewPointTransaction::class, ['record' => $tx->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Budi Santoso')
            ->assertSee('Dapat Poin')
            ->assertSee('Bonus Ajak Teman')
            ->assertSee('Bonus ajak teman');
    }

    // ------------------------------------------------------------- entri manual

    public function test_a_manual_earn_adds_points_logs_the_actor_and_notifies_the_customer(): void
    {
        $customer = $this->customer('Budi Santoso', 100);
        $this->mock(PushNotificationService::class)
            ->shouldReceive('sendToCustomer')
            ->once()
            ->withArgs(fn ($id, $title, $body) => $id === $customer->id && $title === 'Poin Bertambah' && str_contains($body, '50 poin'));

        $admin = $this->as($this->user('super_admin'));
        Livewire::test(CreatePointTransaction::class)
            ->fillForm($this->valid($customer))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(150, $customer->fresh()->loyalty_points);
        $tx = PointTransaction::firstOrFail();
        $this->assertSame(['earn', 50, 'manual', null, $admin->id], [$tx->type, $tx->points, $tx->reference_type, $tx->reference_id, $tx->created_by]);
        $this->assertSame('Kompensasi keterlambatan', $tx->description);
    }

    public function test_a_manual_spend_subtracts_points(): void
    {
        $customer = $this->customer('Budi Santoso', 100);
        $this->mock(PushNotificationService::class)
            ->shouldReceive('sendToCustomer')
            ->once()
            ->withArgs(fn ($id, $title) => $id === $customer->id && $title === 'Poin Berkurang');

        $this->as($this->user('super_admin'));
        Livewire::test(CreatePointTransaction::class)
            ->fillForm($this->valid($customer, ['type' => 'spend', 'points' => 40, 'description' => 'Koreksi salah input']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(60, $customer->fresh()->loyalty_points);
        $this->assertSame('spend', PointTransaction::firstOrFail()->type);
    }

    public function test_a_spend_larger_than_the_balance_is_refused_without_changes(): void
    {
        $customer = $this->customer('Budi Santoso', 30);
        $this->mock(PushNotificationService::class)->shouldNotReceive('sendToCustomer');

        $this->as($this->user('super_admin'));
        Livewire::test(CreatePointTransaction::class)
            ->fillForm($this->valid($customer, ['type' => 'spend', 'points' => 31]))
            ->call('create')
            ->assertNotified('Saldo poin tidak cukup');

        $this->assertSame(30, $customer->fresh()->loyalty_points);
        $this->assertSame(0, PointTransaction::count());

        Livewire::test(CreatePointTransaction::class)
            ->fillForm($this->valid($customer, ['type' => 'spend', 'points' => 30]))
            ->call('create');
        $this->assertSame(0, $customer->fresh()->loyalty_points, 'Menghabiskan seluruh saldo diperbolehkan.');
    }

    public function test_a_failing_push_does_not_block_the_entry(): void
    {
        $customer = $this->customer('Budi Santoso', 100);
        $this->mock(PushNotificationService::class)->shouldReceive('sendToCustomer')->andThrow(new \RuntimeException('FCM down'));

        $this->as($this->user('super_admin'));
        Livewire::test(CreatePointTransaction::class)
            ->fillForm($this->valid($customer))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(150, $customer->fresh()->loyalty_points);
        $this->assertSame(1, PointTransaction::count(), 'Entri poin tidak ikut batal karena notifikasi gagal.');
    }

    public function test_create_validation(): void
    {
        $customer = $this->customer('Budi Santoso', 100);
        $this->quietPush();
        $this->as($this->user('super_admin'));

        Livewire::test(CreatePointTransaction::class)->fillForm($this->valid($customer, ['customer_id' => null]))->call('create')->assertHasFormErrors(['customer_id' => 'required']);
        Livewire::test(CreatePointTransaction::class)->fillForm($this->valid($customer, ['type' => 'bonus']))->call('create')->assertHasFormErrors(['type']);
        Livewire::test(CreatePointTransaction::class)->fillForm($this->valid($customer, ['points' => 0]))->call('create')->assertHasFormErrors(['points']);
        Livewire::test(CreatePointTransaction::class)->fillForm($this->valid($customer, ['points' => 10.5]))->call('create')->assertHasFormErrors(['points']);
        Livewire::test(CreatePointTransaction::class)->fillForm($this->valid($customer, ['points' => 2000000]))->call('create')->assertHasFormErrors(['points']);
        Livewire::test(CreatePointTransaction::class)->fillForm($this->valid($customer, ['description' => '']))->call('create')->assertHasFormErrors(['description' => 'required']);
        Livewire::test(CreatePointTransaction::class)->fillForm($this->valid($customer, ['description' => str_repeat('a', 256)]))->call('create')->assertHasFormErrors(['description' => 'max']);

        $this->assertSame(0, PointTransaction::count());
        $this->assertSame(100, $customer->fresh()->loyalty_points);
    }

    public function test_a_deleted_account_cannot_receive_a_manual_entry(): void
    {
        $gone = $this->customer('Akan Dihapus', 100);
        $gone->update(['name' => null]);
        $gone->delete();
        $this->quietPush();

        $this->as($this->user('super_admin'));
        Livewire::test(CreatePointTransaction::class)
            ->fillForm($this->valid($gone))
            ->call('create');

        $this->assertSame(0, PointTransaction::count());
        $this->assertSame(100, Customer::withTrashed()->find($gone->id)->loyalty_points);
    }
}
