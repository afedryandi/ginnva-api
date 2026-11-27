<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pelanggan boleh melihat SPK booking miliknya, hanya baca (keputusan pemilik 2026-11-27): kondisi kendaraan saat masuk, pekerjaan,
 * dan perlengkapan. Catatan internal dan nama installer tidak ikut.
 */
class CustomerSpkViewTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('store_manager', 'web');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
    }

    private function bookingFor(Customer $customer): Booking
    {
        return Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ]);
    }

    private function spkFor(Booking $booking): Spk
    {
        $manager = User::create(['name' => 'Manager', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $manager->assignRole('store_manager');

        $this->actingAs($manager, 'api')->postJson('/api/staff/spks', [
            'booking_id' => $booking->id,
            'customer_name' => 'Budi',
            'vehicle_plate' => 'B 1234 XYZ',
            'vehicle_brand' => 'Toyota Fortuner',
            'vehicle_type' => 'suv',
            'fuel_level' => 'half',
            'notes' => 'CATATAN INTERNAL: pelanggan rewel soal harga',
            'checklist_items' => [
                ['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true],
                ['category' => 'pekerjaan', 'label' => 'Kaca Film', 'is_checked' => false],
                ['category' => 'perlengkapan', 'label' => 'STNK', 'is_checked' => true],
            ],
            'damage_marks' => [['x_percent' => 20.5, 'y_percent' => 40, 'code' => 'B', 'note' => 'Baret pintu kiri']],
        ])->assertStatus(201);

        return Spk::where('booking_id', $booking->id)->firstOrFail();
    }

    public function test_a_customer_sees_the_spk_of_their_own_booking_read_only(): void
    {
        $booking = $this->bookingFor($this->customer);
        $spk = $this->spkFor($booking);

        $response = $this->actingAs($this->customer, 'customer')->getJson("/api/customer/bookings/{$booking->id}/spk")->assertOk();

        $response->assertJsonPath('data.spk_number', $spk->spk_number)
            ->assertJsonPath('data.vehicle.plate', 'B 1234 XYZ')
            ->assertJsonPath('data.vehicle.body_type', 'SUV')
            ->assertJsonPath('data.vehicle.fuel', '1/2')
            ->assertJsonPath('data.checklist.pekerjaan', ['PPF'])
            ->assertJsonPath('data.checklist.perlengkapan', ['STNK'])
            ->assertJsonPath('data.damage_marks.0.code', 'B')
            ->assertJsonMissingPath('data.damage_marks.0.note');

        $raw = $response->getContent();
        $this->assertStringNotContainsString('Baret pintu kiri', $raw, 'Keterangan per titik tidak dikirim; pelanggan hanya melihat jumlah per jenis.');
        $this->assertStringNotContainsString('CATATAN INTERNAL', $raw, 'Catatan internal SPK tidak boleh bocor ke pelanggan.');
        $this->assertStringNotContainsString('installers', $raw);
        $this->assertStringNotContainsString('Kaca Film', $raw, 'Butir yang tidak dicentang tidak ditampilkan.');
    }

    public function test_the_booking_list_tells_the_app_whether_an_spk_exists(): void
    {
        $withSpk = $this->bookingFor($this->customer);
        $this->spkFor($withSpk);
        $without = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true, 'preferred_date' => now()->addDays(10)->toDateString(), 'status' => 'pending',
        ]);

        $list = collect($this->actingAs($this->customer, 'customer')->getJson('/api/customer/bookings')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue((bool) $list[$withSpk->id]['has_spk']);
        $this->assertFalse((bool) $list[$without->id]['has_spk']);
    }

    public function test_another_customer_cannot_open_it_and_a_booking_without_spk_says_so(): void
    {
        $booking = $this->bookingFor($this->customer);
        $this->spkFor($booking);
        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);

        $this->actingAs($other, 'customer')->getJson("/api/customer/bookings/{$booking->id}/spk")->assertNotFound();

        $bare = $this->bookingFor($this->customer);
        $this->actingAs($this->customer, 'customer')->getJson("/api/customer/bookings/{$bare->id}/spk")
            ->assertNotFound()
            ->assertJsonPath('message', 'SPK untuk booking ini belum dibuat.');
    }
}
