<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleDayOverride;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendancePatternService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Audit Majoo f23 ("6 jenis keterlambatan/kecepatan"), dibangun
 * 2026-09-22. Fokus test: "Masuk Lebih Awal"/"Lembur" dibandingkan
 * terhadap Shift yang berlaku (via Jadwal Kerja), dan karyawan tanpa
 * jadwal ditandai "Tanpa Jadwal" bukan dipaksa false.
 */
class AttendancePatternServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeShiftSchedule(Store $store): WorkSchedule
    {
        $shift = Shift::create([
            'store_id' => $store->id,
            'name' => 'Pagi',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        return WorkSchedule::create([
            'store_id' => $store->id,
            'name' => 'Reguler',
            'days' => collect(WorkSchedule::DAYS)->map(fn ($day) => ['day' => $day, 'shift_id' => $shift->id])->toArray(),
        ]);
    }

    public function test_early_arrival_and_overtime_detected_against_shift(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $user = User::create(['name' => 'A', 'email' => 'a@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $schedule = $this->makeShiftSchedule($store);
        EmployeeScheduleAssignment::assignBulk($schedule, [$user->id], Carbon::parse('2026-09-01'), null);

        // Senin, 7 Sep 2026 -- masuk jam 07:30 (lebih awal dari 08:00),
        // pulang jam 19:00 (lebih lambat dari 17:00).
        $attendance = Attendance::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-07',
            'entry_type' => 'clock',
            'clock_in_at' => '2026-09-07 07:30:00',
            'clock_out_at' => '2026-09-07 19:00:00',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $result = app(AttendancePatternService::class)->classify(collect([$attendance]))->first();

        $this->assertTrue($result['isEarlyArrival']);
        $this->assertTrue($result['isOvertime']);
        $this->assertTrue($result['hasSchedule']);
    }

    public function test_no_schedule_flagged_when_no_assignment_exists(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $user = User::create(['name' => 'B', 'email' => 'b@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-07',
            'entry_type' => 'clock',
            'clock_in_at' => '2026-09-07 08:00:00',
            'clock_out_at' => '2026-09-07 17:00:00',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $result = app(AttendancePatternService::class)->classify(collect([$attendance]))->first();

        $this->assertFalse($result['hasSchedule']);
        $this->assertFalse($result['isEarlyArrival']);
        $this->assertFalse($result['isOvertime']);
    }

    public function test_day_override_takes_precedence_over_template(): void
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $user = User::create(['name' => 'C', 'email' => 'c@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $schedule = $this->makeShiftSchedule($store); // shift 08:00-17:00
        EmployeeScheduleAssignment::assignBulk($schedule, [$user->id], Carbon::parse('2026-09-01'), null);

        $nightShift = Shift::create([
            'store_id' => $store->id,
            'name' => 'Malam',
            'start_time' => '20:00',
            'end_time' => '23:00',
        ]);

        ScheduleDayOverride::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-07',
            'shift_id' => $nightShift->id,
        ]);

        // Masuk jam 19:30 -- lebih awal dari shift override (20:00), TAPI
        // kalau template lama (08:00) yang dipakai, ini akan LEBIH LAMBAT
        // dari 08:00, bukan lebih awal. Override HARUS menang.
        $attendance = Attendance::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-07',
            'entry_type' => 'clock',
            'clock_in_at' => '2026-09-07 19:30:00',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $result = app(AttendancePatternService::class)->classify(collect([$attendance]))->first();

        $this->assertTrue($result['isEarlyArrival']);
    }
}
