<?php

namespace Tests\Feature;

use App\Filament\Resources\NotificationDeliveryResource\Pages\ListNotificationDeliveries;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Notifikasi Fase 3 (2026-10-21): catatan pengiriman push per token + pemeriksaan kuitansi Expo.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SEND = 'exp.host/--/api/v2/push/send';
    private const RECEIPTS = 'exp.host/--/api/v2/push/getReceipts';

    private function user(string $name = 'Rina'): User
    {
        return User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
    }

    private function token(User $user, string $token): DeviceToken
    {
        return DeviceToken::create(['user_id' => $user->id, 'token' => $token, 'platform' => 'android']);
    }

    private function delivery(string $ticket, string $token, array $overrides = []): NotificationDelivery
    {
        return NotificationDelivery::create(array_merge([
            'token' => $token, 'title' => 'Judul', 'ticket_id' => $ticket, 'status' => NotificationDelivery::ACCEPTED, 'sent_at' => now()->subMinutes(20),
        ], $overrides));
    }

    public function test_a_send_records_one_accepted_row_per_token_with_the_ticket_and_owner(): void
    {
        $user = $this->user();
        $this->token($user, 'ExponentPushToken[a]');
        $this->token($user, 'ExponentPushToken[b]');
        Http::fake([self::SEND => Http::response(['data' => [['status' => 'ok', 'id' => 'T-A'], ['status' => 'ok', 'id' => 'T-B']]])]);

        app(PushNotificationService::class)->sendToUsers([$user->id], 'Halo', 'Isi');

        $rows = NotificationDelivery::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['T-A', 'T-B'], $rows->pluck('ticket_id')->all());
        $this->assertSame([NotificationDelivery::ACCEPTED], $rows->pluck('status')->unique()->all());
        $this->assertSame([$user->id], $rows->pluck('user_id')->unique()->all());
        $this->assertSame('Halo', $rows->first()->title);
    }

    public function test_a_rejected_ticket_with_device_not_registered_removes_the_token_and_is_logged_as_dead(): void
    {
        $user = $this->user();
        $this->token($user, 'ExponentPushToken[dead]');
        Http::fake([self::SEND => Http::response(['data' => [['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']]]])]);

        app(PushNotificationService::class)->sendToUsers([$user->id], 'Halo', 'Isi');

        $this->assertSame(0, DeviceToken::count());
        $row = NotificationDelivery::first();
        $this->assertSame(NotificationDelivery::DEAD_TOKEN, $row->status);
        $this->assertSame($user->id, $row->user_id);
    }

    public function test_other_ticket_errors_and_http_failures_are_logged_as_failed(): void
    {
        $user = $this->user();
        $this->token($user, 'ExponentPushToken[x]');

        // Stub Http::fake pertama menang, jadi dua balasan berurutan lewat satu urutan.
        Http::fake([self::SEND => Http::sequence()
            ->push(['data' => [['status' => 'error', 'message' => 'MessageTooBig', 'details' => ['error' => 'MessageTooBig']]]])
            ->push(['errors' => [['code' => 'SERVER']]], 500)]);
        app(PushNotificationService::class)->sendToUsers([$user->id], 'Halo', 'Isi');
        app(PushNotificationService::class)->sendToUsers([$user->id], 'Halo', 'Isi');

        $rows = NotificationDelivery::orderBy('id')->get();
        $this->assertSame([NotificationDelivery::FAILED, NotificationDelivery::FAILED], $rows->pluck('status')->all());
        $this->assertSame('MessageTooBig', $rows[0]->error);
        $this->assertSame('HTTP 500', $rows[1]->error);
        $this->assertSame(1, DeviceToken::count(), 'Kegagalan biasa tidak menghapus token.');
    }

    public function test_the_receipt_check_marks_delivered_dead_unknown_and_leaves_the_rest_waiting(): void
    {
        $user = $this->user();
        $this->token($user, 'ExponentPushToken[dead]');
        $ok = $this->delivery('T-OK', 'ExponentPushToken[ok]');
        $dead = $this->delivery('T-DEAD', 'ExponentPushToken[dead]');
        $bad = $this->delivery('T-BAD', 'ExponentPushToken[bad]');
        $missing = $this->delivery('T-MISSING', 'ExponentPushToken[missing]');
        $expired = $this->delivery('T-EXPIRED', 'ExponentPushToken[expired]', ['sent_at' => now()->subHours(24)]);
        $recent = $this->delivery('T-RECENT', 'ExponentPushToken[recent]', ['sent_at' => now()->subMinutes(5)]);

        Http::fake([self::RECEIPTS => Http::response(['data' => [
            'T-OK'   => ['status' => 'ok'],
            'T-DEAD' => ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']],
            'T-BAD'  => ['status' => 'error', 'message' => 'Kredensial FCM salah', 'details' => ['error' => 'InvalidCredentials']],
        ]])]);

        $this->artisan('notifications:check-receipts')->assertSuccessful();

        $this->assertSame(NotificationDelivery::DELIVERED, $ok->fresh()->status);
        $this->assertNotNull($ok->fresh()->checked_at);
        $this->assertSame(NotificationDelivery::DEAD_TOKEN, $dead->fresh()->status);
        $this->assertSame(0, DeviceToken::where('token', 'ExponentPushToken[dead]')->count());
        $this->assertSame(NotificationDelivery::FAILED, $bad->fresh()->status);
        $this->assertSame('Kredensial FCM salah', $bad->fresh()->error);
        $this->assertSame(NotificationDelivery::ACCEPTED, $missing->fresh()->status, 'Kuitansi belum tersedia: dicoba lagi nanti.');
        $this->assertSame(NotificationDelivery::UNKNOWN, $expired->fresh()->status, 'Lewat 23 jam tanpa kuitansi.');
        $this->assertSame(NotificationDelivery::ACCEPTED, $recent->fresh()->status);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'getReceipts') && ! in_array('T-RECENT', $r['ids'], true));
    }

    public function test_the_receipt_check_survives_an_expo_outage_and_ignores_rows_without_a_ticket(): void
    {
        $waiting = $this->delivery('T-1', 'ExponentPushToken[a]');
        $noTicket = $this->delivery('x', 'ExponentPushToken[b]', ['ticket_id' => null]);
        Http::fake([self::RECEIPTS => Http::response('boom', 503)]);

        $this->artisan('notifications:check-receipts')->assertSuccessful();

        $this->assertSame(NotificationDelivery::ACCEPTED, $waiting->fresh()->status);
        $this->assertSame(NotificationDelivery::ACCEPTED, $noTicket->fresh()->status);
    }

    public function test_the_prune_command_removes_only_old_delivery_rows(): void
    {
        $old = $this->delivery('T-OLD', 'ExponentPushToken[old]', ['sent_at' => now()->subDays(91)]);
        $new = $this->delivery('T-NEW', 'ExponentPushToken[new]', ['sent_at' => now()->subDays(10)]);

        $this->artisan('notifications:prune-old')->assertSuccessful();

        $this->assertNull(NotificationDelivery::find($old->id));
        $this->assertNotNull(NotificationDelivery::find($new->id));
    }

    public function test_the_delivery_log_page_lists_rows_and_summarises_the_last_day(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = tap($this->user('Admin'), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');

        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $this->delivery('T-1', 'ExponentPushToken[a]', ['status' => NotificationDelivery::DELIVERED, 'user_id' => $admin->id, 'title' => 'Booking Baru']);
        $this->delivery('T-2', 'ExponentPushToken[b]', ['status' => NotificationDelivery::FAILED, 'customer_id' => $customer->id, 'title' => 'Poin Bertambah']);
        $this->delivery('T-3', 'ExponentPushToken[c]', ['status' => NotificationDelivery::DEAD_TOKEN, 'title' => 'Lama', 'sent_at' => now()->subDays(3)]);

        $component = Livewire::test(ListNotificationDeliveries::class)
            ->assertSuccessful()
            ->assertSee('Booking Baru')
            ->assertSee('Budi')
            ->assertSee('Admin');

        $this->assertStringContainsString('1 terkirim, 1 gagal, 0 token mati, 0 menunggu kuitansi (50% terkirim)', $component->instance()->getSubheading());
    }
}
