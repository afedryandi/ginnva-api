<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\QuietHours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Notifikasi Fase 4 (2026-10-21): jam tenang promosi pelanggan, 21:00 sampai 08:00 WIB.
 */
class QuietHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketing.promo_quiet_hours' => ['enabled' => true, 'start' => '21:00', 'end' => '08:00', 'timezone' => 'Asia/Jakarta'],
            'marketing.promo_max_campaigns_per_week' => 5,
        ]);
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    }

    private function wib(string $time): Carbon
    {
        return Carbon::parse("2026-11-02 {$time}", 'Asia/Jakarta');
    }

    public function test_the_overnight_window_covers_21_to_08_wib_and_nothing_else(): void
    {
        foreach (['21:00', '23:59', '00:00', '03:30', '07:59'] as $quiet) {
            $this->assertTrue(QuietHours::active($this->wib($quiet)), "{$quiet} WIB harus jam tenang");
        }

        foreach (['08:00', '12:00', '20:59'] as $open) {
            $this->assertFalse(QuietHours::active($this->wib($open)), "{$open} WIB bukan jam tenang");
        }
    }

    public function test_the_window_follows_wib_regardless_of_the_server_timezone(): void
    {
        // 15:30 UTC = 22:30 WIB.
        $this->assertTrue(QuietHours::active(Carbon::parse('2026-11-02 15:30', 'UTC')));
        // 02:00 UTC = 09:00 WIB.
        $this->assertFalse(QuietHours::active(Carbon::parse('2026-11-02 02:00', 'UTC')));
    }

    public function test_the_window_can_be_switched_off_or_set_within_one_day(): void
    {
        config(['marketing.promo_quiet_hours.enabled' => false]);
        $this->assertFalse(QuietHours::active($this->wib('23:00')));

        config(['marketing.promo_quiet_hours' => ['enabled' => true, 'start' => '13:00', 'end' => '15:00', 'timezone' => 'Asia/Jakarta']]);
        $this->assertTrue(QuietHours::active($this->wib('14:00')));
        $this->assertFalse(QuietHours::active($this->wib('23:00')));

        config(['marketing.promo_quiet_hours.end' => '13:00']);
        $this->assertFalse(QuietHours::active($this->wib('13:00')), 'Awal sama dengan akhir berarti tidak ada jam tenang.');
    }

    public function test_the_label_is_readable(): void
    {
        $this->assertSame('21:00 sampai 08:00 WIB', QuietHours::label());
    }

    private function send(string $category): \Illuminate\Http\JsonResponse
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), 'web');

        return app(NotificationController::class)->send(Request::create('/x', 'POST', ['title' => 'Judul', 'body' => 'Isi', 'category' => $category]));
    }

    private function optedInCustomer(): Customer
    {
        $customer = Customer::create(['name' => 'Budi', 'email' => 'budi@test.local', 'phone_number' => '0812' . random_int(10000000, 99999999), 'promo_opt_in' => true]);
        DeviceToken::create(['customer_id' => $customer->id, 'token' => 'ExponentPushToken[budi]', 'platform' => 'android']);

        return $customer;
    }

    public function test_a_promo_at_night_is_refused_with_a_clear_message_and_sends_nothing(): void
    {
        $this->optedInCustomer();
        $this->travelTo($this->wib('22:00'));

        $response = $this->send('promo');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('jam tenang (21:00 sampai 08:00 WIB)', $response->getData(true)['message']);
        $this->assertSame(0, CustomerNotification::count());
        Http::assertNothingSent();
    }

    public function test_a_promo_during_the_day_is_sent_normally(): void
    {
        $this->optedInCustomer();
        $this->travelTo($this->wib('10:00'));

        $response = $this->send('promo');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, CustomerNotification::where('category', 'promo')->count());
    }

    public function test_important_information_is_not_held_back_at_night(): void
    {
        $this->optedInCustomer();
        $this->travelTo($this->wib('23:30'));

        $response = $this->send('info');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, CustomerNotification::where('category', 'info')->count());
    }
}
