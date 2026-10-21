<?php

namespace Tests\Feature;

use App\Filament\Pages\SendNotification;
use App\Models\Carousel;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Marketing/Konten Fase 5 "Komunikasi promosi" (2026-10-27): persetujuan promo opt-in (bawaan mati), promosi hanya ke yang setuju,
 * pesan informasi tetap ke semua, batas kampanye per minggu, dan hitung klik banner.
 */
class PromoConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketing.promo_max_campaigns_per_week' => 2]);
        // Jam tenang diuji terpisah (QuietHoursTest); di sini dimatikan agar tes tidak bergantung pada jam saat dijalankan.
        config(['marketing.promo_quiet_hours.enabled' => false]);
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        Http::fake(['exp.host/*' => fn (Request $request) => Http::response(['data' => collect($request->data())->map(fn () => ['status' => 'ok'])->all()])]);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user, 'web');

        return $user;
    }

    private function customer(string $name, bool $optIn, ?string $token = null): Customer
    {
        $customer = Customer::create(['name' => $name, 'email' => strtolower($name) . '@test.local', 'phone_number' => '0812' . random_int(10000000, 99999999), 'promo_opt_in' => $optIn]);

        if ($token) {
            DeviceToken::create(['customer_id' => $customer->id, 'token' => $token, 'platform' => 'android']);
        }

        return $customer;
    }

    // ------------------------------------------------------------- preferensi pelanggan

    public function test_promo_consent_is_off_by_default_and_the_customer_controls_it(): void
    {
        $customer = $this->customer('Budi', false);

        $this->actingAs($customer, 'customer')->getJson('/api/customer/promo-preference')->assertOk()->assertJsonPath('data.opt_in', false)->assertJsonPath('data.prompted', false);
        $this->assertFalse((bool) Customer::create(['name' => 'Baru', 'phone_number' => '0812' . random_int(10000000, 99999999)])->fresh()->promo_opt_in, 'Pelanggan baru tanpa persetujuan.');

        Carbon::setTestNow('2026-11-01 10:00:00');
        $this->actingAs($customer, 'customer')->putJson('/api/customer/promo-preference', ['opt_in' => true])
            ->assertOk()->assertJsonPath('data.opt_in', true);

        $customer = $customer->fresh();
        $this->assertTrue($customer->promo_opt_in);
        $this->assertSame('app', $customer->promo_opt_in_source);
        $this->assertSame('2026-11-01 10:00:00', $customer->promo_opt_in_at->toDateTimeString());

        $this->actingAs($customer, 'customer')->putJson('/api/customer/promo-preference', ['opt_in' => false])->assertOk();
        $this->assertFalse($customer->fresh()->promo_opt_in);
        $this->assertNotNull($customer->fresh()->promo_prompted_at, 'Sudah pernah ditanya.');

        $this->actingAs($customer, 'customer')->putJson('/api/customer/promo-preference', [])->assertStatus(422);

        Carbon::setTestNow();
    }

    public function test_guests_cannot_read_or_change_the_preference(): void
    {
        $this->getJson('/api/customer/promo-preference')->assertStatus(401);
        $this->putJson('/api/customer/promo-preference', ['opt_in' => true])->assertStatus(401);
    }

    // ------------------------------------------------------------- pengiriman

    public function test_promos_only_reach_customers_who_agreed_and_leave_a_history_row_each(): void
    {
        $this->admin();
        $yes = $this->customer('Ya', true, 'ExponentPushToken[yes]');
        $no = $this->customer('Tidak', false, 'ExponentPushToken[no]');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Diskon', 'broadcast' => true])
            ->call('send');

        $rows = CustomerNotification::where('category', 'promo')->get();
        $this->assertSame([$yes->id], $rows->pluck('customer_id')->all());
        $this->assertNotNull($rows->first()->campaign_key);

        $tokens = [];
        Http::assertSent(function (Request $request) use (&$tokens) {
            $tokens = array_merge($tokens, collect($request->data())->pluck('to')->all());

            return true;
        });
        $this->assertSame(['ExponentPushToken[yes]'], $tokens);
        $this->assertSame(0, CustomerNotification::where('customer_id', $no->id)->count());
    }

    public function test_information_messages_still_reach_everyone(): void
    {
        $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[yes]');
        $this->customer('Tidak', false, 'ExponentPushToken[no]');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'info', 'title' => 'Toko tutup', 'body' => 'Libur nasional', 'broadcast' => true])
            ->call('send');

        $this->assertSame(1, CustomerNotification::whereNull('customer_id')->where('category', 'info')->count());

        $tokens = [];
        Http::assertSent(function (Request $request) use (&$tokens) {
            $tokens = array_merge($tokens, collect($request->data())->pluck('to')->all());

            return true;
        });
        sort($tokens);
        $this->assertSame(['ExponentPushToken[no]', 'ExponentPushToken[yes]'], $tokens);
    }

    public function test_a_targeted_promo_skips_customers_who_did_not_agree(): void
    {
        $this->admin();
        $yes = $this->customer('Ya', true, 'ExponentPushToken[yes]');
        $no = $this->customer('Tidak', false, 'ExponentPushToken[no]');

        Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Halo', 'body' => 'Khusus', 'broadcast' => false, 'customer_ids' => [$yes->id, $no->id]])
            ->call('send');

        $this->assertSame([$yes->id], CustomerNotification::where('category', 'promo')->pluck('customer_id')->all());
    }

    public function test_the_weekly_campaign_limit_blocks_further_promos(): void
    {
        $this->admin();
        $this->customer('Ya', true, 'ExponentPushToken[yes]');
        $send = fn () => Livewire::test(SendNotification::class)
            ->fillForm(['audience' => 'customer', 'category' => 'promo', 'title' => 'Promo', 'body' => 'Diskon', 'broadcast' => true])
            ->call('send');

        $send();
        $send();
        $this->assertSame(2, CustomerNotification::where('category', 'promo')->distinct()->count('campaign_key'));

        $send(); // ketiga: ditolak
        $this->assertSame(2, CustomerNotification::where('category', 'promo')->distinct()->count('campaign_key'));

        // Setelah 7 hari berlalu boleh lagi.
        CustomerNotification::query()->update(['created_at' => now()->subDays(8)]);
        $send();
        $this->assertSame(3, CustomerNotification::where('category', 'promo')->distinct()->count('campaign_key'));
    }

    public function test_the_api_refuses_promos_over_the_limit_with_a_clear_message(): void
    {
        $this->admin();
        $this->customer('Ya', true);
        config(['marketing.promo_max_campaigns_per_week' => 1]);

        $request = fn () => app(\App\Http\Controllers\Api\NotificationController::class)->send(
            \Illuminate\Http\Request::create('/x', 'POST', ['title' => 'P', 'body' => 'B', 'category' => 'promo'])
        );

        $this->assertSame(200, $request()->getStatusCode());
        $second = $request();
        $this->assertSame(429, $second->getStatusCode());
        $this->assertStringContainsString('Batas promosi tercapai', $second->getData(true)['message']);
    }

    // ------------------------------------------------------------- klik banner

    public function test_banner_clicks_are_counted_only_while_the_banner_is_live(): void
    {
        $live = Carousel::create(['title' => 'Tayang', 'image' => 'a.jpg', 'audience' => 'customer', 'is_active' => true]);
        $off = Carousel::create(['title' => 'Mati', 'image' => 'b.jpg', 'audience' => 'customer', 'is_active' => false]);

        $this->postJson("/api/carousels/{$live->id}/click")->assertOk()->assertJsonPath('counted', true);
        $this->postJson("/api/carousels/{$live->id}/click")->assertOk();
        $this->postJson("/api/carousels/{$off->id}/click")->assertOk()->assertJsonPath('counted', false);

        $this->assertSame(2, (int) $live->fresh()->click_count);
        $this->assertSame(0, (int) $off->fresh()->click_count);
    }
}
