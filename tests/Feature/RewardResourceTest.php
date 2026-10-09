<?php

namespace Tests\Feature;

use App\Exports\RewardExport;
use App\Filament\Resources\RewardResource;
use App\Filament\Resources\RewardResource\Pages\CreateReward;
use App\Filament\Resources\RewardResource\Pages\EditReward;
use App\Filament\Resources\RewardResource\Pages\ListRewards;
use App\Models\Customer;
use App\Models\Reward;
use App\Models\RewardRedemption;
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
 * Katalog Reward: hak akses, daftar + pencarian, tambah / ubah (harga poin & stok bilangan bulat dalam batas, stok kosong =
 * tanpa batas), jejak audit, hapus (reward yang pernah ditukar ditolak dari daftar MAUPUN halaman ubah dengan pesan jelas),
 * kelayakan tukar, dan ekspor + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class RewardResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        Reward::query()->delete();
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $menuAccess = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => Store::first()->id, 'menu_access' => $menuAccess, 'is_active' => true], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function as(User $user): User
    {
        $this->actingAs($user, 'web');

        return $user;
    }

    private function reward(string $name, array $extra = []): Reward
    {
        return Reward::create(array_merge(['name' => $name, 'points_cost' => 100, 'stock' => 5, 'is_active' => true], $extra));
    }

    private function redeem(Reward $reward): RewardRedemption
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return RewardRedemption::create(['redeemer_type' => 'customer', 'redeemer_id' => $customer->id, 'reward_id' => $reward->id, 'points_spent' => $reward->points_cost, 'status' => 'pending']);
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules(): void
    {
        $record = new Reward();

        $this->as($this->user('super_admin'));
        $this->assertTrue(RewardResource::canViewAny());
        $this->assertTrue(RewardResource::canCreate());
        $this->assertTrue(RewardResource::canEdit($record));
        $this->assertTrue(RewardResource::canDelete($record));

        $this->as($this->user('kasir'));
        $this->assertTrue(RewardResource::canViewAny());
        $this->assertTrue(RewardResource::canCreate());
        $this->assertTrue(RewardResource::canEdit($record));
        $this->assertTrue(RewardResource::canDelete($record));

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(RewardResource::canViewAny());
        $this->assertFalse(RewardResource::canCreate());
        $this->assertFalse(RewardResource::canEdit($record));
        $this->assertFalse(RewardResource::canDelete($record));
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_ordered_by_points_and_searchable(): void
    {
        $cheap = $this->reward('Voucher Diskon', ['points_cost' => 50, 'stock' => null]);
        $dear = $this->reward('Merchandise Jaket', ['points_cost' => 500]);

        $this->as($this->user('kasir'));
        Livewire::test(ListRewards::class)
            ->assertCanSeeTableRecords([$cheap, $dear], inOrder: true)
            ->assertTableColumnStateSet('stock', null, record: $cheap)
            ->assertTableColumnStateSet('points_cost', 500, record: $dear);

        Livewire::test(ListRewards::class)->searchTable('Jaket')->assertCanSeeTableRecords([$dear])->assertCanNotSeeTableRecords([$cheap]);
    }

    // ------------------------------------------------------------- tambah & ubah

    public function test_create_a_reward_and_log_it(): void
    {
        $user = $this->as($this->user('kasir'));

        Livewire::test(CreateReward::class)
            ->fillForm(['name' => 'Tumbler Ginnva', 'description' => 'Tumbler eksklusif', 'points_cost' => 250, 'stock' => 20, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $reward = Reward::where('name', 'Tumbler Ginnva')->firstOrFail();
        $this->assertSame(250, $reward->points_cost);
        $this->assertSame(20, $reward->stock);
        $this->assertTrue($reward->isRedeemable());
        $this->assertSame($user->id, Activity::where('log_name', 'reward')->latest('id')->firstOrFail()->causer_id);
    }

    public function test_an_empty_stock_means_unlimited(): void
    {
        $this->as($this->user('kasir'));

        Livewire::test(CreateReward::class)
            ->fillForm(['name' => 'Voucher Tanpa Batas', 'points_cost' => 100, 'stock' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        $reward = Reward::where('name', 'Voucher Tanpa Batas')->firstOrFail();
        $this->assertNull($reward->stock);
        $this->assertTrue($reward->isRedeemable());
    }

    public function test_create_validation(): void
    {
        $this->as($this->user('kasir'));
        $valid = ['name' => 'Reward', 'points_cost' => 100, 'stock' => 5];

        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['name' => '']))->call('create')->assertHasFormErrors(['name' => 'required']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['name' => str_repeat('a', 256)]))->call('create')->assertHasFormErrors(['name' => 'max']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['points_cost' => 0]))->call('create')->assertHasFormErrors(['points_cost']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['points_cost' => null]))->call('create')->assertHasFormErrors(['points_cost' => 'required']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['points_cost' => 10.5]))->call('create')->assertHasFormErrors(['points_cost']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['points_cost' => 1000000000]))->call('create')->assertHasFormErrors(['points_cost']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['stock' => -1]))->call('create')->assertHasFormErrors(['stock']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['stock' => 2.5]))->call('create')->assertHasFormErrors(['stock']);
        Livewire::test(CreateReward::class)->fillForm(array_merge($valid, ['stock' => 5000000]))->call('create')->assertHasFormErrors(['stock']);

        $this->assertSame(0, Reward::count());
    }

    public function test_a_zero_stock_reward_can_be_saved_but_is_not_redeemable(): void
    {
        $this->as($this->user('kasir'));

        Livewire::test(CreateReward::class)
            ->fillForm(['name' => 'Habis', 'points_cost' => 100, 'stock' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        $reward = Reward::where('name', 'Habis')->firstOrFail();
        $this->assertSame(0, $reward->stock);
        $this->assertFalse($reward->isRedeemable());
    }

    public function test_edit_updates_the_reward_and_logs_the_change(): void
    {
        $reward = $this->reward('Tumbler', ['points_cost' => 100]);
        $this->redeem($reward);
        $this->as($this->user('kasir'));

        Livewire::test(EditReward::class, ['record' => $reward->getRouteKey()])
            ->fillForm(['points_cost' => 150, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $reward->fresh();
        $this->assertSame(150, $fresh->points_cost);
        $this->assertFalse($fresh->is_active);
        $this->assertFalse($fresh->isRedeemable());
        $this->assertSame(100, RewardRedemption::firstOrFail()->points_spent, 'Riwayat penukaran lama tidak berubah.');
        $this->assertNotNull(Activity::where('log_name', 'reward')->where('subject_id', $reward->id)->where('description', 'like', '%diubah%')->first());
    }

    // ------------------------------------------------------------- hapus

    public function test_an_unused_reward_is_deleted_from_the_list_and_from_the_edit_page(): void
    {
        $a = $this->reward('Daftar');
        $b = $this->reward('Halaman Ubah');
        $this->as($this->user('kasir'));

        Livewire::test(ListRewards::class)->callTableAction('delete', $a)->assertNotified('Reward dihapus');
        $this->assertNull(Reward::find($a->id));

        Livewire::test(EditReward::class, ['record' => $b->getRouteKey()])->callAction('delete')->assertNotified('Reward dihapus');
        $this->assertNull(Reward::find($b->id));
    }

    public function test_a_reward_with_redemptions_cannot_be_deleted_from_either_place(): void
    {
        $reward = $this->reward('Pernah Ditukar');
        $redemption = $this->redeem($reward);
        $this->as($this->user('kasir'));

        Livewire::test(ListRewards::class)
            ->callTableAction('delete', $reward)
            ->assertNotified('Tidak bisa menghapus reward ini');

        Livewire::test(EditReward::class, ['record' => $reward->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Tidak bisa menghapus reward ini');

        $this->assertNotNull(Reward::find($reward->id));
        $this->assertNotNull(RewardRedemption::find($redemption->id));
    }

    // ------------------------------------------------------------- ekspor

    public function test_exports_download_and_are_logged(): void
    {
        $this->reward('Voucher Diskon', ['points_cost' => 50, 'stock' => null]);
        $this->reward('Tumbler', ['points_cost' => 250, 'stock' => 3, 'is_active' => false]);

        $export = new RewardExport();
        $this->assertSame(['Nama', 'Harga Poin', 'Stok', 'Aktif'], $export->headings());
        $this->assertSame([['Voucher Diskon', 50, 'Tanpa batas', 'Ya'], ['Tumbler', 250, 3, 'Tidak']], $export->collection()->all());

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        $page = Livewire::test(ListRewards::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('katalog-reward-20261008-100000.xlsx');
        $page->callAction('exportPdf')->assertFileDownloaded('katalog-reward-20261008-100000.pdf');

        $logs = Activity::where('log_name', 'report_export')->orderBy('id')->get();
        $this->assertSame(['xlsx', 'pdf'], $logs->pluck('properties.format')->all());
        $this->assertSame('reward', $logs[0]->properties['report']);
        $this->assertSame($admin->id, $logs[0]->causer_id);
    }
}
