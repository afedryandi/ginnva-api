<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lama pengerjaan PPF/Kaca Film baru diketahui setelah SPK (kondisi kendaraan diperiksa), jadi angka saat booking hanya perkiraan awal
 * (PPF 4 hari). Siapa pun yang memegang SPK, termasuk installer, boleh mengubahnya dari SPK; pelanggan diberi tahu.
 */
class BookingDurationAfterSpkTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['store_manager', 'installer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function staff(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ], $overrides));
    }

    private function spkFor(Booking $booking): Spk
    {
        $this->actingAs($this->staff('store_manager'), 'api')->postJson('/api/staff/spks', [
            'booking_id' => $booking->id,
            'customer_name' => 'Budi',
            'vehicle_plate' => 'B 1234 XYZ',
            'vehicle_type' => 'suv',
            'checklist_items' => [['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true]],
        ])->assertStatus(201);

        return Spk::where('booking_id', $booking->id)->firstOrFail();
    }

    public function test_ppf_starts_with_a_four_day_estimate(): void
    {
        $this->assertSame(4, (int) $this->booking()->duration_days);
    }

    public function test_an_installer_on_the_booking_sets_the_duration_from_the_spk_and_the_customer_is_told(): void
    {
        $booking = $this->booking();
        $installer = $this->staff('installer');
        $booking->installers()->attach($installer->id);
        $spk = $this->spkFor($booking);

        $this->actingAs($installer, 'api')->getJson("/api/staff/spks/{$spk->id}")
            ->assertOk()
            ->assertJsonPath('duration.days', 4)
            ->assertJsonPath('duration.can_change', true);

        $this->actingAs($installer, 'api')->putJson("/api/staff/spks/{$spk->id}/duration", ['duration_days' => 6, 'reason' => 'Cat kusam, perlu perbaikan dulu'])
            ->assertOk()
            ->assertJsonPath('duration.days', 6);

        $this->assertSame(6, (int) $booking->fresh()->duration_days);

        $note = CustomerNotification::where('customer_id', $this->customer->id)->where('title', 'Estimasi Pengerjaan Berubah')->first();
        $this->assertNotNull($note, 'Pelanggan diberi tahu estimasi baru.');
        $this->assertStringContainsString('6 hari kerja', $note->body);
        $this->assertStringContainsString('Cat kusam', $note->body);
    }

    public function test_an_installer_not_on_the_booking_cannot_touch_it(): void
    {
        $booking = $this->booking();
        $spk = $this->spkFor($booking);

        $this->actingAs($this->staff('installer'), 'api')->putJson("/api/staff/spks/{$spk->id}/duration", ['duration_days' => 6])->assertNotFound();
        $this->assertSame(4, (int) $booking->fresh()->duration_days);
    }

    public function test_shortening_always_works_but_extending_into_a_full_day_is_refused(): void
    {
        $this->store->update(['install_capacity_per_day' => 1]);
        $mine = $this->booking(['duration_days' => 2, 'preferred_date' => now()->addDays(3)->toDateString()]);
        // Booking lain menempati hari ke-3 dari booking ini; kapasitas 1.
        $this->booking(['duration_days' => 1, 'preferred_date' => now()->addDays(5)->toDateString(), 'customer_id' => Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)])->id]);
        $spk = $this->spkFor($mine);
        $manager = $this->staff('store_manager');

        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}/duration", ['duration_days' => 3])
            ->assertStatus(422);
        $this->assertSame(2, (int) $mine->fresh()->duration_days, 'Tidak berubah saat kapasitas penuh.');

        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}/duration", ['duration_days' => 1])->assertOk();
        $this->assertSame(1, (int) $mine->fresh()->duration_days);
    }

    public function test_light_services_and_manual_spks_have_no_adjustable_duration(): void
    {
        $light = $this->booking(['service_type' => 'Detailing', 'product_ppf' => false, 'product_detailing' => true]);
        $manager = $this->staff('store_manager');

        $spkLight = $this->spkFor($light);
        $this->actingAs($manager, 'api')->getJson("/api/staff/spks/{$spkLight->id}")->assertJsonPath('duration.can_change', false);
        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spkLight->id}/duration", ['duration_days' => 2])->assertStatus(422);

        $manual = $this->actingAs($manager, 'api')->postJson('/api/staff/spks', [
            'customer_name' => 'Walk in', 'vehicle_plate' => 'B 9 ZZ', 'vehicle_type' => 'sedan',
            'checklist_items' => [['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true]],
        ]);

        if ($manual->status() === 201) {
            $this->actingAs($manager, 'api')->putJson('/api/staff/spks/' . $manual->json('data.id') . '/duration', ['duration_days' => 2])->assertStatus(422);
        }
    }

    public function test_the_same_or_out_of_range_duration_is_rejected(): void
    {
        $booking = $this->booking();
        $spk = $this->spkFor($booking);
        $manager = $this->staff('store_manager');

        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}/duration", ['duration_days' => 4])->assertStatus(422);
        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}/duration", ['duration_days' => 0])->assertStatus(422);
        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}/duration", ['duration_days' => 15])->assertStatus(422);
    }
}
