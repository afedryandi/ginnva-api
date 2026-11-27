<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\MaterialMemo;
use App\Models\Spk;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\VehicleFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 0 rombakan alur Booking, SPK, Memo (2026-11-27): pelanggan dan kendaraan diisi sekali di Booking; SPK dan Memo membacanya (cermin otomatis).
 * Jenis tenaga (bensin, listrik, hybrid) ada di Booking dan Kendaraan Saya; SPK memakainya untuk menampilkan BBM, Battery, atau keduanya.
 */
class BookingMirrorTest extends TestCase
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

    private function manager(): User
    {
        $user = User::create(['name' => 'Manager ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $user->assignRole('store_manager');

        return $user;
    }

    private function booking(array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true, 'preferred_date' => now()->addDays(3)->toDateString(), 'status' => 'confirmed',
            'customer_name' => 'Budi Santoso', 'phone_number' => '081234567890',
            'vehicle_plate' => 'B 1234 ABC', 'vehicle_brand' => 'Toyota', 'vehicle_model' => 'Fortuner', 'vehicle_variant' => 'VRZ', 'vehicle_year' => 2022,
        ], $overrides));
    }

    private function createSpk(Booking $booking, array $overrides = []): Spk
    {
        $this->actingAs($this->manager(), 'api')->postJson('/api/staff/spks', array_merge([
            'booking_id' => $booking->id, 'customer_name' => 'Nama Lain', 'vehicle_plate' => 'D 999 ZZ',
            'checklist_items' => [['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true]],
        ], $overrides))->assertStatus(201);

        return Spk::where('booking_id', $booking->id)->firstOrFail();
    }

    public function test_an_spk_for_a_booking_takes_customer_and_vehicle_from_the_booking_whatever_is_sent(): void
    {
        $booking = $this->booking();

        $spk = $this->createSpk($booking, ['vehicle_brand' => 'Honda', 'vehicle_year' => '2000']);

        $this->assertSame('Budi Santoso', $spk->customer_name);
        $this->assertSame('081234567890', $spk->phone_number);
        $this->assertSame('B 1234 ABC', $spk->vehicle_plate);
        $this->assertSame('Toyota', $spk->vehicle_brand);
        $this->assertSame('Fortuner', $spk->vehicle_model);
        $this->assertSame('VRZ', $spk->vehicle_variant);
        $this->assertSame('2022', $spk->vehicle_year);
    }

    public function test_a_booking_without_vehicle_data_does_not_blank_what_staff_typed_in_the_spk(): void
    {
        $booking = $this->booking(['vehicle_plate' => null, 'vehicle_brand' => null, 'vehicle_model' => null, 'vehicle_variant' => null, 'vehicle_year' => null]);

        $spk = $this->createSpk($booking, ['vehicle_plate' => 'D 999 ZZ', 'vehicle_brand' => 'Mazda']);

        $this->assertSame('D 999 ZZ', $spk->vehicle_plate);
        $this->assertSame('Mazda', $spk->vehicle_brand);
        $this->assertSame('Budi Santoso', $spk->customer_name, 'Nama tetap dari booking.');
    }

    public function test_correcting_the_booking_updates_its_spk_and_memos_everywhere(): void
    {
        $booking = $this->booking();
        $spk = $this->createSpk($booking);
        $viaBooking = MaterialMemo::create(['memo_number' => 'MEMO-A-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $this->manager()->id, 'booking_id' => $booking->id]);

        $other = $this->booking(['booking_number' => 'BKG-TEST-' . uniqid()]);
        $otherSpk = $this->createSpk($other);
        $viaSpk = MaterialMemo::create(['memo_number' => 'MEMO-B-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $this->manager()->id, 'spk_id' => $otherSpk->id]);

        $this->assertSame('Toyota Fortuner VRZ - B 1234 ABC', $viaBooking->fresh()->vehicle_info, 'Memo yang dibuat dari booking sudah membaca kendaraannya.');
        $this->assertSame('Toyota Fortuner VRZ - B 1234 ABC', $viaSpk->fresh()->vehicle_info, 'Memo yang dibuat dari SPK membaca kendaraan booking SPK itu.');

        $booking->update(['vehicle_plate' => 'B 9999 XYZ', 'customer_name' => 'Budi S.', 'vehicle_model' => 'Innova']);
        $other->update(['vehicle_plate' => 'B 8888 QQ']);

        $spk->refresh();
        $this->assertSame('B 9999 XYZ', $spk->vehicle_plate);
        $this->assertSame('Budi S.', $spk->customer_name);
        $this->assertSame('Innova', $spk->vehicle_model);

        $memo = $viaBooking->fresh();
        $this->assertSame('B 9999 XYZ', $memo->vehicle_plate);
        $this->assertSame('Toyota Innova VRZ - B 9999 XYZ', $memo->vehicle_info);

        $this->assertSame('B 8888 QQ', $viaSpk->fresh()->vehicle_plate, 'Memo lewat SPK ikut berubah.');
    }

    public function test_the_memo_vehicle_cannot_be_overwritten_by_hand_once_it_belongs_to_a_booking(): void
    {
        $booking = $this->booking();
        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-C-' . uniqid(), 'store_id' => $this->store->id, 'created_by' => $this->manager()->id,
            'booking_id' => $booking->id, 'vehicle_brand' => 'Tiba-tiba lain', 'vehicle_plate' => 'Z 1 Z',
        ]);

        $this->assertSame('Toyota', $memo->fresh()->vehicle_brand);
        $this->assertSame('B 1234 ABC', $memo->fresh()->vehicle_plate);

        $memo->update(['vehicle_model' => 'Diubah tangan']);
        $this->assertSame('Fortuner', $memo->fresh()->vehicle_model);
    }

    public function test_when_the_switch_is_on_spk_and_memo_must_belong_to_a_booking(): void
    {
        $manager = $this->manager();
        $manual = ['customer_name' => 'Tanpa Booking', 'checklist_items' => [['category' => 'pekerjaan', 'label' => 'PPF', 'is_checked' => true]]];

        // Mati (bawaan): jalur lama tetap jalan supaya aplikasi lama tidak rusak.
        config(['features.booking_required_for_spk_memo' => false]);
        $this->actingAs($manager, 'api')->postJson('/api/staff/spks', $manual)->assertStatus(201);

        config(['features.booking_required_for_spk_memo' => true]);
        $this->actingAs($manager, 'api')->postJson('/api/staff/spks', $manual)->assertStatus(422)
            ->assertJsonPath('message', 'SPK harus dibuat dari booking. Buat booking dulu kalau belum ada.');

        $booking = $this->booking();
        $this->createSpk($booking);
    }

    public function test_when_the_switch_is_on_a_memo_needs_a_booking_directly_or_through_its_spk(): void
    {
        $installer = User::create(['name' => 'Installer', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $installer->assignRole('installer');
        $booking = $this->booking();
        $spk = $this->createSpk($booking);
        $typed = ['vehicle_brand' => 'Ketik Bebas', 'vehicle_model' => 'Model X', 'vehicle_plate' => 'Z 1 Z', 'request_note' => 'Ambil film'];

        config(['features.booking_required_for_spk_memo' => true]);
        $this->actingAs($installer, 'api')->postJson('/api/staff/memos', $typed + ['spk_number' => 'KETIK-BEBAS'])->assertStatus(422)
            ->assertJsonPath('errors.spk_id.0', 'Memo harus dibuat dari SPK atau booking. Pilih SPK yang terikat booking, atau buat booking dulu.');

        $id = $this->actingAs($installer, 'api')->postJson('/api/staff/memos', $typed + ['spk_id' => $spk->id])->assertStatus(201)->json('data.id');
        $memo = MaterialMemo::findOrFail($id);
        $this->assertSame($booking->id, (int) $memo->booking_id, 'Booking SPK ikut tertaut.');
        $this->assertSame('Toyota', $memo->vehicle_brand, 'Yang diketik diganti isi booking.');
        $this->assertSame('B 1234 ABC', $memo->vehicle_plate);

        config(['features.booking_required_for_spk_memo' => false]);
        $this->actingAs($installer, 'api')->postJson('/api/staff/memos', $typed + ['spk_number' => 'KETIK-BEBAS'])->assertStatus(201);
    }

    public function test_the_spk_detail_tells_the_screen_which_power_fields_to_show_and_which_are_locked(): void
    {
        $manager = $this->manager();

        $cases = ['listrik' => [false, true], 'bensin' => [true, false], 'hybrid' => [true, true], null => [true, true]];

        foreach ($cases as $power => $expected) {
            $booking = $this->booking(['booking_number' => 'BKG-TEST-' . uniqid(), 'vehicle_power' => $power === '' ? null : $power]);
            $spk = $this->createSpk($booking);

            $response = $this->actingAs($manager, 'api')->getJson("/api/staff/spks/{$spk->id}")->assertOk();
            $response->assertJsonPath('vehicle.shows_fuel', $expected[0])->assertJsonPath('vehicle.shows_battery', $expected[1]);
            $this->assertContains('vehicle_plate', $response->json('vehicle.locked'));
            $this->assertContains('customer_name', $response->json('vehicle.locked'));
        }

        $this->assertTrue(VehicleFormat::showsFuel(null) && VehicleFormat::showsBattery(null));
        $this->assertSame(['bensin', 'listrik', 'hybrid'], array_keys(VehicleFormat::POWERS));
    }

    public function test_the_prefill_for_a_new_spk_carries_the_power_info_of_the_booking(): void
    {
        $booking = $this->booking(['vehicle_power' => 'listrik']);

        $this->actingAs($this->manager(), 'api')->getJson("/api/staff/spks/prefill-from-booking?booking_id={$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.vehicle.shows_fuel', false)
            ->assertJsonPath('data.vehicle.shows_battery', true)
            ->assertJsonPath('data.vehicle.power_label', 'Listrik');
    }

    public function test_power_type_is_kept_on_the_customer_vehicle_copied_to_the_booking_and_set_by_staff(): void
    {
        $customer = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        $vehicle = Vehicle::create(['brand' => 'Wuling', 'model' => 'Air EV', 'variant' => null, 'size_category' => 'S']);

        $created = $this->actingAs($customer, 'customer')->postJson('/api/customer/vehicles', ['vehicle_id' => $vehicle->id, 'plate' => 'B 5555 KLM', 'vehicle_power' => 'listrik'])
            ->assertStatus(201)->assertJsonPath('data.vehicle_power', 'listrik');

        $this->actingAs($customer, 'customer')->postJson('/api/customer/vehicles', ['vehicle_id' => $vehicle->id, 'plate' => 'B 6666 KLM', 'vehicle_power' => 'diesel'])->assertStatus(422);

        $booking = $this->actingAs($customer, 'customer')->postJson('/api/customer/bookings', [
            'store_id' => $this->store->id, 'preferred_date' => now()->addDays(4)->toDateString(), 'services' => ['kaca_film'],
            'customer_vehicle_id' => $created->json('data.id'), 'privacy_consent' => true,
        ])->assertStatus(201);

        $this->assertSame('listrik', Booking::findOrFail($booking->json('data.id'))->vehicle_power);

        $staffBooking = $this->actingAs($this->manager(), 'api')->postJson('/api/staff/bookings', [
            'source' => 'walk_in', 'customer_name' => 'Joni', 'phone_number' => '0899', 'services' => ['kaca_film'],
            'preferred_date' => now()->addDays(5)->toDateString(), 'vehicle_power' => 'hybrid',
        ])->assertStatus(201);

        $this->assertSame('hybrid', Booking::findOrFail($staffBooking->json('data.id'))->vehicle_power);
        $this->actingAs($this->manager(), 'api')->postJson('/api/staff/bookings', [
            'source' => 'walk_in', 'customer_name' => 'Joni', 'phone_number' => '0899', 'services' => ['detailing'], 'confirm' => false,
            'preferred_date' => now()->addDays(6)->toDateString(), 'vehicle_power' => 'diesel',
        ])->assertStatus(422);
    }
}
