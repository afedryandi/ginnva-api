<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\BookingServiceType;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 2 pengerjaan ulang booking (2026-10-16): kapasitas BERAT (harian: PPF/kaca film) dan RINGAN (slot jam: Detailing/
 * Premium Wash). Layanan ringan tidak memakan kapasitas harian berat; booking campuran dihitung berat; jam wajib untuk
 * pelanggan; slot penuh menutup jam itu.
 */
class LightServiceCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT_A = '09:00 - 11:00';
    private const SLOT_B = '11:00 - 13:00';

    private Store $store;
    private Customer $customer;
    private int $vehicleId;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true,
            'install_capacity_per_day' => 1, 'light_capacity_per_slot' => 1,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']],
        ]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $this->vehicleId = $this->actingAs($this->customer, 'customer')->postJson('/api/customer/vehicles', ['brand' => 'Toyota', 'model' => 'Avanza', 'plate' => 'B 1234 ABC'])->json('data.id');
        $this->date = now()->addDays(5)->toDateString();
    }

    private function makeBooking(array $overrides = []): Booking
    {
        $other = Customer::create(['name' => 'Lain ' . uniqid(), 'phone_number' => '0813' . random_int(1000000, 9999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $other->id,
            'store_id' => $this->store->id,
            'service_type' => 'Detailing',
            'product_detailing' => true,
            'preferred_date' => $this->date,
            'preferred_time' => self::SLOT_A,
            'status' => 'confirmed',
        ], $overrides));
    }

    private function book(array $services, array $overrides = [])
    {
        return $this->actingAs($this->customer, 'customer')->postJson('/api/customer/bookings', array_merge([
            'store_id' => $this->store->id,
            'services' => $services,
            'customer_vehicle_id' => $this->vehicleId,
            'preferred_date' => $this->date,
        ], $overrides));
    }

    private function staff(): User
    {
        Role::findOrCreate('store_manager', 'web');
        $user = User::create(['name' => 'SM', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01']);
        $user->assignRole('store_manager');

        return $user;
    }

    // ------------------------------------------------------------- klasifikasi

    public function test_light_only_detection_follows_the_service_categories(): void
    {
        $this->assertTrue($this->makeBooking(['product_detailing' => true])->isLightOnly());
        $this->assertTrue($this->makeBooking(['product_detailing' => false, 'product_premium_wash' => true])->isLightOnly());
        $this->assertFalse($this->makeBooking(['product_detailing' => true, 'product_ppf' => true])->isLightOnly(), 'Campuran dihitung berat.');
        $this->assertFalse($this->makeBooking(['product_detailing' => false, 'product_kaca_film' => true])->isLightOnly());
        $this->assertFalse($this->makeBooking(['product_detailing' => false])->isLightOnly(), 'Tanpa penanda produk = berat (konservatif).');
    }

    public function test_changing_a_service_category_in_the_catalog_changes_the_classification(): void
    {
        $booking = $this->makeBooking();
        $this->assertTrue($booking->isLightOnly());

        BookingServiceType::where('key', 'detailing')->update(['capacity_category' => 'berat']);
        \Illuminate\Support\Facades\Cache::forget('booking_service_types.v1');

        $this->assertFalse($booking->fresh()->isLightOnly());
    }

    // ------------------------------------------------------------- kapasitas harian berat

    public function test_light_bookings_do_not_consume_the_daily_heavy_capacity(): void
    {
        $this->makeBooking(); // ringan, confirmed

        $day = Carbon::parse($this->date);
        $this->assertSame(0, Booking::confirmedOverlapCount($this->store->id, $day));
        $this->assertSame(1, Booking::confirmedOverlapCount($this->store->id, $day, null, true), 'includeLight menghitung semuanya.');

        $this->book(['ppf'])->assertStatus(201); // kapasitas harian berat (1) masih kosong
    }

    public function test_heavy_bookings_still_fill_the_daily_capacity(): void
    {
        $this->makeBooking(['service_type' => 'PPF', 'product_detailing' => false, 'product_ppf' => true, 'preferred_time' => null]);

        $this->book(['ppf'])->assertStatus(422)->assertJsonPath('errors.preferred_date.0', 'Tanggal penuh.');
        $this->book(['ppf', 'detailing'])->assertStatus(422); // campuran = berat
    }

    // ------------------------------------------------------------- slot jam ringan

    public function test_slot_availability_marks_only_the_full_slot(): void
    {
        $this->makeBooking(['preferred_time' => self::SLOT_A]);

        $slots = collect(Booking::lightSlotAvailability($this->store->id, Carbon::parse($this->date)))->keyBy('slot');

        $this->assertFalse($slots[self::SLOT_A]['available']);
        $this->assertTrue($slots[self::SLOT_B]['available']);
        $this->assertSame(1, $slots[self::SLOT_A]['capacity']);
    }

    public function test_customer_must_pick_a_valid_available_slot_for_light_services(): void
    {
        $this->book(['premium_wash'])->assertStatus(422)->assertJsonPath('errors.preferred_time.0', 'Pilih jam kedatangan untuk layanan ini.');
        $this->book(['premium_wash'], ['preferred_time' => '10:00'])->assertStatus(422)->assertJsonPath('errors.preferred_time.0', 'Jam kedatangan tidak valid. Pilih salah satu jam yang tersedia.');

        $this->makeBooking(['preferred_time' => self::SLOT_A]);

        $this->book(['premium_wash'], ['preferred_time' => self::SLOT_A])->assertStatus(422)->assertJsonPath('success', false);
        $this->book(['premium_wash'], ['preferred_time' => self::SLOT_B])->assertStatus(201);
    }

    public function test_mixed_bookings_do_not_need_a_slot_and_use_the_heavy_rules(): void
    {
        $this->book(['ppf', 'detailing'])->assertStatus(201);
    }

    public function test_light_full_dates_lists_a_date_only_when_every_slot_is_full(): void
    {
        foreach (Booking::lightSlots() as $slot) {
            $this->makeBooking(['preferred_time' => $slot]);
        }

        $this->assertContains($this->date, Booking::lightFullDates($this->store->id, today(), today()->addDays(30)));

        $other = now()->addDays(6)->toDateString();
        $this->makeBooking(['preferred_date' => $other, 'preferred_time' => self::SLOT_A]);
        $this->assertNotContains($other, Booking::lightFullDates($this->store->id, today(), today()->addDays(30)));
    }

    // ------------------------------------------------------------- endpoint publik

    public function test_light_slots_endpoint_reports_status_without_exposing_capacity(): void
    {
        $this->makeBooking(['preferred_time' => self::SLOT_A]);

        $response = $this->getJson("/api/stores/{$this->store->id}/light-slots?date={$this->date}")->assertOk();

        $slots = collect($response->json('data.slots'))->keyBy('slot');
        $this->assertFalse($slots[self::SLOT_A]['available']);
        $this->assertTrue($slots[self::SLOT_B]['available']);
        $this->assertArrayNotHasKey('capacity', $slots[self::SLOT_A]);
        $this->assertArrayNotHasKey('used', $slots[self::SLOT_A]);
    }

    public function test_light_slots_endpoint_validates_the_date_and_shows_closed_days_as_unavailable(): void
    {
        $this->getJson("/api/stores/{$this->store->id}/light-slots")->assertStatus(422);
        $this->getJson("/api/stores/{$this->store->id}/light-slots?date=" . now()->subDay()->toDateString())->assertStatus(422);

        BlockedDate::create(['store_id' => $this->store->id, 'date' => $this->date, 'reason' => 'Libur']);

        $slots = $this->getJson("/api/stores/{$this->store->id}/light-slots?date={$this->date}")->assertOk()->json('data.slots');
        $this->assertSame([], array_filter($slots, fn ($s) => $s['available']));

        $this->getJson('/api/stores/999999/light-slots?date=' . $this->date)->assertStatus(404);
        $this->getJson("/api/stores/{$this->store->id}/light-full-dates")->assertOk()->assertJsonStructure(['success', 'data']);
    }

    // ------------------------------------------------------------- konfirmasi staf dan pindah jadwal

    public function test_staff_confirmation_of_a_light_booking_checks_the_slot_not_the_daily_capacity(): void
    {
        $staff = $this->staff();
        $this->makeBooking(['preferred_time' => self::SLOT_A]); // slot A penuh

        // Booking berat mengisi kapasitas harian: tidak boleh menghalangi booking ringan.
        $this->makeBooking(['service_type' => 'PPF', 'product_detailing' => false, 'product_ppf' => true, 'preferred_time' => null]);

        $fullSlot = $this->makeBooking(['status' => 'pending', 'preferred_time' => self::SLOT_A]);
        $freeSlot = $this->makeBooking(['status' => 'pending', 'preferred_time' => self::SLOT_B]);
        $noTime = $this->makeBooking(['status' => 'pending', 'preferred_time' => null]); // data lama tanpa jam: dilewati

        $this->actingAs($staff, 'api')->postJson("/api/staff/bookings/{$fullSlot->id}/confirm")->assertStatus(422);
        $this->actingAs($staff, 'api')->postJson("/api/staff/bookings/{$freeSlot->id}/confirm")->assertOk();
        $this->actingAs($staff, 'api')->postJson("/api/staff/bookings/{$noTime->id}/confirm")->assertOk();

        $this->assertSame('pending', $fullSlot->fresh()->status);
        $this->assertSame('confirmed', $freeSlot->fresh()->status);
    }

    public function test_customer_reschedule_request_for_a_light_booking_needs_the_same_slot_free_on_the_new_date(): void
    {
        $mine = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'service_type' => 'Premium Wash', 'product_premium_wash' => true, 'preferred_date' => $this->date,
            'preferred_time' => self::SLOT_A, 'status' => 'pending',
        ]);

        $newDate = now()->addDays(8)->toDateString();
        $this->makeBooking(['preferred_date' => $newDate, 'preferred_time' => self::SLOT_A]); // jam yang sama penuh di tanggal baru

        $this->actingAs($this->customer, 'customer')->postJson("/api/customer/bookings/{$mine->id}/reschedule-request", ['requested_date' => $newDate])
            ->assertStatus(422);

        $okDate = now()->addDays(9)->toDateString();
        $this->actingAs($this->customer, 'customer')->postJson("/api/customer/bookings/{$mine->id}/reschedule-request", ['requested_date' => $okDate])
            ->assertStatus(201);
    }

    public function test_the_store_can_set_its_own_light_capacity_and_unset_falls_back_to_the_default(): void
    {
        $this->assertSame(1, Booking::lightCapacityPerSlot($this->store->id));

        $this->store->update(['light_capacity_per_slot' => 3]);
        $this->assertSame(3, Booking::lightCapacityPerSlot($this->store->id));

        $this->store->update(['light_capacity_per_slot' => null]);
        $this->assertSame((int) config('booking.light_capacity_default'), Booking::lightCapacityPerSlot($this->store->id));
    }

    public function test_staff_capacity_overview_adds_light_slots_without_changing_heavy_numbers(): void
    {
        $staff = $this->staff();
        $this->makeBooking(['preferred_time' => self::SLOT_A]);

        $rows = collect($this->actingAs($staff, 'api')->getJson("/api/staff/bookings/capacity-overview?store_id={$this->store->id}&days=10")
            ->assertOk()->json('data'))->keyBy('date');

        $row = $rows[$this->date];
        $this->assertSame(0, $row['used'], 'Booking ringan tidak masuk angka harian berat.');
        $slots = collect($row['light_slots'])->keyBy('slot');
        $this->assertSame(1, $slots[self::SLOT_A]['used']);
        $this->assertFalse($slots[self::SLOT_A]['available']);
        $this->assertTrue($slots[self::SLOT_B]['available']);
    }

    public function test_staff_capacity_preview_includes_slots_only_for_light_bookings(): void
    {
        $staff = $this->staff();
        $light = $this->makeBooking(['status' => 'pending']);
        $heavy = $this->makeBooking(['status' => 'pending', 'service_type' => 'PPF', 'product_detailing' => false, 'product_ppf' => true, 'preferred_time' => null]);

        $lightRow = $this->actingAs($staff, 'api')->getJson("/api/staff/bookings/{$light->id}/capacity-preview?duration_days=1")->assertOk()->json('data.0');
        $heavyRow = $this->actingAs($staff, 'api')->getJson("/api/staff/bookings/{$heavy->id}/capacity-preview?duration_days=1")->assertOk()->json('data.0');

        $this->assertCount(count(Booking::lightSlots()), $lightRow['light_slots']);
        $this->assertNull($heavyRow['light_slots']);
    }
}
