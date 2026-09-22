<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Spk;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianServiceDurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Temuan PRIORITAS TINGGI dari audit Majoo vs Ginnva ("Komisi
 * Bertingkat berbasis Durasi Layanan Selesai"). Lihat
 * TechnicianServiceDurationService untuk definisi metrik &
 * keterbatasannya — durasi AKTUAL dari Spk::checked_in_at/
 * checked_out_at, bukan estimasi standar per produk.
 */
class TechnicianServiceDurationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeBookingWithSpk(Store $store, array $checkTimes): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . rand(1000000, 9999999)]);

        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'duration_days' => 1,
            'status' => 'confirmed',
        ]);

        Spk::create([
            'spk_number' => 'SPK-TEST-' . uniqid(),
            'store_id' => $store->id,
            'booking_id' => $booking->id,
            'customer_name' => 'Budi',
            'vehicle_plate' => 'B 1234 XYZ',
            'checked_in_at' => $checkTimes[0],
            'checked_out_at' => $checkTimes[1],
        ]);

        return $booking;
    }

    public function test_duration_accumulated_from_spk_checkin_checkout(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $user = User::create(['name' => 'Teknisi A', 'email' => 'teknisi-a@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $technician = Technician::create(['store_id' => $store->id, 'user_id' => $user->id, 'name' => 'Teknisi A', 'status' => 'active']);

        $today = Carbon::today();
        $booking = $this->makeBookingWithSpk($store, [
            $today->copy()->setTime(8, 0),
            $today->copy()->setTime(11, 30),
        ]);
        $booking->installers()->attach($user->id);

        $rows = app(TechnicianServiceDurationService::class)->summarize(
            $today->copy()->startOfMonth(),
            $today->copy()->endOfMonth(),
        );

        $row = $rows->firstWhere('technician_id', $technician->id);

        $this->assertNotNull($row);
        $this->assertSame(210, $row['total_minutes']); // 3.5 jam
        $this->assertSame(3.5, $row['total_hours']);
        $this->assertSame(1, $row['job_count']);
    }

    public function test_team_job_credits_full_duration_to_each_installer(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $userA = User::create(['name' => 'Teknisi A', 'email' => 'a@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $userB = User::create(['name' => 'Teknisi B', 'email' => 'b@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $techA = Technician::create(['store_id' => $store->id, 'user_id' => $userA->id, 'name' => 'Teknisi A', 'status' => 'active']);
        $techB = Technician::create(['store_id' => $store->id, 'user_id' => $userB->id, 'name' => 'Teknisi B', 'status' => 'active']);

        $today = Carbon::today();
        $booking = $this->makeBookingWithSpk($store, [
            $today->copy()->setTime(9, 0),
            $today->copy()->setTime(10, 0),
        ]);
        $booking->installers()->attach([$userA->id, $userB->id]);

        $rows = app(TechnicianServiceDurationService::class)->summarize(
            $today->copy()->startOfMonth(),
            $today->copy()->endOfMonth(),
        );

        $rowA = $rows->firstWhere('technician_id', $techA->id);
        $rowB = $rows->firstWhere('technician_id', $techB->id);

        // Full 60 menit dikreditkan ke KEDUANYA, bukan dibagi 30/30.
        $this->assertSame(60, $rowA['total_minutes']);
        $this->assertSame(60, $rowB['total_minutes']);
    }

    public function test_spk_without_checkout_is_excluded(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $user = User::create(['name' => 'Teknisi A', 'email' => 'teknisi-a@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $technician = Technician::create(['store_id' => $store->id, 'user_id' => $user->id, 'name' => 'Teknisi A', 'status' => 'active']);

        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000099']);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'duration_days' => 1,
            'status' => 'confirmed',
        ]);
        $booking->installers()->attach($user->id);

        Spk::create([
            'spk_number' => 'SPK-TEST-' . uniqid(),
            'store_id' => $store->id,
            'booking_id' => $booking->id,
            'customer_name' => 'Budi',
            'vehicle_plate' => 'B 1234 XYZ',
            'checked_in_at' => now(),
            'checked_out_at' => null,
        ]);

        $rows = app(TechnicianServiceDurationService::class)->summarize(
            now()->startOfMonth(),
            now()->endOfMonth(),
        );

        $row = $rows->firstWhere('technician_id', $technician->id);

        $this->assertSame(0, $row['total_minutes']);
        $this->assertSame(0, $row['job_count']);
    }
}
