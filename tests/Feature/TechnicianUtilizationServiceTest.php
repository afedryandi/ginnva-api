<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianUtilizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Temuan PRIORITAS TINGGI dari audit Majoo vs Ginnva. Lihat
 * TechnicianUtilizationService untuk definisi metrik & keterbatasannya
 * (Jam Job adalah estimasi dari duration_days, bukan timestamp riil).
 */
class TechnicianUtilizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): Store
    {
        return Store::create([
            'name' => 'Toko Test',
            'is_active' => true,
            // Buka 08:00-16:00 semua hari — 8 jam standar per hari.
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '08:00', 'close' => '16:00', 'closed' => false],
            ],
        ]);
    }

    private function makeBooking(Store $store, array $overrides = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001' . rand(0, 9)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'duration_days' => 1,
            'status' => 'completed',
        ], $overrides));
    }

    public function test_utilization_computed_from_attendance_and_completed_bookings(): void
    {
        $store = $this->makeStore();
        $user = User::create(['name' => 'Teknisi A', 'email' => 'teknisi-a@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $technician = Technician::create(['store_id' => $store->id, 'user_id' => $user->id, 'name' => 'Teknisi A', 'status' => 'active']);

        $today = Carbon::today();

        // Hadir 8 jam hari ini (08:00-16:00, pas 1 hari kerja penuh).
        \App\Models\Attendance::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => $today->toDateString(),
            'entry_type' => 'clock',
            'clock_in_at' => $today->copy()->setTime(8, 0),
            'clock_out_at' => $today->copy()->setTime(16, 0),
        ]);

        // 1 booking selesai, duration_days=1 -> estimasi 8 jam job (toko
        // buka 8 jam/hari) -> utilisasi harus 100%.
        $booking = $this->makeBooking($store, ['preferred_date' => $today->toDateString(), 'duration_days' => 1]);
        $booking->installers()->attach($user->id);

        $rows = app(TechnicianUtilizationService::class)->summarize(
            $today->copy()->startOfMonth(),
            $today->copy()->endOfMonth(),
        );

        $row = $rows->firstWhere('technician_id', $technician->id);

        $this->assertNotNull($row);
        $this->assertSame(8.0, $row['present_hours']);
        $this->assertSame(8.0, $row['job_hours']);
        $this->assertSame(100.0, $row['utilization_percent']);
        $this->assertSame(0.0, $row['idle_hours']);
    }

    public function test_technician_without_user_account_has_null_present_hours(): void
    {
        $store = $this->makeStore();
        $technician = Technician::create(['store_id' => $store->id, 'name' => 'Teknisi Baru', 'status' => 'active']);

        $rows = app(TechnicianUtilizationService::class)->summarize(
            Carbon::today()->startOfMonth(),
            Carbon::today()->endOfMonth(),
        );

        $row = $rows->firstWhere('technician_id', $technician->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['has_account']);
        $this->assertNull($row['present_hours']);
        $this->assertNull($row['utilization_percent']);
    }

    public function test_cancelled_bookings_are_excluded_from_job_hours(): void
    {
        $store = $this->makeStore();
        $user = User::create(['name' => 'Teknisi B', 'email' => 'teknisi-b@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $technician = Technician::create(['store_id' => $store->id, 'user_id' => $user->id, 'name' => 'Teknisi B', 'status' => 'active']);

        $today = Carbon::today();

        $booking = $this->makeBooking($store, [
            'preferred_date' => $today->toDateString(),
            'duration_days' => 1,
            'status' => 'cancelled',
        ]);
        $booking->installers()->attach($user->id);

        $rows = app(TechnicianUtilizationService::class)->summarize(
            $today->copy()->startOfMonth(),
            $today->copy()->endOfMonth(),
        );

        $row = $rows->firstWhere('technician_id', $technician->id);

        $this->assertSame(0.0, $row['job_hours']);
    }
}
