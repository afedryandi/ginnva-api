<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Izin chat per installer (2026-12-15): installer bawaannya tidak ikut chat booking; store manager/akses penuh bisa
 * mengizinkan installer yang ditugaskan lewat Assignment (booking_installers.can_chat). Yang diizinkan boleh teks dan tahap.
 */
class BookingInstallerChatTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function user(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::create(['name' => ucfirst($role) . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(): Booking
    {
        return Booking::create([
            'booking_number' => 'BKG-IC-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->addDay()->toDateString(),
            'status' => 'confirmed',
        ]);
    }

    public function test_installer_has_no_chat_access_by_default(): void
    {
        $installer = $this->user('installer');
        $booking = $this->booking();
        $booking->installers()->attach($installer->id);

        $this->actingAs($installer, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->assertStatus(403);
        $this->actingAs($installer, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Halo'])->assertStatus(403);
    }

    public function test_assignment_can_grant_chat_only_to_assigned_installers(): void
    {
        $manager = $this->user('store_manager');
        $a = $this->user('installer');
        $b = $this->user('installer');
        $booking = $this->booking();

        $this->actingAs($manager, 'api')->putJson("/api/staff/bookings/{$booking->id}/installers", [
            'installer_user_ids' => [$a->id, $b->id],
            'chat_installer_user_ids' => [$a->id],
        ])->assertOk();

        $this->assertTrue((bool) $booking->installers()->where('users.id', $a->id)->first()->pivot->can_chat);
        $this->assertFalse((bool) $booking->installers()->where('users.id', $b->id)->first()->pivot->can_chat);

        // Tidak mengirim daftar chat: izin yang sudah ada dipertahankan.
        $this->actingAs($manager, 'api')->putJson("/api/staff/bookings/{$booking->id}/installers", ['installer_user_ids' => [$a->id, $b->id]])->assertOk();
        $this->assertTrue((bool) $booking->installers()->where('users.id', $a->id)->first()->pivot->can_chat);

        // Id di luar penugasan diabaikan.
        $outsider = $this->user('installer');
        $this->actingAs($manager, 'api')->putJson("/api/staff/bookings/{$booking->id}/installers", [
            'installer_user_ids' => [$a->id],
            'chat_installer_user_ids' => [$outsider->id],
        ])->assertOk();
        $this->assertFalse((bool) $booking->installers()->where('users.id', $a->id)->first()->pivot->can_chat);
    }

    public function test_permitted_installer_can_read_and_send_text_and_stage(): void
    {
        $manager = $this->user('store_manager');
        $allowed = $this->user('installer');
        $other = $this->user('installer');
        $booking = $this->booking();

        $this->actingAs($manager, 'api')->putJson("/api/staff/bookings/{$booking->id}/installers", [
            'installer_user_ids' => [$allowed->id, $other->id],
            'chat_installer_user_ids' => [$allowed->id],
        ])->assertOk();

        $this->actingAs($allowed, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->assertOk();
        $this->actingAs($allowed, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Sedang dipasang'])->assertCreated();
        $this->actingAs($allowed, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'stage', 'stage' => 'ppf_washing'])->assertCreated();
        $this->assertSame('ppf_washing', $booking->fresh()->current_stage);

        // Installer lain di booking yang sama, tanpa izin, tetap ditolak.
        $this->actingAs($other, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->assertStatus(403);

        // Installer berizin di booking lain (tidak ditugaskan) tidak bisa masuk.
        $elsewhere = $this->booking();
        $this->actingAs($allowed, 'api')->getJson("/api/staff/bookings/{$elsewhere->id}/messages")->assertStatus(403);
    }

    public function test_installer_booking_detail_reports_own_chat_permission(): void
    {
        $manager = $this->user('store_manager');
        $installer = $this->user('installer');
        $booking = $this->booking();

        $this->actingAs($manager, 'api')->putJson("/api/staff/bookings/{$booking->id}/installers", [
            'installer_user_ids' => [$installer->id],
            'chat_installer_user_ids' => [$installer->id],
        ])->assertOk();

        $this->actingAs($installer, 'api')->getJson("/api/staff/bookings/{$booking->id}")
            ->assertOk()->assertJsonPath('data.installers.0.can_chat', true);
    }
}
