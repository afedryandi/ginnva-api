<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\MaterialMemo;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use App\Support\VehicleFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Data kendaraan seragam di Booking, SPK, dan Memo Barang (2026-11-27): istilah Merek, Model, Varian, Tahun; plat dirapikan; batas dan
 * aturan tahun sama; SPK dan memo membawa merek, model, dan varian terpisah (tidak ada lagi penggabungan atau tebakan kata pertama).
 */
class VehicleFieldsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['store_manager', 'installer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true, 'preferred_date' => now()->addDays(3)->toDateString(), 'status' => 'confirmed',
        ], $overrides));
    }

    public function test_the_plate_is_tidied_the_same_way_in_booking_spk_and_memo(): void
    {
        $this->assertSame('B 1234 XYZ', VehicleFormat::normalizePlate('  b   1234 xyz '));
        $this->assertNull(VehicleFormat::normalizePlate('   '));

        $booking = $this->booking(['vehicle_plate' => 'b 1234  xyz']);
        $this->assertSame('B 1234 XYZ', $booking->fresh()->vehicle_plate);

        // SPK dari booking yang belum punya plat: yang diketik staf dirapikan. Bila booking punya plat, SPK membaca plat booking (BookingMirror).
        $manager = $this->user('store_manager');
        $withoutPlate = $this->booking(['vehicle_plate' => null]);
        $this->actingAs($manager, 'api')->postJson('/api/staff/spks', [
            'booking_id' => $withoutPlate->id, 'customer_name' => 'Budi', 'vehicle_plate' => 'd 99   ab',
            'checklist_items' => [['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true]],
        ])->assertStatus(201);
        $this->assertSame('D 99 AB', Spk::where('booking_id', $withoutPlate->id)->value('vehicle_plate'));

        $memo = MaterialMemo::create(['memo_number' => 'MEMO-T-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $manager->id, 'vehicle_plate' => 'f 1  zz']);
        $this->assertSame('F 1 ZZ', $memo->fresh()->vehicle_plate);
    }

    public function test_the_spk_keeps_brand_model_variant_and_year_separately_with_one_set_of_limits(): void
    {
        $booking = $this->booking();
        $manager = $this->user('store_manager');

        $payload = [
            'booking_id' => $booking->id, 'customer_name' => 'Budi', 'vehicle_brand' => 'Land Rover', 'vehicle_model' => 'Defender',
            'vehicle_variant' => '110 X-Dynamic', 'vehicle_year' => '2023',
            'checklist_items' => [['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true]],
        ];

        $this->actingAs($manager, 'api')->postJson('/api/staff/spks', $payload)->assertStatus(201);

        $spk = Spk::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('Land Rover', $spk->vehicle_brand, 'Merek dua kata tetap utuh.');
        $this->assertSame('Defender', $spk->vehicle_model);
        $this->assertSame('110 X-Dynamic', $spk->vehicle_variant);
        $this->assertSame('Land Rover Defender 110 X-Dynamic', $spk->vehicle_name);

        // Aturan tahun dan batas panjang sama dengan Booking dan Memo.
        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}", ['customer_name' => 'Budi', 'vehicle_year' => '1950'])->assertStatus(422);
        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}", ['customer_name' => 'Budi', 'vehicle_year' => (string) (now()->year + 5)])->assertStatus(422);
        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}", ['customer_name' => 'Budi', 'vehicle_brand' => str_repeat('a', VehicleFormat::MAX_BRAND + 1)])->assertStatus(422);
        $this->actingAs($manager, 'api')->putJson("/api/staff/spks/{$spk->id}", ['customer_name' => 'Budi', 'vehicle_plate' => str_repeat('1', VehicleFormat::MAX_PLATE + 1)])->assertStatus(422);
    }

    public function test_the_memo_takes_brand_model_variant_and_year_and_builds_the_combined_text(): void
    {
        $installer = $this->user('installer');

        $response = $this->actingAs($installer, 'api')->postJson('/api/staff/memos', [
            'vehicle_brand' => 'Land Rover', 'vehicle_model' => 'Defender', 'vehicle_variant' => '110 X-Dynamic', 'vehicle_year' => 2023,
            'vehicle_plate' => 'b 1 abc', 'request_note' => 'Ambil film',
        ])->assertStatus(201);

        $memo = MaterialMemo::findOrFail($response->json('data.id'));
        $this->assertSame('Land Rover', $memo->vehicle_brand);
        $this->assertSame('Defender', $memo->vehicle_model);
        $this->assertSame('110 X-Dynamic', $memo->vehicle_variant);
        $this->assertSame(2023, $memo->vehicle_year);
        $this->assertSame('B 1 ABC', $memo->vehicle_plate);
        $this->assertSame('Land Rover Defender 110 X-Dynamic - B 1 ABC', $memo->vehicle_info);

        $this->actingAs($installer, 'api')->postJson('/api/staff/memos', [
            'vehicle_brand' => 'Toyota', 'vehicle_model' => 'Avanza', 'vehicle_year' => 1900, 'vehicle_plate' => 'B 2 ABC',
        ])->assertStatus(422);
    }

    public function test_the_combined_text_without_a_variant_keeps_its_old_shape(): void
    {
        $this->assertSame('Toyota Avanza - B 1234 XYZ', MaterialMemo::composeVehicleInfo('Toyota', 'Avanza', 'B 1234 XYZ'));
        $this->assertSame('Toyota Avanza 1.5 G - B 1234 XYZ', MaterialMemo::composeVehicleInfo('Toyota', 'Avanza', 'B 1234 XYZ', '1.5 G'));
        $this->assertNull(MaterialMemo::composeVehicleInfo(null, null, null));
    }
}
