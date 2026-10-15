<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingIntakePhoto;
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
 * Fase 3C pengerjaan ulang booking (2026-10-19): foto penerimaan (4 sudut baku + kerusakan) dan penandaan pelanggan tidak datang.
 */
class IntakePhotosAndNoShowTest extends TestCase
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
            'booking_number' => 'BKG-IN-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'confirmed',
        ], $overrides));
    }

    private function upload(User $user, Booking $booking, string $angle, array $extra = [])
    {
        return $this->actingAs($user, 'api')->post("/api/staff/bookings/{$booking->id}/intake-photos", array_merge([
            'angle' => $angle,
            'photo' => UploadedFile::fake()->image("{$angle}.jpg"),
        ], $extra), ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------- foto penerimaan

    public function test_the_four_standard_angles_make_the_set_complete(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->booking();

        $first = $this->upload($manager, $booking, 'depan')->assertCreated();
        $this->assertFalse($first->json('data.complete'));

        foreach (['belakang', 'kiri', 'kanan'] as $angle) {
            $last = $this->upload($manager, $booking, $angle)->assertCreated();
        }

        $this->assertTrue($last->json('data.complete'));
        $this->assertSame(4, $booking->intakePhotos()->count());
        foreach ($booking->intakePhotos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
        }
    }

    public function test_a_standard_angle_is_replaced_not_duplicated(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->booking();

        $this->upload($manager, $booking, 'depan')->assertCreated();
        $oldPath = $booking->intakePhotos()->first()->path;

        $this->upload($manager, $booking, 'depan')->assertCreated();

        $this->assertSame(1, $booking->intakePhotos()->where('angle', 'depan')->count());
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_damage_photos_need_a_note_and_can_be_deleted(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->booking();

        $this->upload($manager, $booking, 'kerusakan')->assertStatus(422);

        $this->upload($manager, $booking, 'kerusakan', ['note' => 'Baret di pintu kiri depan'])->assertCreated();
        $photo = $booking->intakePhotos()->where('angle', 'kerusakan')->first();
        $this->assertSame('Baret di pintu kiri depan', $photo->note);

        $this->actingAs($manager, 'api')->deleteJson("/api/staff/bookings/{$booking->id}/intake-photos/{$photo->id}")->assertOk();
        $this->assertSame(0, $booking->intakePhotos()->count());

        // Foto sudut baku tidak dihapus, hanya diganti.
        $this->upload($manager, $booking, 'depan')->assertCreated();
        $standard = $booking->intakePhotos()->where('angle', 'depan')->first();
        $this->actingAs($manager, 'api')->deleteJson("/api/staff/bookings/{$booking->id}/intake-photos/{$standard->id}")->assertStatus(422);
    }

    public function test_damage_photos_are_capped(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->booking();

        for ($i = 0; $i < BookingIntakePhoto::MAX_DAMAGE_PHOTOS; $i++) {
            $this->upload($manager, $booking, 'kerusakan', ['note' => "Kerusakan {$i}"])->assertCreated();
        }

        $this->upload($manager, $booking, 'kerusakan', ['note' => 'Satu lagi'])->assertStatus(422);
    }

    public function test_intake_photos_only_for_confirmed_bookings_and_authorized_staff(): void
    {
        $manager = $this->user('store_manager');

        $pending = $this->booking(['status' => 'pending']);
        $this->upload($manager, $pending, 'depan')->assertStatus(422);

        $booking = $this->booking();
        $this->upload($this->user('installer'), $booking, 'depan')->assertStatus(403);

        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $outsider = $this->user('store_manager');
        $outsider->update(['store_id' => $otherStore->id]);
        $this->upload($outsider, $booking, 'depan')->assertStatus(404);

        $this->upload($manager, $booking, 'atap')->assertStatus(422);
    }

    // ------------------------------------------------------------- no-show

    public function test_no_show_cancels_the_booking_and_frees_the_slot(): void
    {
        $manager = $this->user('store_manager');
        $booking = $this->booking();

        $this->assertSame(1, Booking::confirmedOverlapCount($this->store->id, today()));

        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$booking->id}/no-show")->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->no_show_at);
        $this->assertSame('Pelanggan tidak datang (no-show)', $fresh->cancel_reason);
        $this->assertSame($manager->id, (int) $fresh->cancelled_by_id);
        $this->assertSame(0, Booking::confirmedOverlapCount($this->store->id, today()));
    }

    public function test_no_show_is_refused_for_future_dates_started_work_and_wrong_status(): void
    {
        $manager = $this->user('store_manager');

        $future = $this->booking(['preferred_date' => now()->addDays(2)->toDateString()]);
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$future->id}/no-show")->assertStatus(422);

        $started = $this->booking(['current_stage' => 'ppf_washing']);
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$started->id}/no-show")->assertStatus(422);

        $pending = $this->booking(['status' => 'pending']);
        $this->actingAs($manager, 'api')->postJson("/api/staff/bookings/{$pending->id}/no-show")->assertStatus(422);

        $this->assertNull($future->fresh()->no_show_at);
    }

    public function test_only_store_managers_and_full_access_can_mark_no_show(): void
    {
        $booking = $this->booking();
        Role::findOrCreate('staff', 'web');

        $this->actingAs($this->user('staff'), 'api')->postJson("/api/staff/bookings/{$booking->id}/no-show")->assertStatus(403);
        $this->actingAs($this->user('installer'), 'api')->postJson("/api/staff/bookings/{$booking->id}/no-show")->assertStatus(403);
        $this->assertSame('confirmed', $booking->fresh()->status);

        $this->actingAs($this->user('super_admin'), 'api')->postJson("/api/staff/bookings/{$booking->id}/no-show")->assertOk();
    }
}
