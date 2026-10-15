<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Serah terima unit (Fase 3B pengerjaan ulang booking, 2026-10-18): petunjuk perawatan wajib, tanda tangan BASAH wajib untuk
 * layanan berat (PPF/kaca film) dan opsional untuk layanan ringan, foto akhir opsional, sekali per booking.
 */
class BookingHandoverTest extends TestCase
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
        $user = User::create(['name' => ucfirst($role), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-HO-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->addDay()->toDateString(),
            'status' => 'confirmed',
            'current_stage' => 'qc',
        ], $overrides));
    }

    private function light(): Booking
    {
        return $this->booking(['service_type' => 'Premium Wash', 'product_ppf' => false, 'product_premium_wash' => true, 'current_stage' => null]);
    }

    public function test_heavy_services_need_the_wet_signature(): void
    {
        $booking = $this->booking();
        $manager = $this->user('store_manager');

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])
            ->assertStatus(422);
        $this->assertNull($booking->fresh()->handover);

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => true])
            ->assertCreated();

        $handover = $booking->fresh()->handover;
        $this->assertTrue($handover->wet_signature_required);
        $this->assertTrue($handover->wet_signature_received);
        $this->assertSame($manager->id, $handover->recorded_by_user_id);
    }

    public function test_light_services_do_not_need_the_signature(): void
    {
        $booking = $this->light();
        $manager = $this->user('store_manager');

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])
            ->assertCreated();

        $handover = $booking->fresh()->handover;
        $this->assertFalse($handover->wet_signature_required);
        $this->assertFalse($handover->wet_signature_received);
    }

    public function test_care_instructions_are_always_required(): void
    {
        $booking = $this->light();
        $manager = $this->user('store_manager');

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => false, 'wet_signature_received' => true])
            ->assertStatus(422);
    }

    public function test_final_photo_is_stored_and_returned(): void
    {
        $booking = $this->light();
        $manager = $this->user('store_manager');

        $this->actingAs($manager, 'api')->post("/api/staff/bookings/{$booking->id}/handover", [
            'care_instructions_given' => '1',
            'wet_signature_received' => '0',
            'photo' => UploadedFile::fake()->image('akhir.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $booking->fresh()->handover->final_photo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($manager, 'api')->getJson("/api/staff/bookings/{$booking->id}/handover")
            ->assertOk()->assertJsonPath('data.handover.care_instructions_given', true)->assertJsonPath('data.can_record', false);
    }

    public function test_handover_is_recorded_only_once_and_only_for_confirmed_bookings(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->light();

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])->assertCreated();
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])->assertStatus(422);

        $pending = $this->booking(['status' => 'pending']);
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$pending->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => true])->assertStatus(422);
    }

    public function test_installers_and_other_stores_cannot_record(): void
    {
        $booking = $this->light();

        $this->actingAs($this->user('installer'), 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])->assertStatus(403);

        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $outsider = $this->user('store_manager');
        $outsider->update(['store_id' => $otherStore->id]);
        $this->actingAs($outsider, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])->assertStatus(404);
    }

    public function test_when_qc_is_required_the_unit_must_have_passed_qc_first(): void
    {
        config(['booking.qc_required' => true]);
        $manager = $this->user('store_manager');
        $booking = $this->light();

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])->assertStatus(422);

        $booking->forceFill(['ready_at' => now()])->saveQuietly();
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])->assertCreated();
    }

    public function test_completion_needs_the_handover_only_when_the_flag_is_on(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->light();
        $booking->update(['current_stage' => 'qc']);

        $this->assertNull($booking->fresh()->completionBlocker());

        config(['booking.handover_required' => true]);
        $this->assertStringContainsString('serah terima', (string) $booking->fresh()->completionBlocker());

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", ['care_instructions_given' => true, 'wet_signature_received' => false])->assertCreated();
        $this->assertNull($booking->fresh()->completionBlocker());

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/complete")->assertOk();
        $this->assertSame('completed', $booking->fresh()->status);
    }
}
