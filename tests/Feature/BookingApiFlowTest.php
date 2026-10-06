<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Alur Booking lewat endpoint HTTP mobile (staff & customer): konfirmasi,
 * QC wajib sebelum selesai, urutan tahap, koreksi tahap, akses menu, dan
 * pengajuan pembatalan customer (audit alur Booking ronde 6-8).
 */
class BookingApiFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
        Http::fake(); // push Expo tidak boleh keluar dari test

        foreach (['store_manager', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
    }

    private function staff(string $role, ?array $menuAccess = null): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => 'x',
            'store_id' => $this->store->id,
            'menu_access' => $menuAccess,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(array $overrides = []): Booking
    {
        $customer = $overrides['_customer'] ?? Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        unset($overrides['_customer']);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id'    => $customer->id,
            'store_id'       => $this->store->id,
            'service_type'   => 'Pelindung Cat (PPF)',
            'product_ppf'    => true,
            'preferred_date' => now()->addDays(5)->toDateString(),
            'status'         => 'confirmed',
        ], $overrides));
    }

    public function test_store_manager_can_confirm_pending_booking(): void
    {
        $booking = $this->booking(['status' => 'pending']);

        $this->actingAs($this->staff('store_manager'), 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/confirm")
            ->assertSuccessful();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_complete_is_rejected_until_quality_check_is_marked(): void
    {
        $manager = $this->staff('store_manager');
        $booking = $this->booking(['current_stage' => 'ppf_installation']);

        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/complete")
            ->assertStatus(422);
        $this->assertSame('confirmed', $booking->fresh()->status);

        $booking->update(['current_stage' => 'qc']);

        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/complete")
            ->assertSuccessful();
        $this->assertSame('completed', $booking->fresh()->status);
    }

    public function test_stage_order_is_forward_only(): void
    {
        $manager = $this->staff('store_manager');
        $booking = $this->booking();

        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'stage', 'stage' => 'ppf_detailing'])
            ->assertStatus(201);
        $this->assertSame('ppf_detailing', $booking->fresh()->current_stage);

        // Sama / mundur ditolak.
        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'stage', 'stage' => 'ppf_detailing'])
            ->assertStatus(422);
        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'stage', 'stage' => 'ppf_washing'])
            ->assertStatus(422);

        // Maju diterima.
        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'stage', 'stage' => 'ppf_installation'])
            ->assertStatus(201);
    }

    public function test_stage_of_other_product_is_rejected(): void
    {
        $booking = $this->booking(); // PPF saja

        $this->actingAs($this->staff('store_manager'), 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'stage', 'stage' => 'kf_cleaning'])
            ->assertStatus(422);
    }

    public function test_only_store_manager_can_correct_stage_and_reason_is_required(): void
    {
        $booking = $this->booking(['current_stage' => 'ppf_installation']);

        $this->actingAs($this->staff('kasir'), 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/stage-correction", ['stage' => 'ppf_washing', 'reason' => 'salah tandai'])
            ->assertStatus(403);

        $manager = $this->staff('store_manager');

        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/stage-correction", ['stage' => 'ppf_washing'])
            ->assertStatus(422);

        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/stage-correction", ['stage' => 'ppf_washing', 'reason' => 'salah tandai'])
            ->assertSuccessful();
        $this->assertSame('ppf_washing', $booking->fresh()->current_stage);

        $this->actingAs($manager, 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/stage-correction", ['stage' => 'reset_main', 'reason' => 'belum mulai'])
            ->assertSuccessful();
        $this->assertNull($booking->fresh()->current_stage);
    }

    public function test_staff_without_booking_menu_access_is_forbidden(): void
    {
        $booking = $this->booking();
        $noBooking = $this->staff('kasir', ['SomeOtherResource']);

        $this->actingAs($noBooking, 'api')->getJson('/api/staff/bookings')->assertStatus(403);
        $this->actingAs($noBooking, 'api')->getJson("/api/staff/bookings/{$booking->id}")->assertStatus(403);
        $this->actingAs($noBooking, 'api')->getJson("/api/staff/bookings/{$booking->id}/messages")->assertStatus(403);
    }

    public function test_staff_with_booking_menu_access_can_list_bookings(): void
    {
        $this->booking();

        $this->actingAs($this->staff('kasir'), 'api')
            ->getJson('/api/staff/bookings')
            ->assertSuccessful()
            ->assertJsonPath('success', true);
    }

    public function test_customer_cancellation_request_is_decided_by_store_manager_only(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = $this->booking(['_customer' => $customer]);

        $this->actingAs($customer, 'customer')
            ->postJson("/api/customer/bookings/{$booking->id}/cancellation-request", ['reason' => 'Berhalangan hadir'])
            ->assertSuccessful();

        $request = BookingCancellationRequest::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('pending', $request->status);

        // Staf biasa ditolak.
        $this->actingAs($this->staff('kasir'), 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/cancellation-request/{$request->id}/approve")
            ->assertStatus(403);
        $this->assertSame('confirmed', $booking->fresh()->status);

        // Store Manager menyetujui -> booking batal dengan alasan customer.
        $this->actingAs($this->staff('store_manager'), 'api')
            ->postJson("/api/staff/bookings/{$booking->id}/cancellation-request/{$request->id}/approve")
            ->assertSuccessful();

        $fresh = $booking->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('Berhalangan hadir', $fresh->cancel_reason);
        $this->assertSame('customer', $fresh->cancelled_by_type);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_customer_cannot_request_cancellation_after_booking_date_passed(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = $this->booking(['_customer' => $customer, 'preferred_date' => now()->subDay()->toDateString()]);

        $this->actingAs($customer, 'customer')
            ->postJson("/api/customer/bookings/{$booking->id}/cancellation-request", ['reason' => 'Berhalangan hadir'])
            ->assertStatus(422);
    }

    public function test_customer_cannot_reschedule_while_cancellation_is_pending(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = $this->booking(['_customer' => $customer]);

        $this->actingAs($customer, 'customer')
            ->postJson("/api/customer/bookings/{$booking->id}/cancellation-request", ['reason' => 'Berhalangan hadir'])
            ->assertSuccessful();

        $this->actingAs($customer, 'customer')
            ->postJson("/api/customer/bookings/{$booking->id}/reschedule-request", ['requested_date' => now()->addDays(9)->toDateString()])
            ->assertStatus(422);
    }

    public function test_customer_booking_list_hides_internal_columns_and_supports_segments(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $this->booking(['_customer' => $customer, 'status' => 'confirmed']);
        $this->booking(['_customer' => $customer, 'status' => 'pending']);
        $done = $this->booking(['_customer' => $customer, 'status' => 'confirmed']);
        $done->update(['current_stage' => 'qc']);
        $done->update(['status' => 'completed']);

        $active = $this->actingAs($customer, 'customer')->getJson('/api/customer/bookings?segment=active')->assertSuccessful();
        $this->assertCount(2, $active->json('data'));

        $history = $this->actingAs($customer, 'customer')->getJson('/api/customer/bookings?segment=history')->assertSuccessful();
        $this->assertCount(1, $history->json('data'));

        $first = $active->json('data.0');
        foreach (['journal_entry_id', 'partner_id', 'amount_received', 'dpp_amount', 'ppn_amount', 'h1_reminder_sent_at'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $first, "Kolom internal {$hidden} bocor ke API customer.");
        }
        $this->assertArrayNotHasKey('late_deduction_amount', $first['store'] ?? []);
    }
}
