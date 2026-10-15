<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingServiceType;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\FilmProduct;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CustomerAccountDeletionService;
use App\Services\SpkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 1 pengerjaan ulang booking (2026-10-15): Kendaraan Saya, katalog kendaraan dan layanan (estimasi harga), dan
 * booking v2 (layanan + kendaraan) — termasuk kompatibilitas payload lama.
 */
class CustomerVehicleAndBookingV2Test extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;
    private Vehicle $fortuner;
    private Vehicle $brio;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $this->fortuner = Vehicle::create(['brand' => 'Toyota', 'model' => 'Fortuner', 'variant' => 'VRZ', 'size_category' => 'L']);
        $this->brio = Vehicle::create(['brand' => 'Honda', 'model' => 'Brio', 'variant' => null, 'size_category' => 'S']);
    }

    private function as(?Customer $customer = null)
    {
        return $this->actingAs($customer ?? $this->customer, 'customer');
    }

    private function addVehicle(array $overrides = [], ?Customer $customer = null)
    {
        return $this->as($customer)->postJson('/api/customer/vehicles', array_merge([
            'vehicle_id' => $this->fortuner->id,
            'plate' => 'B 1234 ABC',
            'color' => 'Hitam',
            'year' => 2022,
        ], $overrides));
    }

    private function bookingPayload(int $vehicleId, array $overrides = []): array
    {
        return array_merge([
            'store_id' => $this->store->id,
            'services' => ['ppf'],
            'customer_vehicle_id' => $vehicleId,
            'preferred_date' => now()->addDays(5)->toDateString(),
            'privacy_consent' => true,
        ], $overrides);
    }

    // ------------------------------------------------------------- Kendaraan Saya

    public function test_vehicle_from_the_catalog_gets_size_and_label_and_the_first_becomes_default(): void
    {
        $response = $this->addVehicle(['plate' => 'b 1234-abc'])->assertStatus(201);

        $response->assertJsonPath('data.brand', 'Toyota')
            ->assertJsonPath('data.model', 'Fortuner')
            ->assertJsonPath('data.label', 'Toyota Fortuner VRZ')
            ->assertJsonPath('data.size_category', 'L')
            ->assertJsonPath('data.plate', 'B 1234 ABC')
            ->assertJsonPath('data.is_default', true);

        $second = $this->addVehicle(['vehicle_id' => $this->brio->id, 'plate' => 'D 99 XY'])->assertStatus(201);
        $second->assertJsonPath('data.is_default', false)->assertJsonPath('data.size_category', 'S');

        $this->as()->getJson('/api/customer/vehicles')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.is_default', true);
    }

    public function test_manual_vehicle_needs_brand_and_model_and_size_can_stay_empty(): void
    {
        $this->as()->postJson('/api/customer/vehicles', ['plate' => 'B 1 A'])->assertStatus(422)->assertJsonValidationErrors(['brand', 'model']);

        $this->as()->postJson('/api/customer/vehicles', ['brand' => 'Wuling', 'model' => 'Air EV', 'plate' => 'B 5555 KLM'])
            ->assertStatus(201)
            ->assertJsonPath('data.vehicle_id', null)
            ->assertJsonPath('data.size_category', null);
    }

    public function test_plate_must_be_valid_and_unique_per_customer_and_the_list_is_capped(): void
    {
        $this->addVehicle(['plate' => 'ABC'])->assertStatus(422)->assertJsonValidationErrors('plate'); // tanpa angka
        $this->addVehicle(['plate' => 'B 1234 ABCD'])->assertStatus(422)->assertJsonValidationErrors('plate'); // seri 4 huruf

        $this->addVehicle(['plate' => 'B 1234 ABC'])->assertStatus(201);
        $this->addVehicle(['plate' => 'b1234abc'])->assertStatus(422)->assertJsonValidationErrors('plate'); // plat sama, beda tulisan

        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $this->addVehicle(['plate' => 'B 1234 ABC'], $other)->assertStatus(201); // plat sama boleh di pelanggan lain

        foreach (range(2, 10) as $i) {
            $this->addVehicle(['plate' => 'B ' . ($i * 100 + $i) . ' AB'])->assertStatus(201);
        }
        $this->addVehicle(['plate' => 'B 7777 ZZ'])->assertStatus(422)->assertJsonValidationErrors('plate'); // batas 10
    }

    public function test_update_default_and_delete_work_and_stay_private_to_the_owner(): void
    {
        $a = $this->addVehicle(['plate' => 'B 1111 AA'])->assertStatus(201)->json('data.id');
        $b = $this->addVehicle(['vehicle_id' => $this->brio->id, 'plate' => 'B 2222 BB'])->assertStatus(201)->json('data.id');

        $this->as()->putJson("/api/customer/vehicles/{$b}", ['vehicle_id' => $this->brio->id, 'plate' => 'B 2222 BB', 'color' => 'Merah', 'is_default' => true])
            ->assertOk()->assertJsonPath('data.color', 'Merah')->assertJsonPath('data.is_default', true);
        $this->assertFalse((bool) CustomerVehicle::find($a)->is_default);

        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $this->as($other)->putJson("/api/customer/vehicles/{$a}", ['vehicle_id' => $this->brio->id, 'plate' => 'B 3333 CC'])->assertStatus(404);
        $this->as($other)->deleteJson("/api/customer/vehicles/{$a}")->assertStatus(404);
        $this->as($other)->getJson('/api/customer/vehicles')->assertJsonCount(0, 'data');

        $this->as()->deleteJson("/api/customer/vehicles/{$b}")->assertOk();
        $this->assertTrue((bool) CustomerVehicle::find($a)->is_default, 'Default berpindah ke kendaraan yang tersisa.');
    }

    public function test_vehicle_endpoints_require_login(): void
    {
        $this->getJson('/api/customer/vehicles')->assertStatus(401);
        $this->postJson('/api/customer/vehicles', [])->assertStatus(401);
    }

    // ------------------------------------------------------------- katalog

    public function test_vehicle_catalog_is_public_and_includes_the_size(): void
    {
        // Migrasi sudah menanam katalog kendaraan asli, jadi periksa isi yang kita tambahkan, bukan daftar lengkapnya.
        $response = $this->getJson('/api/vehicles/catalog')->assertOk()
            ->assertJsonFragment(['brand' => 'Toyota', 'model' => 'Fortuner', 'variant' => 'VRZ', 'size_category' => 'L'])
            ->assertJsonFragment(['brand' => 'Honda', 'model' => 'Brio', 'size_category' => 'S']);

        $this->assertContains('Toyota', $response->json('data.brands'));
        $this->assertContains('Honda', $response->json('data.brands'));
    }

    public function test_service_catalog_does_not_expose_prices_by_default(): void
    {
        FilmProduct::where('product_type', 'detailing')->update(['base_price' => 450_000, 'is_active' => true]);

        $services = $this->getJson('/api/booking/services')->assertOk()->json('data.services');

        $this->assertNotEmpty($services);
        foreach ($services as $service) {
            $this->assertNull($service['price'], 'Harga tidak boleh bocor lewat API publik.');
        }
        $this->assertStringNotContainsString('450000', json_encode($services));
    }

    public function test_service_catalog_shows_fixed_prices_for_detailing_and_wash_and_from_prices_for_ppf_when_enabled(): void
    {
        config(['booking.show_prices' => true]);

        // Migrasi bisa menanam produk bawaan; mulai dari katalog PPF/kaca film yang bersih supaya angkanya pasti.
        FilmProduct::whereIn('product_type', ['ppf', 'window_film'])->get()->each->delete();
        FilmProduct::where('product_type', 'detailing')->update(['base_price' => 450_000, 'is_active' => true]);
        FilmProduct::where('product_type', 'premium_wash')->update(['base_price' => 150_000, 'is_active' => true]);

        $ppf = FilmProduct::create(['sku' => 'PPF-A', 'name' => 'PPF A', 'product_type' => 'ppf', 'position' => 'all', 'base_price' => 0, 'is_active' => true]);
        DB::table('film_product_prices')->insert([
            ['film_product_id' => $ppf->id, 'vehicle_size' => 'S', 'price' => 5_000_000, 'created_at' => now(), 'updated_at' => now()],
            ['film_product_id' => $ppf->id, 'vehicle_size' => 'L', 'price' => 8_000_000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $all = collect($this->getJson('/api/booking/services')->assertOk()->json('data.services'))->keyBy('key');

        $this->assertSame(['kaca_film', 'ppf', 'detailing', 'premium_wash'], $all->keys()->all());
        $this->assertSame('fixed', $all['detailing']['price']['type']);
        $this->assertEquals(450_000, $all['detailing']['price']['amount']);
        $this->assertSame('Rp450.000', $all['detailing']['price']['label']);
        $this->assertSame('Rp150.000', $all['premium_wash']['price']['label']);
        $this->assertSame('from', $all['ppf']['price']['type']);
        $this->assertSame('Mulai dari Rp5.000.000', $all['ppf']['price']['label']);
        $this->assertSame('unavailable', $all['kaca_film']['price']['type']);
        $this->assertSame(3, $all['ppf']['estimated_duration_days']);
        $this->assertSame(1, $all['premium_wash']['estimated_duration_days']);
        $this->assertSame('ringan', $all['detailing']['capacity_category']);

        $sized = collect($this->getJson('/api/booking/services?vehicle_size=L')->json('data.services'))->keyBy('key');
        $this->assertSame('Mulai dari Rp8.000.000', $sized['ppf']['price']['label']);
        $this->assertSame('Rp450.000', $sized['detailing']['price']['label'], 'Harga tetap tidak bergantung ukuran.');
    }

    public function test_an_inactive_service_is_hidden_from_the_catalog(): void
    {
        BookingServiceType::where('key', 'detailing')->update(['is_active' => false]);
        Cache::forget('booking_service_types.v1');

        $keys = collect($this->getJson('/api/booking/services')->json('data.services'))->pluck('key')->all();

        $this->assertNotContains('detailing', $keys);
        $this->assertContains('premium_wash', $keys);
    }

    // ------------------------------------------------------------- booking v2

    public function test_booking_v2_stores_services_flags_and_a_vehicle_snapshot(): void
    {
        $vehicleId = $this->addVehicle()->json('data.id');

        $response = $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId, ['services' => ['detailing', 'ppf'], 'notes' => 'Mohon hati-hati']))
            ->assertStatus(201);

        $booking = Booking::findOrFail($response->json('data.id'));

        $this->assertSame('Pelindung Cat (PPF), Detailing', $booking->service_type);
        $this->assertTrue((bool) $booking->product_ppf);
        $this->assertTrue((bool) $booking->product_detailing);
        $this->assertFalse((bool) $booking->product_kaca_film);
        $this->assertFalse((bool) $booking->product_premium_wash);
        $this->assertSame($vehicleId, $booking->customer_vehicle_id);
        $this->assertSame('B 1234 ABC', $booking->vehicle_plate);
        $this->assertSame('Toyota', $booking->vehicle_brand);
        $this->assertSame('Fortuner', $booking->vehicle_model);
        $this->assertSame('VRZ', $booking->vehicle_variant);
        $this->assertSame('Hitam', $booking->vehicle_color);
        $this->assertSame(2022, $booking->vehicle_year);
        $this->assertSame('L', $booking->vehicle_size);
        $this->assertSame(3, $booking->duration_days, 'PPF menentukan durasi bawaan 3 hari.');
        $this->assertSame('pending', $booking->status);
    }

    public function test_light_services_default_to_one_day(): void
    {
        $vehicleId = $this->addVehicle()->json('data.id');

        $id = $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId, ['services' => ['premium_wash'], 'preferred_time' => '09:00 - 11:00']))->assertStatus(201)->json('data.id');

        $this->assertSame(1, Booking::findOrFail($id)->duration_days);
        $this->assertTrue((bool) Booking::findOrFail($id)->product_premium_wash);
    }

    public function test_booking_v2_validates_services_and_the_vehicle(): void
    {
        $vehicleId = $this->addVehicle()->json('data.id');
        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $othersVehicle = $this->addVehicle(['plate' => 'D 4321 XY'], $other)->json('data.id');

        $post = fn (array $over) => $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId, $over));

        $post(['services' => []])->assertStatus(422);
        $post(['services' => ['coating']])->assertStatus(422);
        $post(['services' => ['ppf', 'ppf']])->assertStatus(422);
        $post(['customer_vehicle_id' => null])->assertStatus(422);
        $post(['customer_vehicle_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('customer_vehicle_id');
        $post(['customer_vehicle_id' => $othersVehicle])->assertStatus(422)->assertJsonValidationErrors('customer_vehicle_id');

        $this->assertSame(0, Booking::count());
    }

    public function test_booking_v2_keeps_the_duplicate_rule_for_the_same_services(): void
    {
        $vehicleId = $this->addVehicle()->json('data.id');

        $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId, ['services' => ['ppf', 'detailing']]))->assertStatus(201);
        $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId, ['services' => ['detailing', 'ppf']]))->assertStatus(422); // urutan beda, layanan sama
        $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId, ['services' => ['ppf']]))->assertStatus(201); // layanan berbeda
    }

    public function test_the_old_payload_without_a_vehicle_still_works(): void
    {
        $id = $this->as()->postJson('/api/customer/bookings', [
            'store_id' => $this->store->id,
            'service_type' => 'Kaca Film (Window Film)',
            'product_kaca_film' => true,
            'preferred_date' => now()->addDays(5)->toDateString(),
        ])->assertStatus(201)->json('data.id');

        $booking = Booking::findOrFail($id);
        $this->assertTrue((bool) $booking->product_kaca_film);
        $this->assertNull($booking->customer_vehicle_id);
        $this->assertNull($booking->vehicle_plate);
    }

    public function test_the_booking_keeps_its_vehicle_snapshot_when_the_vehicle_is_deleted(): void
    {
        $vehicleId = $this->addVehicle()->json('data.id');
        $id = $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId))->assertStatus(201)->json('data.id');

        $this->as()->deleteJson("/api/customer/vehicles/{$vehicleId}")->assertOk();

        $booking = Booking::findOrFail($id);
        $this->assertNull($booking->customer_vehicle_id);
        $this->assertSame('B 1234 ABC', $booking->vehicle_plate);
        $this->assertSame('Fortuner', $booking->vehicle_model);
    }

    public function test_spk_created_from_a_booking_is_prefilled_with_the_vehicle(): void
    {
        $vehicleId = $this->addVehicle()->json('data.id');
        $id = $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId))->assertStatus(201)->json('data.id');
        Booking::whereKey($id)->update(['status' => 'confirmed']);

        $spk = app(SpkService::class)->createFromBooking(Booking::findOrFail($id), null);

        $this->assertSame('B 1234 ABC', $spk->vehicle_plate);
        $this->assertSame('Toyota Fortuner VRZ', $spk->vehicle_brand);
        $this->assertSame('2022', (string) $spk->vehicle_year);
        $this->assertSame(1, Spk::count());
    }

    public function test_deleting_the_account_removes_the_vehicles_but_keeps_the_booking_snapshot(): void
    {
        $vehicleId = $this->addVehicle()->json('data.id');
        $id = $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId))->assertStatus(201)->json('data.id');

        app(CustomerAccountDeletionService::class)->delete($this->customer);

        $this->assertSame(0, CustomerVehicle::count());
        $this->assertSame('B 1234 ABC', Booking::withoutGlobalScopes()->findOrFail($id)->vehicle_plate);
    }

    public function test_staff_see_the_vehicle_on_the_booking_detail_and_list(): void
    {
        Role::findOrCreate('store_manager', 'web');
        $staff = User::create(['name' => 'SM', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01']);
        $staff->assignRole('store_manager');

        $vehicleId = $this->addVehicle()->json('data.id');
        $id = $this->as()->postJson('/api/customer/bookings', $this->bookingPayload($vehicleId))->assertStatus(201)->json('data.id');

        $this->actingAs($staff, 'api')->getJson("/api/staff/bookings/{$id}")->assertOk()
            ->assertJsonPath('data.vehicle_plate', 'B 1234 ABC')
            ->assertJsonPath('data.vehicle_brand', 'Toyota')
            ->assertJsonPath('data.vehicle_model', 'Fortuner')
            ->assertJsonPath('data.vehicle_size', 'L');

        $this->actingAs($staff, 'api')->getJson('/api/staff/bookings')->assertOk()
            ->assertJsonFragment(['vehicle_plate' => 'B 1234 ABC']);
    }
}
