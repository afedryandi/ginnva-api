<?php

namespace Tests\Feature;

use App\Filament\Resources\RewardRedemptionResource\Pages\EditRewardRedemption;
use App\Filament\Resources\RewardResource\Pages\CreateReward;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Store;
use App\Models\User;
use App\Models\VoucherClaim;
use App\Services\RewardRedemptionService;
use App\Services\VoucherService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Voucher dipindah ke menu Reward (2026-10-10): staf membuat Reward bertipe Voucher, customer menukar poin, voucher
 * langsung terbit di "Voucher Saya" (kode unik, nominal & masa berlaku di-snapshot), lalu dipakai di booking berikutnya.
 * Voucher fisik (kode dicetak, di-assign staf) sudah dihapus. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class RewardVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        Reward::query()->delete();
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user, 'web');

        return $user;
    }

    private function customer(int $points = 1000): Customer
    {
        return Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999), 'loyalty_points' => $points]);
    }

    private function voucherReward(array $extra = []): Reward
    {
        return Reward::create(array_merge([
            'name' => 'Voucher Diskon 100rb', 'type' => 'voucher', 'points_cost' => 300, 'stock' => 5,
            'voucher_discount' => 100000, 'voucher_valid_days' => 30, 'is_active' => true,
        ], $extra));
    }

    private function booking(Customer $customer): Booking
    {
        return Booking::create([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->store->id, 'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true, 'preferred_date' => now()->addDays(3)->toDateString(), 'status' => 'confirmed',
        ]);
    }

    // ------------------------------------------------------------- staf membuat reward voucher

    public function test_staff_creates_a_voucher_reward_in_the_reward_menu(): void
    {
        $this->admin();

        Livewire::test(CreateReward::class)
            ->fillForm(['name' => 'Voucher Diskon 50rb', 'type' => 'voucher', 'points_cost' => 150, 'voucher_discount' => 50000, 'voucher_valid_days' => 60, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $reward = Reward::firstOrFail();
        $this->assertTrue($reward->isVoucher());
        $this->assertSame([50000.0, 60], [(float) $reward->voucher_discount, $reward->voucher_valid_days]);
    }

    public function test_a_voucher_reward_requires_the_discount_and_validity(): void
    {
        $this->admin();

        Livewire::test(CreateReward::class)
            ->fillForm(['name' => 'Voucher Tanpa Nominal', 'type' => 'voucher', 'points_cost' => 150, 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['voucher_discount' => 'required', 'voucher_valid_days' => 'required']);

        $this->assertSame(0, Reward::count());
    }

    public function test_an_item_reward_never_keeps_voucher_data(): void
    {
        $this->admin();

        Livewire::test(CreateReward::class)
            ->fillForm(['name' => 'Tumbler', 'type' => 'item', 'points_cost' => 150, 'voucher_discount' => 99999, 'voucher_valid_days' => 10, 'is_active' => true])
            ->call('create');

        $reward = Reward::firstOrFail();
        $this->assertSame([null, null], [$reward->voucher_discount, $reward->voucher_valid_days]);
    }

    // ------------------------------------------------------------- customer menukar poin

    public function test_the_catalog_exposes_the_type_and_voucher_terms(): void
    {
        $this->voucherReward();
        Reward::create(['name' => 'Tumbler', 'points_cost' => 100, 'stock' => 5, 'is_active' => true]);

        $data = collect($this->getJson('/api/rewards')->assertSuccessful()->json('data'))->keyBy('name');

        $this->assertSame(['voucher', 100000.0, 30], [$data['Voucher Diskon 100rb']['type'], (float) $data['Voucher Diskon 100rb']['voucher_discount'], $data['Voucher Diskon 100rb']['voucher_valid_days']]);
        $this->assertSame(['item', null], [$data['Tumbler']['type'], $data['Tumbler']['voucher_discount']]);
    }

    public function test_redeeming_a_voucher_reward_issues_the_voucher_to_my_vouchers(): void
    {
        $customer = $this->customer(1000);
        $reward = $this->voucherReward();

        $response = $this->actingAs($customer, 'customer')->postJson("/api/customer/rewards/{$reward->id}/redeem")->assertSuccessful();

        $code = $response->json('voucher.code');
        $this->assertStringStartsWith('GNV-', $code);
        $this->assertSame(700, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(4, $reward->fresh()->stock);

        $redemption = RewardRedemption::firstOrFail();
        $this->assertSame('fulfilled', $redemption->status, 'Voucher terbit langsung, tidak menunggu staf.');

        $mine = $this->actingAs($customer, 'customer')->getJson('/api/customer/vouchers')->assertSuccessful()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame([$code, 'active', 'active', 'Voucher Diskon 100rb', 100000.0, '2026-11-07', $reward->id], [
            $mine[0]['code'], $mine[0]['status'], $mine[0]['effective_status'], $mine[0]['name'], (float) $mine[0]['discount_amount'], $mine[0]['expires_at'], $mine[0]['reward_id'],
        ]);
        $this->assertSame('Voucher Diskon 100rb', $mine[0]['voucher']['name'], 'Objek voucher dipertahankan untuk aplikasi lama.');
    }

    public function test_the_voucher_keeps_its_snapshot_when_the_reward_changes_later(): void
    {
        $customer = $this->customer();
        $reward = $this->voucherReward();
        $this->actingAs($customer, 'customer')->postJson("/api/customer/rewards/{$reward->id}/redeem")->assertSuccessful();

        $reward->update(['voucher_discount' => 5000, 'voucher_valid_days' => 1]);

        $claim = VoucherClaim::firstOrFail();
        $this->assertSame([100000.0, '2026-11-07'], [$claim->faceValue(), $claim->expires_at->toDateString()]);
    }

    public function test_not_enough_points_issues_nothing(): void
    {
        $customer = $this->customer(100);
        $reward = $this->voucherReward();

        $this->actingAs($customer, 'customer')->postJson("/api/customer/rewards/{$reward->id}/redeem")->assertStatus(422);

        $this->assertSame([0, 100, 5], [VoucherClaim::count(), (int) $customer->fresh()->loyalty_points, $reward->fresh()->stock]);
    }

    public function test_partners_cannot_redeem_a_voucher_reward(): void
    {
        $partner = Partner::createAccount(['business_name' => 'Mitra', 'email' => uniqid() . '@example.com', 'password' => 'rahasia123']);
        $partner->forceFill(['points_balance' => 1000])->save();
        $reward = $this->voucherReward();

        $this->expectExceptionMessage('Voucher diskon hanya bisa ditukar oleh customer.');
        try {
            app(RewardRedemptionService::class)->redeem($partner, $reward);
        } finally {
            $this->assertSame([0, 1000], [VoucherClaim::count(), (int) $partner->fresh()->points_balance]);
        }
    }

    public function test_available_vouchers_lists_only_active_voucher_rewards_with_stock(): void
    {
        $this->voucherReward(['name' => 'Aktif']);
        $this->voucherReward(['name' => 'Habis', 'stock' => 0]);
        $this->voucherReward(['name' => 'Nonaktif', 'is_active' => false]);
        Reward::create(['name' => 'Tumbler', 'points_cost' => 100, 'is_active' => true]);

        $names = collect($this->getJson('/api/customer/vouchers/available')->assertSuccessful()->json('data'))->pluck('name')->all();

        $this->assertSame(['Aktif'], $names);
    }

    // ------------------------------------------------------------- dipakai di booking

    public function test_the_voucher_is_applied_on_a_booking_of_the_same_customer_once(): void
    {
        $customer = $this->customer();
        $reward = $this->voucherReward();
        $redemption = app(RewardRedemptionService::class)->redeem($customer, $reward);
        $claim = $redemption->voucherClaim;
        $booking = $this->booking($customer);

        DB::transaction(function () use ($claim, $booking) {
            $this->assertSame(100000.0, app(VoucherService::class)->applyToBooking($claim->id, $booking));
        });

        $this->assertSame(['used', $booking->id], [$claim->fresh()->status, $claim->fresh()->booking_id]);

        $other = $this->booking($customer);
        $this->expectExceptionMessageMatches('/sudah dipakai/');
        DB::transaction(fn () => app(VoucherService::class)->applyToBooking($claim->id, $other));
    }

    public function test_an_expired_voucher_is_refused(): void
    {
        $customer = $this->customer();
        $claim = app(RewardRedemptionService::class)->redeem($customer, $this->voucherReward(['voucher_valid_days' => 1]))->voucherClaim;
        Carbon::setTestNow('2026-10-10 10:00:00');

        $mine = $this->actingAs($customer, 'customer')->getJson('/api/customer/vouchers')->json('data');
        $this->assertSame('expired', $mine[0]['effective_status']);

        $this->expectExceptionMessageMatches('/kedaluwarsa/');
        DB::transaction(fn () => app(VoucherService::class)->applyToBooking($claim->id, $this->booking($customer)));
    }

    public function test_a_voucher_cannot_be_used_on_another_customers_booking(): void
    {
        $owner = $this->customer();
        $claim = app(RewardRedemptionService::class)->redeem($owner, $this->voucherReward())->voucherClaim;

        $this->expectExceptionMessageMatches('/milik customer lain/');
        DB::transaction(fn () => app(VoucherService::class)->applyToBooking($claim->id, $this->booking($this->customer())));
    }

    // ------------------------------------------------------------- pembatalan penukaran

    public function test_cancelling_an_unused_voucher_redemption_refunds_points_and_removes_the_voucher(): void
    {
        $customer = $this->customer(1000);
        $reward = $this->voucherReward();
        $redemption = app(RewardRedemptionService::class)->redeem($customer, $reward);
        $this->admin();

        Livewire::test(EditRewardRedemption::class, ['record' => $redemption->getRouteKey()])
            ->fillForm(['status' => 'cancelled'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, VoucherClaim::count());
        $this->assertSame(1000, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(5, $reward->fresh()->stock);
        $this->assertSame(['spend', 'earn'], PointTransaction::where('customer_id', $customer->id)->orderBy('id')->pluck('type')->all());
    }

    public function test_a_used_voucher_redemption_cannot_be_cancelled(): void
    {
        $customer = $this->customer(1000);
        $redemption = app(RewardRedemptionService::class)->redeem($customer, $this->voucherReward());
        DB::transaction(fn () => app(VoucherService::class)->applyToBooking($redemption->voucherClaim->id, $this->booking($customer)));

        $this->expectExceptionMessageMatches('/sudah dipakai di booking/');
        $redemption->fresh()->update(['status' => 'cancelled']);
    }

    public function test_a_cancelled_voucher_redemption_cannot_be_revived(): void
    {
        $customer = $this->customer(1000);
        $redemption = app(RewardRedemptionService::class)->redeem($customer, $this->voucherReward());
        $redemption->update(['status' => 'cancelled']);

        $this->expectExceptionMessageMatches('/tidak bisa diaktifkan lagi/');
        $redemption->fresh()->update(['status' => 'fulfilled']);
    }

    // ------------------------------------------------------------- voucher fisik dihapus

    public function test_the_physical_voucher_menu_and_assignment_are_gone(): void
    {
        $this->assertFalse(class_exists(\App\Filament\Resources\VoucherResource::class));
        $this->assertFalse(method_exists(VoucherService::class, 'assignToCustomer'));
        $this->assertFalse(method_exists(VoucherService::class, 'assignToWalkin'));
    }
}
