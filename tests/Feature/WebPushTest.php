<?php

namespace Tests\Feature;

use App\Filament\Pages\NotifikasiHp;
use App\Models\Store;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\StaffInboxNotification;
use App\Services\StaffNotifier;
use App\Services\WebPushService;
use Filament\Facades\Filament;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PWA panel admin (2026-11-24): langganan push web perangkat, pengiriman lewat WebPushService/WebPushChannel, dan halaman Notifikasi di HP.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    /** @var list<array{endpoint: string, payload: string}> */
    private array $queued = [];

    /** @var list<string> endpoint yang dibalas 410 (langganan kedaluwarsa) oleh klien palsu */
    private array $expired = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        config(['services.webpush.public_key' => 'BPublicKeyContoh', 'services.webpush.private_key' => 'PrivateKeyContoh', 'services.webpush.subject' => 'mailto:admin@ginnva.id']);
        $this->bindFakeClient();
    }

    private function user(): User
    {
        return tap(User::create(['name' => 'Staff ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'is_active' => true]), fn (User $u) => $u->assignRole('kasir'));
    }

    private function bindFakeClient(): void
    {
        $this->queued = [];
        $test = $this;

        $client = \Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->andReturnUsing(function ($subscription, $payload) use ($test) {
            $test->queued[] = ['endpoint' => $subscription->getEndpoint(), 'payload' => (string) $payload];

            return true;
        });
        $client->shouldReceive('flush')->andReturnUsing(function () use ($test) {
            foreach ($test->queued as $item) {
                $gone = in_array($item['endpoint'], $test->expired, true);
                yield new MessageSentReport(new PsrRequest('POST', $item['endpoint']), new PsrResponse($gone ? 410 : 201), ! $gone, $gone ? 'Gone' : 'OK');
            }
        });

        $this->app->bind(WebPushService::class, fn () => new WebPushService(fn () => $client));
    }

    private function subscription(User $user, string $endpoint): WebPushSubscription
    {
        return WebPushSubscription::remember($user->id, $endpoint, 'p256dh-' . uniqid(), 'auth-' . uniqid());
    }

    private function body(string $endpoint = 'https://push.example.test/send/abc'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'kunci-publik', 'auth' => 'kunci-auth'], 'content_encoding' => 'aes128gcm'];
    }

    public function test_a_logged_in_user_can_subscribe_and_the_same_device_is_stored_once(): void
    {
        $user = $this->user();

        $this->postJson('/web-push/subscribe', $this->body())->assertStatus(401);

        $this->actingAs($user, 'web')->postJson('/web-push/subscribe', $this->body())->assertSuccessful();
        $this->actingAs($user, 'web')->postJson('/web-push/subscribe', $this->body())->assertSuccessful();

        $this->assertSame(1, WebPushSubscription::count());
        $row = WebPushSubscription::firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('kunci-publik', $row->p256dh);
        $this->assertSame('aes128gcm', $row->content_encoding);

        // Perangkat yang sama dipakai akun lain (mis. ganti login di HP yang sama): langganan pindah ke akun yang sedang login.
        $other = $this->user();
        $this->actingAs($other, 'web')->postJson('/web-push/subscribe', $this->body())->assertSuccessful();
        $this->assertSame(1, WebPushSubscription::count());
        $this->assertSame($other->id, WebPushSubscription::firstOrFail()->user_id);
    }

    public function test_subscribe_validates_the_payload_and_needs_keys_on_the_server(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'web')->postJson('/web-push/subscribe', ['endpoint' => 'bukan-url'])->assertStatus(422);
        $this->actingAs($user, 'web')->postJson('/web-push/subscribe', ['endpoint' => 'https://push.example.test/x', 'keys' => ['p256dh' => 'a']])->assertStatus(422);

        config(['services.webpush.private_key' => null]);
        $this->actingAs($user, 'web')->postJson('/web-push/subscribe', $this->body())->assertStatus(503);
        $this->assertSame(0, WebPushSubscription::count());
    }

    public function test_unsubscribe_removes_only_my_own_device(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->subscription($me, 'https://push.example.test/mine');
        $this->subscription($other, 'https://push.example.test/theirs');

        $this->actingAs($me, 'web')->postJson('/web-push/unsubscribe', ['endpoint' => 'https://push.example.test/theirs'])->assertSuccessful();
        $this->assertSame(2, WebPushSubscription::count(), 'Tidak boleh mencabut perangkat akun lain.');

        $this->actingAs($me, 'web')->postJson('/web-push/unsubscribe', ['endpoint' => 'https://push.example.test/mine'])->assertSuccessful();
        $this->assertSame(['https://push.example.test/theirs'], WebPushSubscription::pluck('endpoint')->all());
    }

    public function test_push_goes_to_every_device_of_the_users_and_expired_ones_are_removed(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->subscription($a, 'https://push.example.test/a1');
        $this->subscription($a, 'https://push.example.test/a2');
        $this->subscription($b, 'https://push.example.test/b1');
        $this->expired = ['https://push.example.test/a2'];

        $result = app(WebPushService::class)->pushTo([$a->id], 'Judul', 'Isi pesan', 'https://panel.test/admin/x');

        $this->assertSame(['sent' => 1, 'failed' => 1, 'removed' => 1], $result);
        $this->assertSame(['https://push.example.test/a1', 'https://push.example.test/a2'], array_column($this->queued, 'endpoint'));
        $this->assertSame(['title' => 'Judul', 'body' => 'Isi pesan', 'url' => 'https://panel.test/admin/x'], json_decode($this->queued[0]['payload'], true));
        $this->assertSame(['https://push.example.test/a1', 'https://push.example.test/b1'], WebPushSubscription::orderBy('id')->pluck('endpoint')->all(), 'Langganan kedaluwarsa dihapus.');
    }

    public function test_nothing_is_sent_without_keys_or_in_local_development(): void
    {
        $user = $this->user();
        $this->subscription($user, 'https://push.example.test/a1');
        $service = app(WebPushService::class);

        config(['services.webpush.public_key' => null]);
        $this->assertSame(0, $service->pushTo([$user->id], 'T', 'B')['sent']);

        config(['services.webpush.public_key' => 'BPublicKeyContoh', 'app.url' => 'http://127.0.0.1:8000']);
        $this->app['env'] = 'local';
        $this->assertSame(['sent' => 0, 'failed' => 0, 'removed' => 0], $service->pushTo([$user->id], 'T', 'B'), 'Lokal: diblokir seperti push aplikasi.');
        $this->assertSame([], $this->queued);
    }

    public function test_inbox_notifications_also_use_the_web_push_channel_only_for_subscribed_users(): void
    {
        $subscribed = $this->user();
        $plain = $this->user();
        $this->subscription($subscribed, 'https://push.example.test/a1');

        $notification = new StaffInboxNotification('Permohonan Pembelian Baru', 'Ada permohonan menunggu.');

        $this->assertSame(['database', WebPushChannel::class], $notification->via($subscribed));
        $this->assertSame(['database'], $notification->via($plain));

        config(['services.webpush.private_key' => null]);
        $this->assertSame(['database'], $notification->via($subscribed), 'Tanpa kunci server, saluran push web tidak dipakai.');
    }

    public function test_the_staff_notifier_delivers_to_the_panel_bell_and_the_pwa_device(): void
    {
        $this->mock(\App\Services\PushNotificationService::class)->shouldReceive('sendToUsers')->andReturnNull();
        $user = $this->user();
        $this->subscription($user, 'https://push.example.test/a1');

        app(StaffNotifier::class)->toUsers([$user->id], 'Memo Barang Menunggu Persetujuan', 'Budi mengajukan pengambilan barang.');

        $this->assertSame(1, $user->notifications()->count(), 'Lonceng panel tetap terisi.');
        $this->assertCount(1, $this->queued);

        $payload = json_decode($this->queued[0]['payload'], true);
        $this->assertSame('Memo Barang Menunggu Persetujuan', $payload['title']);
        $this->assertSame('Budi mengajukan pengambilan barang.', $payload['body']);
        $this->assertNotEmpty($payload['url']);
    }

    public function test_the_test_endpoint_needs_a_registered_device(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'web')->postJson('/web-push/test')->assertStatus(422);

        $this->subscription($user, 'https://push.example.test/a1');
        $this->actingAs($user, 'web')->postJson('/web-push/test')->assertSuccessful()->assertJsonPath('result.sent', 1);
        $this->assertSame('Tes notifikasi Ginnva', json_decode($this->queued[0]['payload'], true)['title']);
    }

    public function test_the_real_client_can_be_built_with_real_vapid_keys(): void
    {
        // Tes lain memakai klien palsu, jadi dulu tidak menangkap salah argumen konstruktor WebPush (v11: argumen ketiga = klien HTTP, bukan
        // batas waktu). Di sini klien nyata dibuat dengan pasangan kunci VAPID yang sah. Pasangan ini khusus tes (dibuat sekali, tidak dipakai di
        // mana pun); kunci tidak dibuat ulang tiap tes karena pembuatan kunci EC butuh konfigurasi OpenSSL yang tidak selalu ada di Windows.
        config([
            'services.webpush.public_key' => 'BCMVEiBfNiUktC5Rrsam2IAovyJGhjifJHrzCMkMWQeZTWnJAoyYFUCCTkhFiuKz09R5X12kj6U5LfUnie-v9L4',
            'services.webpush.private_key' => 'sUo6Gx4Hph1NpdIBYT2p5wylAxr8uCOpSOzjrGmxSzQ',
        ]);

        $client = (new \ReflectionMethod(WebPushService::class, 'client'))->invoke(new WebPushService());

        $this->assertInstanceOf(WebPush::class, $client);
    }

    public function test_the_settings_page_opens_for_any_panel_user_and_lists_my_devices(): void
    {
        $user = $this->user();
        $this->subscription($user, 'https://push.example.test/a1');
        WebPushSubscription::where('user_id', $user->id)->update(['user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)']);
        $this->actingAs($user, 'web');

        $this->assertTrue(NotifikasiHp::canAccess());

        Livewire::test(NotifikasiHp::class)
            ->assertSuccessful()
            ->assertSee('Status perangkat ini')
            ->assertSee('iPhone')
            ->assertSee('Aktifkan notifikasi');
    }

    public function test_the_panel_pages_carry_the_manifest_and_the_static_pwa_files_exist(): void
    {
        $login = $this->get('/admin/login')->assertSuccessful();
        $login->assertSee('rel="manifest"', false)->assertSee('apple-touch-icon', false)->assertSee("register('/sw.js'", false);

        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/admin', $manifest['start_url']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }

        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('push'", (string) file_get_contents(public_path('sw.js')));
        $this->assertFileExists(public_path('images/pwa/apple-touch-icon.png'));
    }
}
