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
            'privacy_consent' => true,
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

    private function installer(string $name): User
    {
        Role::findOrCreate('installer', 'web');
        $user = User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole('installer');

        return $user;
    }

    private function leave(User $user, string $status): void
    {
        \App\Models\LeaveRequest::create([
            'request_number' => 'REQ-' . uniqid(), 'user_id' => $user->id, 'store_id' => $this->store->id, 'type' => 'cuti',
            'start_date' => $this->date, 'end_date' => $this->date, 'reason' => 'x', 'status' => $status,
        ]);
    }

    private function heavyBooking(array $overrides = []): Booking
    {
        return $this->makeBooking(array_merge(['service_type' => 'PPF', 'product_detailing' => false, 'product_ppf' => true, 'preferred_time' => null], $overrides));
    }

    public function test_installer_availability_excludes_approved_leave_and_day_off_overrides(): void
    {
        $a = $this->installer('Andi');
        $b = $this->installer('Budi');
        $this->installer('Cici');

        $service = app(\App\Services\InstallerAvailabilityService::class);
        $day = Carbon::parse($this->date);

        $this->assertSame(3, $service->forDate($this->store->id, $day)['available']);

        $this->leave($a, 'approved');
        $this->leave($b, 'pending');

        $result = $service->forDate($this->store->id, $day);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['available'], 'Hanya cuti approved yang mengurangi.');
        $this->assertSame('Andi', $result['unavailable'][0]['name']);

        \App\Models\ScheduleDayOverride::create(['user_id' => $b->id, 'store_id' => $this->store->id, 'date' => $this->date, 'shift_id' => null]);
        $this->assertSame(1, $service->forDate($this->store->id, $day)['available']);
    }

    public function test_confirming_a_heavy_booking_warns_when_installers_are_short_but_still_confirms(): void
    {
        $staff = $this->staff();
        $this->store->update(['install_capacity_per_day' => 5]);
        $this->installer('Andi');

        $this->heavyBooking();
        $second = $this->heavyBooking(['status' => 'pending']);

        $response = $this->actingAs($staff, 'api')->postJson("/api/staff/bookings/{$second->id}/confirm")->assertOk();

        $this->assertSame('confirmed', $second->fresh()->status);
        $this->assertCount(1, $response->json('warnings'));
        $this->assertStringContainsString('1 dari 1 installer', $response->json('warnings.0'));
    }

    public function test_confirming_without_a_shortage_returns_no_warning(): void
    {
        $staff = $this->staff();
        $this->installer('Andi');
        $booking = $this->heavyBooking(['status' => 'pending']);

        $this->actingAs($staff, 'api')->postJson("/api/staff/bookings/{$booking->id}/confirm")->assertOk()->assertJsonPath('warnings', []);
    }

    public function test_capacity_overview_reports_installer_counts(): void
    {
        $staff = $this->staff();
        $a = $this->installer('Andi');
        $this->installer('Budi');
        $this->leave($a, 'approved');

        $row = collect($this->actingAs($staff, 'api')->getJson("/api/staff/bookings/capacity-overview?store_id={$this->store->id}&days=10")
            ->assertOk()->json('data'))->firstWhere('date', $this->date);

        $this->assertSame(['total' => 2, 'available' => 1], $row['installers']);
    }

    public function test_full_dates_with_duration_blocks_start_dates_whose_later_days_are_full(): void
    {
        $this->store->update(['opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']]]);
        \Illuminate\Support\Facades\Cache::flush();

        // Hari ke-5 penuh (kapasitas 1, satu booking berat 1 hari).
        $this->heavyBooking(['duration_days' => 1]);

        $single = $this->getJson("/api/stores/{$this->store->id}/full-dates")->assertOk()->json('data');
        $this->assertContains($this->date, $single);
        $this->assertNotContains(now()->addDays(4)->toDateString(), $single, 'Tanpa durasi hanya hari itu sendiri.');

        $three = $this->getJson("/api/stores/{$this->store->id}/full-dates?duration_days=3")->assertOk()->json('data');
        foreach ([3, 4, 5] as $offset) {
            $this->assertContains(now()->addDays($offset)->toDateString(), $three, "Mulai H+{$offset} melewati hari penuh H+5.");
        }
        $this->assertNotContains(now()->addDays(2)->toDateString(), $three);
        $this->assertNotContains(now()->addDays(6)->toDateString(), $three);
    }

    public function test_light_slots_follow_the_store_opening_hours(): void
    {
        $this->store->update(['opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '10:00', 'close' => '14:00']]]);
        $day = Carbon::parse($this->date);

        $this->assertSame(['11:00 - 13:00'], Booking::lightSlotsFor($this->store->id, $day), 'Hanya slot yang seluruhnya di dalam 10:00-14:00.');

        $listed = collect(Booking::lightSlotAvailability($this->store->id, $day))->pluck('slot')->all();
        $this->assertSame(['11:00 - 13:00'], $listed);

        $this->book(['premium_wash'], ['preferred_time' => '15:00 - 17:00'])->assertStatus(422);
        $this->book(['premium_wash'], ['preferred_time' => '11:00 - 13:00'])->assertStatus(201);

        $apiSlots = collect($this->getJson("/api/stores/{$this->store->id}/light-slots?date={$this->date}")->assertOk()->json('data.slots'))->pluck('slot')->all();
        $this->assertSame(['11:00 - 13:00'], $apiSlots);
    }

    public function test_a_date_counts_as_full_when_every_slot_inside_the_opening_hours_is_taken(): void
    {
        $this->store->update(['opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '10:00', 'close' => '14:00']]]);

        $this->assertNotContains($this->date, Booking::lightFullDates($this->store->id, today(), today()->addDays(30)));

        $this->makeBooking(['preferred_time' => '11:00 - 13:00']); // satu-satunya slot yang berlaku, kapasitas 1

        $this->assertContains($this->date, Booking::lightFullDates($this->store->id, today(), today()->addDays(30)), 'Slot di luar jam buka tidak boleh menghalangi tanggal dinyatakan penuh.');
    }

    public function test_stores_without_opening_hours_keep_all_standard_slots(): void
    {
        $this->store->update(['opening_hours' => null]);

        $this->assertSame(Booking::lightSlots(), Booking::lightSlotsFor($this->store->id, Carbon::parse($this->date)));
    }

    public function test_opening_hours_with_seconds_are_compared_correctly(): void
    {
        $this->store->update(['opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00:00', 'close' => '17:00:00']]]);

        $this->assertSame(Booking::lightSlots(), Booking::lightSlotsFor($this->store->id, Carbon::parse($this->date)), '09:00:00-17:00:00 mencakup semua slot standar.');
    }
}

