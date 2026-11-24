<?php

namespace Tests\Feature;

use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pengaman push di lingkungan lokal (2026-11-22): API di laptop memegang salinan token ponsel asli dan push lewat Expo tidak butuh kredensial server,
 * jadi uji lokal sempat mengirim notifikasi sungguhan. Push dilewati bila APP_ENV=local DAN APP_URL menunjuk ke mesin lokal, kecuali PUSH_ALLOW_LOCAL=true.
 * Server production, termasuk yang keliru ber-APP_ENV=local tetapi ber-APP_URL asli, tetap mengirim.
 */
class PushLocalGuardTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[tokenUjiLokal1234567890]';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'tiket-1']]], 200)]);
    }

    private function asEnvironment(string $env, string $url, ?bool $allowLocal = null): void
    {
        $this->app['env'] = $env;
        config(['app.env' => $env, 'app.url' => $url]);

        if ($allowLocal !== null) {
            config(['services.push.allow_local' => $allowLocal]);
        }
    }

    private function push(): array
    {
        return app(PushNotificationService::class)->pushToTokens([self::TOKEN], 'Judul uji', 'Isi uji');
    }

    private function sentToExpo(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'exp.host/--/api/v2/push/send'))->count();
    }

    public function test_push_is_skipped_on_a_local_development_machine(): void
    {
        $this->asEnvironment('local', 'http://127.0.0.1:8000', false);

        $this->assertTrue(PushNotificationService::isLocalDevelopment());
        $this->assertFalse(PushNotificationService::sendingAllowed());
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->push());
        $this->assertSame(0, $this->sentToExpo(), 'Tidak boleh ada permintaan ke Expo dari lingkungan lokal.');
    }

    public function test_the_other_local_hosts_are_recognised_too(): void
    {
        foreach (['http://localhost:8000', 'http://ginnva-api.test', 'http://app.localhost'] as $url) {
            $this->asEnvironment('local', $url, false);
            $this->assertTrue(PushNotificationService::isLocalDevelopment(), $url);
        }
    }

    public function test_push_can_be_switched_on_deliberately_for_local_testing(): void
    {
        $this->asEnvironment('local', 'http://127.0.0.1:8000', true);

        $this->assertTrue(PushNotificationService::sendingAllowed());
        $this->assertSame(1, $this->push()['sent']);
        $this->assertSame(1, $this->sentToExpo());
    }

    public function test_production_always_sends(): void
    {
        $this->asEnvironment('production', 'https://api.ginnva.id', false);

        $this->assertFalse(PushNotificationService::isLocalDevelopment());
        $this->assertSame(1, $this->push()['sent']);
        $this->assertSame(1, $this->sentToExpo());
    }

    public function test_a_production_server_wrongly_marked_local_still_sends_because_its_url_is_real(): void
    {
        // Pernah terjadi: .env production lama berisi APP_ENV=local. Push tidak boleh ikut terhenti.
        $this->asEnvironment('local', 'https://api.ginnva.id', false);

        $this->assertFalse(PushNotificationService::isLocalDevelopment());
        $this->assertSame(1, $this->push()['sent']);
    }

    public function test_the_test_suite_environment_is_not_treated_as_local(): void
    {
        $this->asEnvironment('testing', 'http://localhost', false);

        $this->assertFalse(PushNotificationService::isLocalDevelopment());
        $this->assertTrue(PushNotificationService::sendingAllowed());
    }
}
