<?php

namespace Tests\Feature;

use App\Models\Attendance;
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
 * Utilisasi Teknisi (service): jam hadir dari absensi, jam job estimasi dari
 * durasi booking selesai x jam operasional toko (dipotong ke rentang laporan),
 * jam idle & persen utilisasi, teknisi tanpa akun, dan filter toko/status.
 */
class TechnicianUtilizationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $hours = [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']];
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'opening_hours' => $hours]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true, 'opening_hours' => $hours]);
    }

    private function technician(string $name, ?Store $store = null, bool $withAccount = true, string $status = 'active'): array
    {
        $store ??= $this->store;
        $user = $withAccount
            ? User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id])
            : null;
        $technician = Technician::create(['store_id' => $store->id, 'user_id' => $user?->id, 'name' => $name, 'level' => 'intermediate', 'status' => $status]);

        return [$technician, $user];
    }

    private function present(User $user, string $date, string $in = '09:00', string $out = '18:00'): void
    {
        Attendance::create(['user_id' => $user->id, 'store_id' => $user->store_id, 'date' => $date, 'entry_type' => 'clock', 'clock_in_at' => "{$date} {$in}:00", 'clock_out_at' => "{$date} {$out}:00"]);
    }

    private function job(User $user, string $date, int $days = 1, string $status = 'completed'): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $user->store_id, 'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true, 'preferred_date' => $date, 'duration_days' => $days, 'status' => $status,
        ]);
        $booking->installers()->attach($user->id);

        return $booking;
    }

    private function row(array $rows, int $technicianId): array
    {
        return collect($rows)->firstWhere('technician_id', $technicianId);
    }

    private function summarize(string $from = '2026-09-07', string $to = '2026-09-13', ?int $storeId = null)
    {
        return app(TechnicianUtilizationService::class)->summarize(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay(), $storeId);
    }

    public function test_utilization_is_job_hours_over_present_hours(): void
    {
        [$tech, $user] = $this->technician('Andi');
        $this->present($user, '2026-09-07');
        $this->present($user, '2026-09-08');
        $this->job($user, '2026-09-07');

        $row = $this->row($this->summarize()->all(), $tech->id);

        $this->assertSame(18.0, $row['present_hours']);
        $this->assertSame(9.0, $row['job_hours'], '1 hari x (18:00 - 09:00).');
        $this->assertSame(9.0, $row['idle_hours']);
        $this->assertSame(50.0, $row['utilization_percent']);
        $this->assertTrue($row['has_account']);
    }

    public function test_multi_day_jobs_are_clipped_to_the_report_range(): void
    {
        [$tech, $user] = $this->technician('Budi');
        $this->present($user, '2026-09-13');
        $this->job($user, '2026-09-13', 3); // hanya 13 Sep yang masuk rentang 7-13 Sep

        $this->assertSame(9.0, $this->row($this->summarize()->all(), $tech->id)['job_hours']);
    }

    public function test_only_completed_jobs_inside_the_range_count(): void
    {
        [$tech, $user] = $this->technician('Citra');
        $this->present($user, '2026-09-07');
        $this->job($user, '2026-09-07', 1, 'confirmed');
        $this->job($user, '2026-09-08', 1, 'cancelled');
        $this->job($user, '2026-09-20', 1, 'completed');

        $this->assertSame(0.0, $this->row($this->summarize()->all(), $tech->id)['job_hours']);
    }

    public function test_attendance_without_a_clock_out_does_not_count_as_present_hours(): void
    {
        [$tech, $user] = $this->technician('Dewi');
        Attendance::create(['user_id' => $user->id, 'store_id' => $user->store_id, 'date' => '2026-09-07', 'entry_type' => 'clock', 'clock_in_at' => '2026-09-07 09:00:00']);

        $row = $this->row($this->summarize()->all(), $tech->id);

        $this->assertSame(0.0, $row['present_hours']);
        $this->assertNull($row['utilization_percent'], 'Tanpa jam hadir tidak ada persen (bukan 0% atau pembagian nol).');
    }

    public function test_a_technician_without_an_account_has_no_present_hours_or_percentage(): void
    {
        [$tech] = $this->technician('Eko', null, false);

        $row = $this->row($this->summarize()->all(), $tech->id);

        $this->assertFalse($row['has_account']);
        $this->assertNull($row['present_hours']);
        $this->assertNull($row['idle_hours']);
        $this->assertNull($row['utilization_percent']);
    }

    public function test_inactive_technicians_are_excluded_and_the_store_filter_applies(): void
    {
        [$mine] = $this->technician('Fajar');
        [$inactive] = $this->technician('Gita', null, true, 'inactive');
        [$theirs] = $this->technician('Hadi', $this->otherStore);

        $all = $this->summarize()->pluck('technician_id')->all();
        $filtered = $this->summarize('2026-09-07', '2026-09-13', $this->store->id)->pluck('technician_id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $all);
        $this->assertNotContains($inactive->id, $all);
        $this->assertSame([$mine->id], $filtered);
    }

    public function test_utilization_can_exceed_one_hundred_percent_when_the_estimate_is_above_attendance(): void
    {
        [$tech, $user] = $this->technician('Indra');
        $this->present($user, '2026-09-07', '09:00', '13:30'); // 4,5 jam hadir
        $this->job($user, '2026-09-07');                       // 9 jam estimasi

        $row = $this->row($this->summarize()->all(), $tech->id);

        $this->assertSame(200.0, $row['utilization_percent']);
        $this->assertEquals(0, $row['idle_hours'], 'Idle tidak pernah negatif.');
    }
}
