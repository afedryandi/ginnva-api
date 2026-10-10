<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chat booking grup customer + tim Ginnva (keputusan 2026-10-10): leader installer ikut chat (otomatis semua booking di
 * tokonya), installer TIDAK ikut chat tapi tetap tercatat sebagai pengerja. Role ppf_leader diganti installer_leader.
 */
class InstallerLeaderChatTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        Http::fake();

        foreach (['store_manager', 'installer', 'installer_leader'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function staff(string $role, ?Store $store = null): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        return Booking::create([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->store->id, 'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true, 'preferred_date' => now()->addDays(5)->toDateString(), 'status' => 'confirmed',
        ]);
    }

    public function test_the_installer_leader_joins_the_chat_of_every_booking_in_their_store(): void
    {
        $leader = $this->staff('installer_leader');
        $booking = $this->booking();

        $this->actingAs($leader, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->assertSuccessful();

        $this->actingAs($leader, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Mobil sudah masuk bay.'])->assertStatus(201);
        $this->actingAs($leader, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'stage', 'stage' => 'ppf_detailing'])->assertStatus(201);
        $this->assertSame('ppf_detailing', $booking->fresh()->current_stage);

        $senders = $this->actingAs($leader, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->json('data.messages.*.sender_name');
        $this->assertSame(['Leader Installer', 'Leader Installer'], $senders);
    }

    public function test_the_customer_sees_the_leader_label_not_a_personal_name(): void
    {
        $leader = $this->staff('installer_leader');
        $booking = $this->booking();
        $this->actingAs($leader, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Halo'])->assertStatus(201);

        $names = $this->actingAs($booking->customer, 'customer')->getJson("/api/customer/bookings/{$booking->id}/messages")->assertSuccessful()->json('data.messages.*.sender_name');

        $this->assertSame(['Leader Installer'], $names);
    }

    public function test_a_leader_of_another_store_is_refused(): void
    {
        $booking = $this->booking();
        $outsider = $this->staff('installer_leader', $this->otherStore);

        $this->actingAs($outsider, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->assertStatus(403);
        $this->actingAs($outsider, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Halo'])->assertStatus(403);
    }

    public function test_assigned_installers_no_longer_have_chat_access_but_are_recorded(): void
    {
        $installer = $this->staff('installer');
        $booking = $this->booking();
        $booking->installers()->attach($installer->id);

        $this->actingAs($installer, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->assertStatus(403);
        $this->actingAs($installer, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Halo'])->assertStatus(403);
        $this->assertSame(0, $booking->messages()->count());

        // Pengerja tetap tercatat dan terlihat oleh leader di chat.
        $this->assertSame([$installer->id], $booking->installers()->pluck('users.id')->all());
        $leader = $this->staff('installer_leader');
        $this->actingAs($leader, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")
            ->assertSuccessful()
            ->assertJsonPath('data.installers', [$installer->name]);
    }

    public function test_customer_messages_notify_the_leader_but_not_the_installers(): void
    {
        $leader = $this->staff('installer_leader');
        $installer = $this->staff('installer');
        $booking = $this->booking();
        $booking->installers()->attach($installer->id);

        $captured = [];
        $this->partialMock(PushNotificationService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function ($ids) use (&$captured) {
                $captured = collect($ids)->all();
            });
        });

        $this->actingAs($booking->customer, 'customer')->postJson("/api/customer/bookings/{$booking->id}/messages", ['body' => 'Kapan selesai?'])->assertStatus(201);

        $this->assertContains($leader->id, $captured);
        $this->assertNotContains($installer->id, $captured);
    }

    public function test_the_ppf_leader_role_is_renamed_keeping_its_users(): void
    {
        $old = Role::create(['name' => 'ppf_leader', 'guard_name' => 'web']);
        Role::where('name', 'installer_leader')->delete();
        $user = User::create(['name' => 'Lama', 'email' => 'lama@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $user->assignRole('ppf_leader');

        (include database_path('migrations/2026_10_10_000001_replace_ppf_leader_with_installer_leader_role.php'))->up();

        $this->assertNull(Role::where('name', 'ppf_leader')->first());
        $this->assertTrue($user->fresh()->hasRole('installer_leader'));
        $this->assertSame($old->id, Role::where('name', 'installer_leader')->value('id'), 'Role diganti nama, bukan dibuat ulang: penugasan lama utuh.');
    }

    public function test_the_migration_merges_when_both_roles_already_exist(): void
    {
        $old = Role::create(['name' => 'ppf_leader', 'guard_name' => 'web']);
        $new = Role::where('name', 'installer_leader')->firstOrFail();
        $user = User::create(['name' => 'Lama', 'email' => 'lama@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        DB::table('model_has_roles')->insert(['role_id' => $old->id, 'model_type' => User::class, 'model_id' => $user->id]);

        (include database_path('migrations/2026_10_10_000001_replace_ppf_leader_with_installer_leader_role.php'))->up();

        $this->assertNull(Role::where('name', 'ppf_leader')->first());
        $this->assertSame(1, DB::table('model_has_roles')->where('role_id', $new->id)->where('model_id', $user->id)->count());
    }
}
