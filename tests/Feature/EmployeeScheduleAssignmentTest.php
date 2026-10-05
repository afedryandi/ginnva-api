<?php

namespace Tests\Feature;

use App\Models\EmployeeScheduleAssignment;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Modul "Jadwal Kerja" — audit Majoo vs Ginnva, dibangun 2026-09-22.
 * Fokus test: assignBulk() tidak pernah menyisakan 2 penugasan "terbuka"
 * (effective_to null) sekaligus untuk 1 karyawan, dan histori lama
 * tetap utuh (bukan dihapus/ditimpa) — itu inti temuan "Tanggal Efektif"
 * & "Riwayat Jadwal Kerja".
 */
class EmployeeScheduleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(Store $store, string $name = 'Jadwal Reguler'): WorkSchedule
    {
        $shift = Shift::firstOrCreate(['store_id' => $store->id, 'name' => 'Pagi'], [
            'start_time' => '08:00',
            'end_time' => '17:00',
            'is_active' => true,
        ]);

        return WorkSchedule::create([
            'store_id' => $store->id,
            'name' => $name,
            'is_active' => true,
            'days' => collect(WorkSchedule::DAYS)->map(fn ($day) => [
                'day' => $day,
                'shift_id' => $day === 'sun' ? null : $shift->id,
            ])->toArray(),
        ]);
    }

    public function test_assign_bulk_creates_open_assignment_for_each_user(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $schedule = $this->makeSchedule($store);
        $userA = User::create(['name' => 'A', 'email' => 'a@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $userB = User::create(['name' => 'B', 'email' => 'b@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $count = EmployeeScheduleAssignment::assignBulk($schedule, [$userA->id, $userB->id], today()->subDays(5), null);

        $this->assertSame(2, $count);
        $this->assertSame(1, EmployeeScheduleAssignment::where('user_id', $userA->id)->count());
        $this->assertNull(EmployeeScheduleAssignment::where('user_id', $userA->id)->first()->effective_to);
    }

    public function test_reassigning_closes_previous_open_assignment_and_keeps_history(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $scheduleA = $this->makeSchedule($store, 'Jadwal A');
        $scheduleB = $this->makeSchedule($store, 'Jadwal B');
        $user = User::create(['name' => 'A', 'email' => 'a@test.local', 'password' => 'x', 'store_id' => $store->id]);

        // Tanggal relatif: service menolak tanggal efektif > 31 hari ke belakang.
        $startA = today()->subDays(20);
        $startB = today()->subDays(5);
        EmployeeScheduleAssignment::assignBulk($scheduleA, [$user->id], $startA, null);
        EmployeeScheduleAssignment::assignBulk($scheduleB, [$user->id], $startB, null);

        $this->assertSame(2, EmployeeScheduleAssignment::where('user_id', $user->id)->count());

        $old = EmployeeScheduleAssignment::where('user_id', $user->id)->where('work_schedule_id', $scheduleA->id)->first();
        $new = EmployeeScheduleAssignment::where('user_id', $user->id)->where('work_schedule_id', $scheduleB->id)->first();

        // Histori lama TETAP ADA, cuma ditutup sehari sebelum yang baru mulai.
        $this->assertNotNull($old);
        $this->assertTrue($old->effective_to->isSameDay($startB->copy()->subDay()));
        $this->assertNull($new->effective_to);
    }

    public function test_reassigning_before_a_later_start_date_is_rejected(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $scheduleA = $this->makeSchedule($store, 'Jadwal A');
        $scheduleB = $this->makeSchedule($store, 'Jadwal B');
        $user = User::create(['name' => 'A', 'email' => 'a@test.local', 'password' => 'x', 'store_id' => $store->id]);

        // Penugasan A dijadwalkan untuk masa depan...
        EmployeeScheduleAssignment::assignBulk($scheduleA, [$user->id], today()->addDays(30), null);

        // ...menugaskan jadwal LAIN yang berlaku LEBIH AWAL ditolak (aturan model:
        // "Pilih tanggal yang lebih baru"), penugasan A tetap utuh.
        $this->expectException(\InvalidArgumentException::class);
        try {
            EmployeeScheduleAssignment::assignBulk($scheduleB, [$user->id], today()->addDays(1), null);
        } finally {
            $this->assertSame(1, EmployeeScheduleAssignment::where('user_id', $user->id)->count());
            $this->assertSame($scheduleA->id, EmployeeScheduleAssignment::where('user_id', $user->id)->first()->work_schedule_id);
        }
    }

    public function test_work_schedule_shift_id_for_returns_null_on_day_off(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $schedule = $this->makeSchedule($store);

        $this->assertNotNull($schedule->shiftIdFor('mon'));
        $this->assertNull($schedule->shiftIdFor('sun'));
    }

    public function test_active_for_returns_correct_assignment_by_date(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $scheduleA = $this->makeSchedule($store, 'Jadwal A');
        $scheduleB = $this->makeSchedule($store, 'Jadwal B');
        $user = User::create(['name' => 'A', 'email' => 'a@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $startA = today()->subDays(25);
        $startB = today()->subDays(5);
        EmployeeScheduleAssignment::assignBulk($scheduleA, [$user->id], $startA, null);
        EmployeeScheduleAssignment::assignBulk($scheduleB, [$user->id], $startB, null);

        $activeInSeptember = EmployeeScheduleAssignment::activeFor($user->id, today()->subDays(15));
        $activeInOctober = EmployeeScheduleAssignment::activeFor($user->id, today());

        $this->assertSame($scheduleA->id, $activeInSeptember->work_schedule_id);
        $this->assertSame($scheduleB->id, $activeInOctober->work_schedule_id);
    }
}
