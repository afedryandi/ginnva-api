<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 2026-11-11: (a0) pengingat absen masuk ke karyawan tepat pada jam buka, (a) peringatan belum absen masuk memakai jam buka toko bila karyawan tanpa shift dan melewati yang sedang izin/cuti,
 * (b) pengingat absen pulang ke karyawan sendiri setelah jam tutup, (c) pengingat pengajuan Izin & Cuti / Koreksi Absensi yang menunggu
 * terlalu lama. Toko buka Senin-Sabtu 09:00-18:00, toleransi telat 15 menit. "Hari ini" Rabu 11 November 2026.
 */
class AttendanceRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'super_admin', 'store_manager', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-11-11 10:00:00');

        $this->store = $this->store('Toko A');
        $this->otherStore = $this->store('Toko B');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function store(string $name): Store
    {
        return Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => $name, 'is_active' => true, 'late_tolerance_minutes' => 15,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'],
                ['days' => ['sun'], 'closed' => true],
            ],
        ]);
    }

    private function user(string $role, ?Store $store, string $name): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id, 'join_date' => '2025-01-01', 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function inboxTitles(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['title'] ?? null)->all();
    }

    // ------------------------------------------------------------- (a) belum absen masuk, tanpa shift

    public function test_missing_clock_in_uses_the_opening_time_for_staff_without_a_shift(): void
    {
        $late = $this->user('kasir', $this->store, 'Rina');
        $clockedIn = $this->user('kasir', $this->store, 'Sari');
        $onLeave = $this->user('kasir', $this->store, 'Tono');
        Attendance::create(['user_id' => $clockedIn->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-11 09:00:00']);
        LeaveRequest::create(['user_id' => $onLeave->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'reason' => 'Acara', 'status' => 'approved']);

        // Ambang 09:00 + 15 + 30 = 09:45, dalam jendela 65 menit dari 10:00.
        $this->mock(PushNotificationService::class, function ($mock) use ($late, $clockedIn, $onLeave) {
            $mock->shouldReceive('sendToStoreStaff')->once()->withArgs(fn (int $storeId, string $title, string $body) => $storeId === $this->store->id
                && str_contains($body, $late->name)
                && ! str_contains($body, $clockedIn->name)
                && ! str_contains($body, $onLeave->name));
            $mock->shouldReceive('storeStaffIds')->andReturn(collect());
        });

        $this->artisan('attendance:notify-missing-clockins')->assertSuccessful();
    }

    public function test_missing_clock_in_is_skipped_on_a_closed_day(): void
    {
        Carbon::setTestNow('2026-11-15 10:00:00'); // Minggu, toko libur
        $this->user('kasir', $this->store, 'Rina');

        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToStoreStaff'));

        $this->artisan('attendance:notify-missing-clockins')->assertSuccessful();
    }

    // ------------------------------------------------------------- (b0) pengingat absen masuk ke karyawan, tepat pada jam buka

    public function test_clockin_reminder_goes_to_unclocked_employees_at_opening_time(): void
    {
        Carbon::setTestNow('2026-11-11 09:02:00'); // ambang = jam buka 09:00, putaran 5 menit berikutnya
        $late = $this->user('kasir', $this->store, 'Rina');
        $clockedIn = $this->user('kasir', $this->store, 'Sari');
        $onLeave = $this->user('kasir', $this->store, 'Tono');
        $director = $this->user('direksi', $this->store, 'Direktur');
        Attendance::create(['user_id' => $clockedIn->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-11 09:01:00']);
        LeaveRequest::create(['user_id' => $onLeave->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-11-11', 'end_date' => '2026-11-11', 'reason' => 'x', 'status' => 'approved']);

        $this->artisan('attendance:remind-clockins')->assertSuccessful();

        $this->assertContains('Jangan Lupa Absen Masuk', $this->inboxTitles($late));
        $this->assertSame([], $this->inboxTitles($clockedIn), 'Yang sudah absen masuk tidak diingatkan.');
        $this->assertSame([], $this->inboxTitles($onLeave), 'Yang sedang izin/cuti tidak diingatkan.');
        $this->assertSame([], $this->inboxTitles($director), 'Direksi tidak wajib absen.');
    }

    public function test_clockin_reminder_is_sent_once_per_day_and_not_before_the_threshold_or_on_closed_days(): void
    {
        $late = $this->user('kasir', $this->store, 'Rina');

        Carbon::setTestNow('2026-11-11 08:55:00'); // belum jam buka 09:00
        $this->artisan('attendance:remind-clockins')->assertSuccessful();
        $this->assertSame([], $this->inboxTitles($late));

        Carbon::setTestNow('2026-11-11 09:01:00');
        $this->artisan('attendance:remind-clockins')->assertSuccessful();
        Carbon::setTestNow('2026-11-11 09:06:00'); // putaran berikutnya: tidak diulang
        $this->artisan('attendance:remind-clockins')->assertSuccessful();
        $this->assertCount(1, $this->inboxTitles($late), 'Satu pengingat per karyawan per hari.');

        Carbon::setTestNow('2026-11-15 09:10:00'); // Minggu, toko libur
        $this->artisan('attendance:remind-clockins')->assertSuccessful();
        $this->assertCount(1, $this->inboxTitles($late));
    }

    // ------------------------------------------------------------- (b) pengingat absen pulang, 5 menit setelah jam tutup

    public function test_clockout_reminder_goes_to_the_employee_five_minutes_after_closing_and_mentions_the_radius(): void
    {
        Carbon::setTestNow('2026-11-11 18:07:00'); // ambang 18:00 + 5 = 18:05
        $this->store->update(['attendance_radius_meters' => 120]);
        $forgot = $this->user('kasir', $this->store, 'Rina');
        $done = $this->user('kasir', $this->store, 'Sari');
        Attendance::create(['user_id' => $forgot->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-11 09:00:00']);
        Attendance::create(['user_id' => $done->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-11 09:00:00', 'clock_out_at' => '2026-11-11 18:00:00']);

        $this->artisan('attendance:remind-clockouts')->assertSuccessful();

        $this->assertContains('Jangan Lupa Absen Pulang', $this->inboxTitles($forgot));
        $this->assertSame([], $this->inboxTitles($done), 'Yang sudah absen pulang tidak diingatkan.');
        $this->assertStringContainsString('radius 120 meter', $forgot->notifications()->first()->data['body']);
    }

    public function test_clockout_reminder_is_once_per_day_and_not_before_the_threshold_or_long_after_or_on_closed_days(): void
    {
        $forgot = $this->user('kasir', $this->store, 'Rina');
        Attendance::create(['user_id' => $forgot->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-11 09:00:00']);

        Carbon::setTestNow('2026-11-11 18:03:00'); // belum 18:05
        $this->artisan('attendance:remind-clockouts')->assertSuccessful();
        $this->assertSame([], $this->inboxTitles($forgot));

        Carbon::setTestNow('2026-11-11 18:06:00');
        $this->artisan('attendance:remind-clockouts')->assertSuccessful();
        Carbon::setTestNow('2026-11-11 18:11:00'); // putaran berikutnya: tidak diulang
        $this->artisan('attendance:remind-clockouts')->assertSuccessful();
        $this->assertCount(1, $this->inboxTitles($forgot), 'Satu pengingat per karyawan per hari.');
    }

    public function test_clockout_reminder_skips_stale_rows_far_past_the_window(): void
    {
        $forgot = $this->user('kasir', $this->store, 'Rina');
        Attendance::create(['user_id' => $forgot->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-11 09:00:00']);

        Carbon::setTestNow('2026-11-11 20:00:00'); // lewat 18:05 + 60 menit
        $this->artisan('attendance:remind-clockouts')->assertSuccessful();

        $this->assertSame([], $this->inboxTitles($forgot));
    }

    // ------------------------------------------------------------- (c) pengajuan menunggu terlalu lama

    private function leave(User $user, Store $store, string $createdAt): LeaveRequest
    {
        $request = LeaveRequest::create(['user_id' => $user->id, 'store_id' => $store->id, 'type' => 'izin', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'x', 'status' => 'pending']);
        LeaveRequest::whereKey($request->id)->update(['created_at' => $createdAt]);

        return $request;
    }

    private function correction(User $user, Store $store, string $createdAt, string $status = 'pending'): AttendanceCorrectionRequest
    {
        $request = AttendanceCorrectionRequest::create(['user_id' => $user->id, 'store_id' => $store->id, 'date' => '2026-11-09', 'entry_type' => 'manual', 'reason' => 'Lupa', 'status' => $status, 'requested_by' => $user->id]);
        AttendanceCorrectionRequest::whereKey($request->id)->update(['created_at' => $createdAt]);

        return $request;
    }

    public function test_pending_requests_remind_deciders_only_on_the_reminder_days(): void
    {
        $admin = $this->user('super_admin', null, 'Admin');
        $managerA = $this->user('store_manager', $this->store, 'Manajer A');
        $managerB = $this->user('store_manager', $this->otherStore, 'Manajer B');
        $staff = $this->user('kasir', $this->store, 'Rina');

        $this->leave($staff, $this->store, '2026-11-09 08:00:00');          // umur 2 hari: hari pengingat pertama
        $this->correction($staff, $this->store, '2026-11-06 08:00:00');     // umur 5 hari: pengingat kedua (2 + 3)
        $this->leave($staff, $this->store, '2026-11-10 08:00:00');          // umur 1 hari: belum
        $this->leave($staff, $this->store, '2026-11-07 08:00:00');          // umur 4 hari: bukan hari pengingat
        $this->correction($staff, $this->store, '2026-11-01 08:00:00', 'approved'); // sudah diputuskan: diabaikan

        $this->artisan('attendance:remind-pending-requests')->assertSuccessful();

        $this->assertContains('Pengajuan Menunggu Keputusan', $this->inboxTitles($admin));
        $this->assertContains('Pengajuan Menunggu Keputusan', $this->inboxTitles($managerA));
        $this->assertSame([], $this->inboxTitles($managerB), 'Store manager toko lain tidak diganggu.');
        $this->assertSame([], $this->inboxTitles($staff), 'Karyawan biasa bukan pemutus.');

        $body = $admin->notifications()->first()->data['body'];
        $this->assertStringContainsString('1 pengajuan Izin & Cuti', $body);
        $this->assertStringContainsString('1 Koreksi Absensi', $body);
    }

    public function test_pending_requests_send_nothing_when_none_are_due(): void
    {
        $admin = $this->user('super_admin', null, 'Admin');
        $staff = $this->user('kasir', $this->store, 'Rina');
        $this->leave($staff, $this->store, '2026-11-10 08:00:00');

        $this->artisan('attendance:remind-pending-requests')->assertSuccessful();

        $this->assertSame([], $this->inboxTitles($admin));
    }

    public function test_new_commands_are_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $expressions = $events->mapWithKeys(fn ($e) => [$e->command => $e->expression]);

        $clockouts = $expressions->first(fn ($expr, $cmd) => str_contains((string) $cmd, 'attendance:remind-clockouts'));
        $pending = $expressions->first(fn ($expr, $cmd) => str_contains((string) $cmd, 'attendance:remind-pending-requests'));

        $clockins = $expressions->first(fn ($expr, $cmd) => str_contains((string) $cmd, 'attendance:remind-clockins'));

        $this->assertSame('*/5 * * * *', $clockins);
        $this->assertSame('*/5 * * * *', $clockouts);
        $this->assertSame('0 9 * * *', $pending);
    }
}
