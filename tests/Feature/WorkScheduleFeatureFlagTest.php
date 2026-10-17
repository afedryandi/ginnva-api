<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkScheduleCalendarReport;
use App\Filament\Resources\ShiftResource;
use App\Filament\Resources\WorkScheduleResource;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 0 (2026-10-28): Ginnva tidak memakai shift (keputusan pemilik 2026-10-17), jadi menu panel dan layar aplikasi Jadwal Kerja
 * disembunyikan lewat config/features.php. Data dan kodenya tetap ada, dan fitur bisa dinyalakan lagi.
 */
class WorkScheduleFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
    }

    private function staffWithSchedule(): User
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $user = User::create(['name' => 'Budi', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'is_active' => true]);
        $user->assignRole('kasir');

        $shift = Shift::create(['store_id' => $store->id, 'name' => 'Pagi', 'start_time' => '08:30', 'end_time' => '17:00', 'is_active' => true]);
        $days = collect(WorkSchedule::DAYS)->map(fn (string $day) => ['day' => $day, 'shift_id' => $shift->id])->all();
        $schedule = WorkSchedule::create(['store_id' => $store->id, 'name' => 'Standar', 'days' => $days, 'is_active' => true]);
        EmployeeScheduleAssignment::assignBulk($schedule, [$user->id], today(), null);

        return $user;
    }

    public function test_the_schedule_menus_are_hidden_by_default_and_return_when_the_feature_is_switched_on(): void
    {
        foreach ([ShiftResource::class, WorkScheduleResource::class, WorkScheduleCalendarReport::class] as $screen) {
            $this->assertFalse($screen::shouldRegisterNavigation(), "{$screen} harus tersembunyi bila fitur mati.");
        }

        config(['features.work_schedules' => true]);

        foreach ([ShiftResource::class, WorkScheduleResource::class, WorkScheduleCalendarReport::class] as $screen) {
            $this->assertTrue($screen::shouldRegisterNavigation(), "{$screen} muncul lagi bila fitur dinyalakan.");
        }
    }

    public function test_the_staff_app_only_gets_the_schedule_screen_when_the_feature_is_on_and_the_person_has_a_schedule(): void
    {
        $withSchedule = $this->staffWithSchedule();

        $this->actingAs($withSchedule, 'api')->getJson('/api/staff/auth/me')->assertOk()
            ->assertJsonPath('user.has_work_schedule', false);   // fitur mati

        config(['features.work_schedules' => true]);

        $this->actingAs($withSchedule, 'api')->getJson('/api/staff/auth/me')->assertOk()
            ->assertJsonPath('user.has_work_schedule', true);

        $without = User::create(['name' => 'Tanpa Jadwal', 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $withSchedule->store_id, 'is_active' => true]);
        $without->assignRole('kasir');

        $this->actingAs($without, 'api')->getJson('/api/staff/auth/me')->assertOk()
            ->assertJsonPath('user.has_work_schedule', false);
    }
}
