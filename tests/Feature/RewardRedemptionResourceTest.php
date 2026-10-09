<?php

namespace Tests\Feature;

use App\Exports\RewardRedemptionExport;
use App\Filament\Resources\RewardRedemptionResource;
use App\Filament\Resources\RewardRedemptionResource\Pages\EditRewardRedemption;
use App\Filament\Resources\RewardRedemptionResource\Pages\ListRewardRedemptions;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use App\Services\RewardRedemptionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Klaim Reward: daftar (pemegang customer / partner / akun terhapus), filter, badge menunggu, penukaran lewat layanan
 * (poin & stok berkurang), dan pengubahan status oleh admin: Dikirim memberi tahu customer; Dibatalkan mengembalikan poin &
 * stok (batal-dibatalkan mendebit lagi, tanpa saldo negatif dan tanpa error saat stok 0); jejak audit dan ekspor + log.
 * "Hari ini" dibekukan di 8 Oktober 2026.
 */
class RewardRedemptionResourceTest extends TestCase
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
        Reward::query()->delete();
        RewardRedemption::flushRedeemerCache();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RewardRedemption::flushRedeemerCache();
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

    private function reward(string $name = 'Tumbler', array $extra = []): Reward
    {
        return Reward::create(array_merge(['name' => $name, 'points_cost' => 100, 'stock' => 5, 'is_active' => true], $extra));
    }

    private function customer(string $name = 'Budi Santoso', int $points = 500): Customer
    {
        return Customer::create(['name' => $name, 'phone_number' => '0812' . random_int(10000000, 99999999), 'loyalty_points' => $points]);
    }

    private function partner(string $name = 'Mitra Bengkel', int $points = 500): Partner
    {
        $partner = Partner::createAccount(['business_name' => $name, 'email' => uniqid() . '@example.com', 'password' => 'rahasia123']);
        $partner->update(['points_balance' => $points]);

        return $partner->fresh();
    }

    private function redeem($redeemer, Reward $reward): RewardRedemption
    {
        return app(RewardRedemptionService::class)->redeem($redeemer, $reward);
    }

    private function setStatus(RewardRedemption $redemption, string $status, ?string $notes = null): void
    {
        Livewire::test(EditRewardRedemption::class, ['record' => $redemption->getRouteKey()])
            ->fillForm(array_filter(['status' => $status, 'notes' => $notes], fn ($v) => $v !== null))
            ->call('save')
            ->assertHasNoFormErrors();
    }

    private function quietPush(): void
    {
        $this->mock(PushNotificationService::class)->shouldReceive('sendToCustomer')->andReturnNull();
    }

    // ------------------------------------------------------------- akses

    public function test_access_rules(): void
    {
        $record = new RewardRedemption();

        $this->as($this->user('super_admin'));
        $this->assertTrue(RewardRedemptionResource::canViewAny());
        $this->assertTrue(RewardRedemptionResource::canEdit($record));
        $this->assertFalse(RewardRedemptionResource::canCreate(), 'Penukaran hanya dibuat dari app.');
        $this->assertFalse(RewardRedemptionResource::canDelete($record));
        $this->assertFalse(RewardRedemptionResource::canDeleteAny());

        $this->as($this->user('kasir'));
        $this->assertTrue(RewardRedemptionResource::canViewAny());
        $this->assertTrue(RewardRedemptionResource::canEdit($record));
        $this->assertFalse(RewardRedemptionResource::canDelete($record), 'Penukaran tidak pernah dihapus lewat panel.');

        $this->as($this->user('kasir', ['BookingResource']));
        $this->assertFalse(RewardRedemptionResource::canViewAny());
        $this->assertFalse(RewardRedemptionResource::canEdit($record));
    }

    // ------------------------------------------------------------- daftar

    public function test_list_shows_redeemers_labels_and_the_pending_badge(): void
    {
        $this->quietPush();
        $reward = $this->reward('Tumbler');
        $other = $this->reward('Jaket');
        $byCustomer = $this->redeem($this->customer('Budi Santoso'), $reward);
        $byPartner = $this->redeem($this->partner('Mitra Bengkel'), $other);
        $deleted = $this->customer('Akan Dihapus');
        $ofDeleted = $this->redeem($deleted, $reward);
        $deleted->update(['name' => null]);
        $deleted->delete();
        RewardRedemption::flushRedeemerCache();
        $done = $this->redeem($this->customer('Siti Aminah'), $reward);
        $done->update(['status' => 'fulfilled']);

        $this->as($this->user('kasir'));
        Livewire::test(ListRewardRedemptions::class)
            ->assertCanSeeTableRecords([$byCustomer, $byPartner, $ofDeleted, $done])
            ->assertTableColumnStateSet('redeemer_name', 'Budi Santoso (Customer)', record: $byCustomer)
            ->assertTableColumnStateSet('redeemer_name', 'Mitra Bengkel (Partner)', record: $byPartner)
            ->assertTableColumnStateSet('redeemer_name', 'Pelanggan Terhapus (Customer)', record: $ofDeleted)
            ->assertTableColumnFormattedStateSet('status', 'Menunggu Diproses', record: $byCustomer)
            ->assertTableColumnFormattedStateSet('status', 'Sudah Dikirim', record: $done)
            ->assertTableColumnStateSet('reward.name', 'Jaket', record: $byPartner);

        $this->assertSame('3', RewardRedemptionResource::getNavigationBadge());
    }

    public function test_search_and_filters(): void
    {
        $this->quietPush();
        $tumbler = $this->reward('Tumbler');
        $jaket = $this->reward('Jaket');
        $a = $this->redeem($this->customer('Budi'), $tumbler);
        $b = $this->redeem($this->partner('Mitra'), $jaket);
        $b->update(['status' => 'fulfilled']);

        $this->as($this->user('kasir'));
        Livewire::test(ListRewardRedemptions::class)->searchTable('Jaket')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListRewardRedemptions::class)->filterTable('status', 'pending')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListRewardRedemptions::class)->filterTable('redeemer_type', 'partner')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    // ------------------------------------------------------------- penukaran lewat layanan

    public function test_redeeming_debits_points_and_stock_and_writes_the_ledger(): void
    {
        $reward = $this->reward('Tumbler', ['points_cost' => 150, 'stock' => 2]);
        $customer = $this->customer('Budi', 400);

        $redemption = $this->redeem($customer, $reward);

        $this->assertSame('pending', $redemption->status);
        $this->assertSame(150, $redemption->points_spent);
        $this->assertSame(250, $customer->fresh()->loyalty_points);
        $this->assertSame(1, $reward->fresh()->stock);
        $tx = PointTransaction::where('reference_type', 'reward_redemption')->firstOrFail();
        $this->assertSame(['spend', 150], [$tx->type, $tx->points]);
    }

    public function test_redeeming_is_rejected_without_points_or_stock(): void
    {
        $poor = $this->customer('Miskin', 50);
        $reward = $this->reward('Tumbler', ['points_cost' => 100, 'stock' => 1]);

        try {
            $this->redeem($poor, $reward);
            $this->fail('Poin tidak cukup harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Poin Anda tidak cukup', $e->getMessage());
        }

        $empty = $this->reward('Habis', ['stock' => 0]);
        try {
            $this->redeem($this->customer('Kaya', 900), $empty);
            $this->fail('Stok habis harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah tidak tersedia', $e->getMessage());
        }

        $this->assertSame(0, RewardRedemption::count());
        $this->assertSame(50, $poor->fresh()->loyalty_points);
    }

    // ------------------------------------------------------------- ubah status

    public function test_fulfilling_notifies_the_customer_and_keeps_points_and_stock(): void
    {
        $reward = $this->reward('Tumbler', ['stock' => 3]);
        $customer = $this->customer('Budi', 500);
        $redemption = $this->redeem($customer, $reward);

        $this->mock(PushNotificationService::class)
            ->shouldReceive('sendToCustomer')
            ->once()
            ->withArgs(fn ($id, $title, $body) => $id === $customer->id && $title === 'Reward Sudah Dikirim' && str_contains($body, 'Tumbler'));

        $admin = $this->as($this->user('super_admin'));
        $this->setStatus($redemption, 'fulfilled', 'Diambil di toko');

        $fresh = $redemption->fresh();
        $this->assertSame('fulfilled', $fresh->status);
        $this->assertSame('Diambil di toko', $fresh->notes);
        $this->assertSame(400, $customer->fresh()->loyalty_points);
        $this->assertSame(2, $reward->fresh()->stock);
        $log = Activity::where('log_name', 'reward_redemption')->where('subject_id', $redemption->id)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_cancelling_refunds_points_and_stock_and_can_be_undone(): void
    {
        $this->quietPush();
        $reward = $this->reward('Tumbler', ['points_cost' => 100, 'stock' => 3]);
        $customer = $this->customer('Budi', 500);
        $redemption = $this->redeem($customer, $reward);
        $this->assertSame([400, 2], [$customer->fresh()->loyalty_points, $reward->fresh()->stock]);

        $this->as($this->user('super_admin'));
        $this->setStatus($redemption, 'cancelled');

        $this->assertSame([500, 3], [$customer->fresh()->loyalty_points, $reward->fresh()->stock]);
        $refund = PointTransaction::where('reference_type', 'reward_redemption_refund')->firstOrFail();
        $this->assertSame(['earn', 100, $redemption->id], [$refund->type, $refund->points, $refund->reference_id]);

        $this->setStatus($redemption->fresh(), 'pending');

        $this->assertSame([400, 2], [$customer->fresh()->loyalty_points, $reward->fresh()->stock]);
        $reversal = PointTransaction::where('reference_type', 'reward_redemption_reversal')->firstOrFail();
        $this->assertSame(['spend', 100], [$reversal->type, $reversal->points]);
    }

    public function test_undoing_a_cancel_never_makes_the_balance_negative(): void
    {
        $this->quietPush();
        $reward = $this->reward('Tumbler', ['points_cost' => 100, 'stock' => 3]);
        $customer = $this->customer('Budi', 100);
        $redemption = $this->redeem($customer, $reward);
        $this->as($this->user('super_admin'));
        $this->setStatus($redemption, 'cancelled');
        $this->assertSame(100, $customer->fresh()->loyalty_points);

        $customer->update(['loyalty_points' => 30]);
        $this->setStatus($redemption->fresh(), 'pending');

        $this->assertSame(0, $customer->fresh()->loyalty_points, 'Saldo tidak minus.');
        $this->assertSame(30, PointTransaction::where('reference_type', 'reward_redemption_reversal')->firstOrFail()->points, 'Yang dicatat jumlah yang benar-benar didebit.');
    }

    public function test_undoing_a_cancel_when_the_reward_is_out_of_stock_does_not_fail(): void
    {
        $this->quietPush();
        $reward = $this->reward('Tumbler', ['points_cost' => 100, 'stock' => 1]);
        $customer = $this->customer('Budi', 500);
        $redemption = $this->redeem($customer, $reward);
        $this->as($this->user('super_admin'));
        $this->setStatus($redemption, 'cancelled');
        $this->assertSame(1, $reward->fresh()->stock);

        $reward->update(['stock' => 0]);
        $this->setStatus($redemption->fresh(), 'pending');

        $this->assertSame(0, $reward->fresh()->stock, 'Stok tetap 0, tidak minus dan tidak error.');
        $this->assertSame('pending', $redemption->fresh()->status);
    }

    public function test_an_unlimited_stock_reward_stock_is_untouched(): void
    {
        $this->quietPush();
        $reward = $this->reward('Voucher Diskon', ['points_cost' => 50, 'stock' => null]);
        $customer = $this->customer('Budi', 200);
        $redemption = $this->redeem($customer, $reward);
        $this->as($this->user('super_admin'));

        $this->setStatus($redemption, 'cancelled');

        $this->assertNull($reward->fresh()->stock);
        $this->assertSame(200, $customer->fresh()->loyalty_points);
    }

    public function test_cancelling_a_partner_redemption_refunds_the_partner_ledger(): void
    {
        $reward = $this->reward('Jaket', ['points_cost' => 200, 'stock' => 2]);
        $partner = $this->partner('Mitra Bengkel', 500);
        $redemption = $this->redeem($partner, $reward);
        $this->assertSame(300, $partner->fresh()->points_balance);

        $this->as($this->user('super_admin'));
        $this->setStatus($redemption, 'cancelled');

        $this->assertSame(500, $partner->fresh()->points_balance);
        $this->assertSame(2, $reward->fresh()->stock);
        $refund = PartnerPointTransaction::where('reference_type', 'reward_redemption_refund')->firstOrFail();
        $this->assertSame(['earn', 200, $partner->id], [$refund->type, $refund->points, $refund->partner_id]);
    }

    public function test_cancelling_for_a_deleted_account_still_returns_the_stock(): void
    {
        $this->quietPush();
        $reward = $this->reward('Tumbler', ['points_cost' => 100, 'stock' => 3]);
        $customer = $this->customer('Akan Dihapus', 300);
        $redemption = $this->redeem($customer, $reward);
        $customer->update(['name' => null]);
        $customer->delete();
        RewardRedemption::flushRedeemerCache();

        $this->as($this->user('super_admin'));
        $this->setStatus($redemption, 'cancelled');

        $this->assertSame(3, $reward->fresh()->stock, 'Stok kembali walau akun pelanggan sudah dihapus.');
    }

    public function test_a_failing_push_does_not_block_the_status_change(): void
    {
        $reward = $this->reward('Tumbler');
        $customer = $this->customer('Budi', 500);
        $redemption = $this->redeem($customer, $reward);
        $this->mock(PushNotificationService::class)->shouldReceive('sendToCustomer')->andThrow(new \RuntimeException('FCM down'));

        $this->as($this->user('super_admin'));
        $this->setStatus($redemption, 'fulfilled');

        $this->assertSame('fulfilled', $redemption->fresh()->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->quietPush();
        $redemption = $this->redeem($this->customer('Budi', 500), $this->reward());
        $this->as($this->user('kasir'));

        Livewire::test(EditRewardRedemption::class, ['record' => $redemption->getRouteKey()])
            ->fillForm(['status' => 'selesai'])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame('pending', $redemption->fresh()->status);
    }

    // ------------------------------------------------------------- ekspor

    public function test_exports_download_and_are_logged(): void
    {
        $this->quietPush();
        $reward = $this->reward('Tumbler');
        $redemption = $this->redeem($this->customer('Budi Santoso', 500), $reward);
        $redemption->update(['notes' => 'Catatan']);

        $export = new RewardRedemptionExport();
        $this->assertSame(['Ditukar Oleh', 'Tipe', 'Reward', 'Poin', 'Status', 'Catatan Admin', 'Tanggal'], $export->headings());
        $this->assertSame(['Budi Santoso (Customer)', 'Customer', 'Tumbler', 100, 'Menunggu Diproses', 'Catatan', '2026-10-08 10:00'], $export->collection()->first());

        $admin = $this->as($this->user('super_admin'));
        Excel::fake();
        $page = Livewire::test(ListRewardRedemptions::class);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('klaim-reward-20261008-100000.xlsx');
        $page->callAction('exportPdf')->assertFileDownloaded('klaim-reward-20261008-100000.pdf');

        $logs = Activity::where('log_name', 'report_export')->orderBy('id')->get();
        $this->assertSame(['xlsx', 'pdf'], $logs->pluck('properties.format')->all());
        $this->assertSame('reward_redemption', $logs[0]->properties['report']);
        $this->assertSame($admin->id, $logs[0]->causer_id);
    }
}
