<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StaffNotifier;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Notifikasi Fase 2 (2026-10-21): API kotak masuk notifikasi staf untuk aplikasi mobile.
 */
class StaffInboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
    }

    private function user(string $name = 'Rina'): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('kasir'));
    }

    private function send(User $user, string $title, string $body = 'Isi', array $data = [], ?string $key = null): void
    {
        app(StaffNotifier::class)->toUsers([$user->id], $title, $body, $data, $key);
    }

    public function test_it_requires_login(): void
    {
        $this->getJson('/api/staff/inbox')->assertStatus(401);
        $this->getJson('/api/staff/inbox/unread-count')->assertStatus(401);
        $this->postJson('/api/staff/inbox/read-all')->assertStatus(401);
    }

    public function test_the_list_returns_only_my_notifications_newest_first_with_the_deep_link(): void
    {
        $me = $this->user();
        $other = $this->user('Lain');
        $this->send($me, 'Pertama');
        $this->send($other, 'Milik orang lain');
        $this->travel(1)->minutes();
        $this->send($me, 'Kedua', 'Isi kedua', ['route' => '/staff/attendance', 'params' => ['id' => '5'], 'type' => 'leave']);

        $response = $this->actingAs($me, 'api')->getJson('/api/staff/inbox')->assertSuccessful();

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertSame(['Kedua', 'Pertama'], $titles);
        $first = $response->json('data.0');
        $this->assertSame('/staff/attendance', $first['route']);
        $this->assertSame(['id' => '5'], $first['params']);
        $this->assertSame('leave', $first['type']);
        $this->assertSame('staff_inbox', $first['source']);
        $this->assertNull($first['read_at']);
        $this->assertSame(2, $response->json('unread_count'));
        $this->assertFalse($response->json('has_more'));
    }

    public function test_panel_notifications_are_included_with_the_panel_source(): void
    {
        $me = $this->user();
        FilamentNotification::make()->title('Dari panel')->body('Menunggu persetujuan Anda')->warning()->sendToDatabase($me);

        $item = $this->actingAs($me, 'api')->getJson('/api/staff/inbox')->assertSuccessful()->json('data.0');

        $this->assertSame('Dari panel', $item['title']);
        $this->assertSame('panel', $item['source']);
        $this->assertNull($item['route']);
    }

    public function test_unread_filter_count_and_mark_read(): void
    {
        $me = $this->user();
        $this->send($me, 'A');
        $this->send($me, 'B');
        $id = $me->notifications()->where('data->title', 'A')->first()->id;

        $this->actingAs($me, 'api')->getJson('/api/staff/inbox/unread-count')->assertJsonPath('data.unread_count', 2);

        $this->actingAs($me, 'api')->postJson("/api/staff/inbox/{$id}/read")
            ->assertSuccessful()
            ->assertJsonPath('unread_count', 1);

        $unread = $this->actingAs($me, 'api')->getJson('/api/staff/inbox?unread=1')->json('data');
        $this->assertSame(['B'], collect($unread)->pluck('title')->all());

        $all = $this->actingAs($me, 'api')->getJson('/api/staff/inbox')->json('data');
        $this->assertNotNull(collect($all)->firstWhere('title', 'A')['read_at']);
    }

    public function test_mark_all_read_only_touches_my_notifications(): void
    {
        $me = $this->user();
        $other = $this->user('Lain');
        $this->send($me, 'A');
        $this->send($me, 'B');
        $this->send($other, 'C');

        $this->actingAs($me, 'api')->postJson('/api/staff/inbox/read-all')->assertSuccessful()->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $me->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_another_users_notification_cannot_be_read_or_discovered(): void
    {
        $me = $this->user();
        $other = $this->user('Lain');
        $this->send($other, 'Rahasia');
        $id = $other->notifications()->first()->id;

        $this->actingAs($me, 'api')->postJson("/api/staff/inbox/{$id}/read")->assertStatus(404);
        $this->assertNull($other->notifications()->first()->read_at);
    }

    public function test_a_coalesced_message_appears_once_and_the_list_paginates(): void
    {
        $me = $this->user();
        $this->send($me, 'Pesan baru', 'Pesan 1', [], 'chat:1');
        $this->send($me, 'Pesan baru', 'Pesan 2', [], 'chat:1');

        $this->assertCount(1, $this->actingAs($me, 'api')->getJson('/api/staff/inbox')->json('data'));

        foreach (range(1, 31) as $i) {
            $this->send($me, "Judul {$i}");
        }

        $page1 = $this->actingAs($me, 'api')->getJson('/api/staff/inbox')->json();
        $this->assertCount(30, $page1['data']);
        $this->assertTrue($page1['has_more']);

        $page2 = $this->actingAs($me, 'api')->getJson('/api/staff/inbox?page=2')->json();
        $this->assertCount(2, $page2['data']);
        $this->assertFalse($page2['has_more']);
    }
}
