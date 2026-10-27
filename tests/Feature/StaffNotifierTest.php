<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Store;
use App\Models\User;
use App\Notifications\StaffInboxNotification;
use App\Services\StaffNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Notifikasi Fase 1 (2026-10-21): satu jalur kirim untuk staf. Setiap pesan = satu baris kotak masuk (tabel notifications) + push.
 */
class StaffNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200)]);
        Role::findOrCreate('super_admin', 'web');
    }

    private function user(string $name = 'Rina', bool $withToken = true): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);

        if ($withToken) {
            DeviceToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[' . uniqid() . ']', 'platform' => 'android']);
        }

        return $user;
    }

    private function pushed(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'exp.host'))->count();
    }

    public function test_one_call_creates_an_inbox_row_and_sends_the_push(): void
    {
        $user = $this->user();

        app(StaffNotifier::class)->toUsers([$user->id], 'Pengajuan Izin Disetujui', 'Pengajuan IZN-1 disetujui.', ['route' => '/staff/attendance']);

        $row = $user->notifications()->first();
        $this->assertNotNull($row);
        $this->assertSame(StaffInboxNotification::class, $row->type);
        $this->assertSame('filament', $row->data['format']);
        $this->assertSame('Pengajuan Izin Disetujui', $row->data['title']);
        $this->assertSame('Pengajuan IZN-1 disetujui.', $row->data['body']);
        $this->assertSame('/staff/attendance', $row->data['viewData']['payload']['route']);
        $this->assertNull($row->read_at);
        $this->assertSame(1, $this->pushed());
    }

    public function test_a_user_without_a_push_token_still_gets_the_inbox_row(): void
    {
        $user = $this->user('Tanpa Token', false);

        app(StaffNotifier::class)->toUsers([$user->id], 'Judul', 'Isi');

        $this->assertSame(1, $user->notifications()->count(), 'Pesan tidak hilang walau push tidak mungkin terkirim.');
        $this->assertSame(0, $this->pushed());
    }

    public function test_duplicates_and_empty_lists_are_handled(): void
    {
        $user = $this->user();
        $notifier = app(StaffNotifier::class);

        $notifier->toUsers([$user->id, $user->id, null, ''], 'Judul', 'Isi');
        $this->assertSame(1, $user->notifications()->count());

        $notifier->toUsers([], 'Judul', 'Isi');
        $notifier->toUsers([null], 'Judul', 'Isi');
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $this->pushed());
    }

    public function test_unknown_user_ids_neither_fail_nor_create_rows(): void
    {
        app(StaffNotifier::class)->toUsers([999999], 'Judul', 'Isi');

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('notifications')->count());
    }

    public function test_a_coalesce_key_updates_the_unread_row_instead_of_adding_one(): void
    {
        $user = $this->user();
        $notifier = app(StaffNotifier::class);

        $notifier->toUsers([$user->id], 'Pesan baru', 'Pesan 1', [], 'booking-chat:7');
        $notifier->toUsers([$user->id], 'Pesan baru', 'Pesan 2', [], 'booking-chat:7');
        $notifier->toUsers([$user->id], 'Pesan baru', 'Pesan di booking lain', [], 'booking-chat:8');

        $this->assertSame(2, $user->notifications()->count());
        $this->assertSame('Pesan 2', $user->notifications()->get()->firstWhere(fn ($n) => ($n->data['viewData']['coalesce_key'] ?? null) === 'booking-chat:7')->data['body']);
        $this->assertSame(3, $this->pushed(), 'Push tetap dikirim tiap pesan; hanya kotak masuk yang digabung.');

        // Setelah dibaca, pesan berikutnya membuat baris baru.
        $user->notifications()->get()->each->markAsRead();
        $notifier->toUsers([$user->id], 'Pesan baru', 'Pesan 3', [], 'booking-chat:7');
        $this->assertSame(3, $user->notifications()->count());
    }

    public function test_store_staff_reach_includes_super_admins_with_an_inbox_row(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $admin = $this->user('Admin');
        $admin->assignRole('super_admin');

        app(StaffNotifier::class)->toStoreStaff($store->id, 'Booking Baru', 'Ada booking baru.');

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $this->pushed());
    }

    public function test_a_new_booking_reaches_the_store_staff_inbox_and_customer_chat_messages_are_coalesced(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $admin = $this->user('Admin');
        $admin->assignRole('super_admin');
        $customer = \App\Models\Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        $booking = \App\Models\Booking::create([
            'booking_number' => 'BKG-T-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'preferred_date' => now()->addDay()->toDateString(), 'status' => 'pending',
        ]);

        $this->assertTrue($admin->notifications()->get()->contains(fn ($n) => $n->data['title'] === 'Booking Baru'), 'Booking baru masuk kotak masuk staff.');

        foreach (['Halo', 'Apakah bisa besok?'] as $text) {
            \App\Models\BookingMessage::create(['booking_id' => $booking->id, 'sender_type' => 'customer', 'sender_customer_id' => $customer->id, 'type' => 'text', 'body' => $text]);
        }

        $chat = $admin->notifications()->get()->filter(fn ($n) => $n->data['title'] === 'Pesan Baru dari Customer');
        $this->assertCount(1, $chat, 'Dua pesan di booking yang sama menjadi satu baris kotak masuk.');
        $this->assertSame('Apakah bisa besok?', $chat->first()->data['body']);
    }

    public function test_the_inbox_row_is_readable_by_the_filament_bell(): void
    {
        $user = $this->user();
        app(StaffNotifier::class)->toUsers([$user->id], 'Judul', 'Isi');

        $notification = \Filament\Notifications\Notification::fromDatabase($user->notifications()->first());

        $this->assertSame('Judul', $notification->getTitle());
        $this->assertSame('Isi', $notification->getBody());
    }
}
