<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use App\Services\AlphaCleanupService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 2026-11-11: (1) Hapus Alpha satu tanggal yang ternyata hari libur (libur lupa ditetapkan; tanggal lewat tidak bisa ditutup di Kalender),
 * dan (2) laporan kegagalan absen dari aplikasi ke Riwayat Aktivitas. "Hari ini" dibekukan di 11 November 2026.
 */
class AlphaCleanupAndFailureReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private User $admin;
    private User $andi;
    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-11 10:00:00');

        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->admin = $this->user('super_admin', null, 'Admin');
        $this->andi = $this->user('kasir', $this->storeA, 'Andi');
        $this->budi = $this->user('kasir', $this->storeB, 'Budi');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store, string $name): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id]), fn (User $u) => $u->assignRole($role));
    }

    private function att(User $user, string $date, string $type): Attendance
    {
        return Attendance::create(['user_id' => $user->id, 'store_id' => $user->store_id, 'date' => $date, 'entry_type' => $type]);
    }

    public function test_it_removes_only_alpha_rows_of_that_day_and_logs_a_summary(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->att($this->andi, '2026-11-10', 'alpha');
        $this->att($this->budi, '2026-11-10', 'alpha');
        $this->att($this->andi, '2026-11-09', 'alpha');
        $leave = $this->att($this->budi, '2026-11-09', 'leave');

        $removed = app(AlphaCleanupService::class)->remove('2026-11-10', null, 'Libur Nasional', $this->admin);

        $this->assertSame(2, $removed);
        $this->assertSame(0, Attendance::where('entry_type', 'alpha')->whereDate('date', '2026-11-10')->count());
        $this->assertSame(1, Attendance::where('entry_type', 'alpha')->whereDate('date', '2026-11-09')->count(), 'Tanggal lain tidak tersentuh.');
        $this->assertTrue($leave->fresh() !== null, 'Izin/cuti tidak tersentuh.');

        $log = Activity::where('log_name', 'attendance_alpha_cleanup')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, $log->properties['count']);
        $this->assertSame('Libur Nasional', $log->properties['reason']);
        $this->assertSame($this->admin->id, $log->causer_id);
    }

    public function test_it_can_be_limited_to_one_store(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->att($this->andi, '2026-11-10', 'alpha');
        $this->att($this->budi, '2026-11-10', 'alpha');

        $removed = app(AlphaCleanupService::class)->remove('2026-11-10', $this->storeA->id, 'Renovasi Toko A', $this->admin);

        $this->assertSame(1, $removed);
        $this->assertSame(1, Attendance::where('entry_type', 'alpha')->count(), 'Alpha Toko B tetap.');
        $this->assertSame($this->budi->id, Attendance::where('entry_type', 'alpha')->value('user_id'));
    }

    public function test_it_refuses_today_future_too_old_and_blank_reason(): void
    {
        $this->actingAs($this->admin, 'web');
        $service = app(AlphaCleanupService::class);

        foreach ([['2026-11-11', 'x'], ['2026-11-15', 'x'], ['2026-09-01', 'x'], ['2026-11-10', '   ']] as [$date, $reason]) {
            try {
                $service->remove($date, null, $reason, $this->admin);
                $this->fail("Seharusnya ditolak: {$date} / '{$reason}'");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(0, Activity::where('log_name', 'attendance_alpha_cleanup')->count());
    }

    public function test_it_returns_zero_and_logs_nothing_when_no_alpha_exists(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->assertSame(0, app(AlphaCleanupService::class)->remove('2026-11-10', null, 'Libur', $this->admin));
        $this->assertSame(0, Activity::where('log_name', 'attendance_alpha_cleanup')->count());
    }

    public function test_the_list_action_is_full_access_only_and_deletes_alpha(): void
    {
        $this->att($this->andi, '2026-11-10', 'alpha');

        $this->actingAs($this->andi, 'web');
        Livewire::test(ListAttendances::class)->assertActionHidden('clearAlphaForHoliday');

        $this->actingAs($this->admin, 'web');
        Livewire::test(ListAttendances::class)
            ->assertActionVisible('clearAlphaForHoliday')
            ->callAction('clearAlphaForHoliday', ['date' => '2026-11-10', 'store_id' => null, 'reason' => 'Libur Nasional'])
            ->assertHasNoActionErrors();

        $this->assertSame(0, Attendance::where('entry_type', 'alpha')->count());
    }

    public function test_client_failure_report_is_logged_without_coordinates(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->withHeaders(['X-App-Version' => '1.0.9', 'X-App-Platform' => 'android'])
            ->postJson('/api/staff/attendance/client-failure', [
                'action' => 'clock_in',
                'stage' => 'locating',
                'reason' => 'gps_timeout',
                'message' => 'GPS belum mendapat sinyal',
                'latitude' => -6.2,
            ]);

        $response->assertCreated();
        $log = Activity::where('log_name', 'attendance_failure')->first();
        $this->assertNotNull($log);
        $this->assertSame('gps_timeout', $log->properties['reason']);
        $this->assertSame('android', $log->properties['platform']);
        $this->assertSame('1.0.9', $log->properties['app_version']);
        $this->assertArrayNotHasKey('latitude', $log->properties->toArray(), 'Koordinat tidak disimpan.');
        $this->assertSame($this->admin->id, $log->causer_id);
    }

    public function test_client_failure_report_rejects_unknown_values(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/staff/attendance/client-failure', ['action' => 'clock_in', 'stage' => 'locating', 'reason' => 'asal-asalan'])
            ->assertStatus(422);
    }

    public function test_client_failure_report_requires_login(): void
    {
        $this->postJson('/api/staff/attendance/client-failure', ['action' => 'clock_in', 'stage' => 'locating', 'reason' => 'gps_timeout'])
            ->assertStatus(401);
    }
}
