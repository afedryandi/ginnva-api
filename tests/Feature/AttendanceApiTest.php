<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\LeaveRequest;
use App\Models\ScheduleDayOverride;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Absensi Karyawan (aplikasi staf + perintah terjadwal): absen masuk/keluar
 * dengan validasi radius, lokasi palsu, selfie, dan hitungan telat/pulang
 * cepat (jam toko atau Shift individu), riwayat, pengajuan koreksi, izin &
 * cuti, penandaan Alpha/Izin otomatis, dan peringatan belum absen.
 * Waktu dibekukan: Rabu 7 Okt 2026.
 */
class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('partner', 'web');
        Carbon::setTestNow('2026-10-07 09:10:00');

        $this->store = $this->makeStore('Toko A');
        $this->staff = $this->makeStaff($this->store);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeStore(string $name, array $overrides = []): Store
    {
        return Store::create(array_merge([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => $name, 'is_active' => true,
            'latitude' => -6.2000, 'longitude' => 106.8000, 'attendance_radius_meters' => 100, 'late_tolerance_minutes' => 15,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'],
                ['days' => ['sun'], 'closed' => true],
            ],
        ], $overrides));
    }

    private function makeStaff(?Store $store, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Staf ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x',
            'store_id' => $store?->id, 'join_date' => '2025-01-01',
        ], $extra));
        $user->assignRole('kasir');

        return $user;
    }

    private function photo(int $size = 400): UploadedFile
    {
        return UploadedFile::fake()->image('selfie.jpg', $size, $size);
    }

    private function clockIn(?User $user = null, array $data = [])
    {
        return $this->actingAs($user ?? $this->staff, 'api')->post('/api/staff/attendance/clock-in', array_merge([
            'latitude' => -6.2000, 'longitude' => 106.8000, 'photo' => $this->photo(),
        ], $data), ['Accept' => 'application/json']);
    }

    private function clockOut(?User $user = null, array $data = [])
    {
        return $this->actingAs($user ?? $this->staff, 'api')->post('/api/staff/attendance/clock-out', array_merge([
            'latitude' => -6.2000, 'longitude' => 106.8000, 'photo' => $this->photo(),
        ], $data), ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------- akses

    public function test_attendance_endpoints_require_login(): void
    {
        $this->getJson('/api/staff/attendance/today')->assertStatus(401);
        $this->postJson('/api/staff/attendance/clock-in')->assertStatus(401);
        $this->postJson('/api/staff/attendance/clock-out')->assertStatus(401);
        $this->getJson('/api/staff/attendance/history')->assertStatus(401);
        $this->getJson('/api/staff/attendance/corrections')->assertStatus(401);
        $this->getJson('/api/staff/leave-requests')->assertStatus(401);
    }

    public function test_today_returns_store_geofence_and_no_attendance_before_clock_in(): void
    {
        $this->actingAs($this->staff, 'api')->getJson('/api/staff/attendance/today')->assertSuccessful()
            ->assertJsonPath('attendance', null)
            ->assertJsonPath('store.id', $this->store->id)
            ->assertJsonPath('store.radius_meters', 100);

        $defaultStore = $this->makeStore('Toko Default', ['attendance_radius_meters' => null]);
        $this->actingAs($this->makeStaff($defaultStore), 'api')->getJson('/api/staff/attendance/today')
            ->assertJsonPath('store.radius_meters', Attendance::DEFAULT_RADIUS_METERS);

        $this->actingAs($this->makeStaff(null), 'api')->getJson('/api/staff/attendance/today')->assertJsonPath('store', null);
    }

    // ------------------------------------------------------------- absen masuk

    public function test_clock_in_within_tolerance_records_attendance_without_lateness(): void
    {
        $response = $this->clockIn()->assertSuccessful()->assertJsonPath('success', true);

        $this->assertSame('clock', $response->json('attendance.entry_type'));
        $this->assertSame(0, $response->json('attendance.late_minutes'));
        $this->assertSame(0, $response->json('attendance.clock_in_distance_meters'));
        $this->assertNotNull($response->json('attendance.clock_in_photo_url'));

        $row = Attendance::where('user_id', $this->staff->id)->firstOrFail();
        $this->assertSame('2026-10-07', $row->date->toDateString());
        $this->assertSame($this->store->id, $row->store_id);
        Storage::disk('public')->assertExists($row->clock_in_photo);

        $this->actingAs($this->staff, 'api')->getJson('/api/staff/attendance/today')->assertJsonPath('attendance.id', $row->id);
    }

    public function test_late_minutes_are_counted_after_the_store_tolerance(): void
    {
        Carbon::setTestNow('2026-10-07 09:40:00'); // buka 09:00, toleransi 15 mnt -> telat 25

        $this->clockIn()->assertSuccessful()->assertJsonPath('attendance.late_minutes', 25);
    }

    public function test_individual_shift_overrides_the_store_opening_time(): void
    {
        $shift = Shift::create(['store_id' => $this->store->id, 'name' => 'Siang', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
        ScheduleDayOverride::create(['user_id' => $this->staff->id, 'store_id' => $this->store->id, 'date' => '2026-10-07', 'shift_id' => $shift->id]);

        Carbon::setTestNow('2026-10-07 14:05:00');
        $this->clockIn()->assertSuccessful()->assertJsonPath('attendance.late_minutes', 0);

        $other = $this->makeStaff($this->store);
        ScheduleDayOverride::create(['user_id' => $other->id, 'store_id' => $this->store->id, 'date' => '2026-10-07', 'shift_id' => $shift->id]);
        Carbon::setTestNow('2026-10-07 14:30:00');
        $this->clockIn($other)->assertSuccessful()->assertJsonPath('attendance.late_minutes', 15);
    }

    public function test_clock_in_outside_the_radius_is_rejected_and_the_photo_is_not_kept(): void
    {
        $this->clockIn(null, ['latitude' => -6.3000])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertStringContainsString('dari toko', $this->clockIn(null, ['latitude' => -6.3000])->json('message'));
        $this->assertSame(0, Attendance::count());
        $this->assertSame([], Storage::disk('public')->allFiles('attendance-photos'), 'Foto yatim tidak boleh tertinggal.');
    }

    public function test_mock_location_is_rejected_but_unknown_platform_is_allowed(): void
    {
        $this->clockIn(null, ['is_mocked' => '1'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'GPS palsu'));
        $this->assertSame(0, Attendance::count());

        $this->clockIn()->assertSuccessful(); // iOS: tidak mengirim is_mocked
    }

    public function test_a_second_clock_in_the_same_day_is_rejected(): void
    {
        $this->clockIn()->assertSuccessful();

        $this->clockIn()->assertStatus(422)->assertJsonPath('message', 'Sudah absen masuk hari ini.');
        $this->assertSame(1, Attendance::count());
    }

    public function test_a_store_without_coordinates_does_not_block_clock_in(): void
    {
        $store = $this->makeStore('Tanpa Koordinat', ['latitude' => null, 'longitude' => null]);
        $user = $this->makeStaff($store);

        $this->clockIn($user, ['latitude' => -7.5, 'longitude' => 110.0])->assertSuccessful()
            ->assertJsonPath('attendance.clock_in_distance_meters', null);
    }

    public function test_an_account_without_a_store_cannot_clock_in(): void
    {
        $this->clockIn($this->makeStaff(null))->assertStatus(422);
        $this->assertSame(0, Attendance::count());
    }

    public function test_clock_in_validates_photo_and_coordinates(): void
    {
        $this->clockIn(null, ['photo' => null])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->clockIn(null, ['photo' => $this->photo(100)])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->clockIn(null, ['photo' => UploadedFile::fake()->create('berkas.pdf', 20, 'application/pdf')])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->clockIn(null, ['latitude' => 200])->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->clockIn(null, ['longitude' => null])->assertStatus(422)->assertJsonValidationErrors('longitude');

        $this->assertSame(0, Attendance::count());
    }

    public function test_clock_in_completes_a_manual_row_the_admin_created_earlier_that_day(): void
    {
        Attendance::create(['user_id' => $this->staff->id, 'store_id' => $this->store->id, 'date' => '2026-10-07', 'entry_type' => 'manual', 'note' => 'Device mati']);

        $this->clockIn()->assertSuccessful()->assertJsonPath('attendance.entry_type', 'clock');

        $this->assertSame(1, Attendance::count());
    }

    // ------------------------------------------------------------- absen keluar

    public function test_clock_out_requires_a_clock_in_first_and_cannot_be_repeated(): void
    {
        $this->clockOut()->assertStatus(422)->assertJsonPath('message', 'Belum absen masuk hari ini.');

        $this->clockIn()->assertSuccessful();
        Carbon::setTestNow('2026-10-07 18:00:00');
        $this->clockOut()->assertSuccessful()->assertJsonPath('attendance.early_leave_minutes', 0);

        $this->clockOut()->assertStatus(422)->assertJsonPath('message', 'Sudah absen keluar hari ini.');
    }

    public function test_early_leave_is_counted_against_closing_time_minus_tolerance(): void
    {
        $this->clockIn()->assertSuccessful();
        Carbon::setTestNow('2026-10-07 16:00:00'); // tutup 18:00 -> 120 mnt - toleransi 15

        $this->clockOut()->assertSuccessful()->assertJsonPath('attendance.early_leave_minutes', 105);

        $row = Attendance::firstOrFail();
        $this->assertNotNull($row->clock_out_at);
        Storage::disk('public')->assertExists($row->clock_out_photo);
    }

    public function test_clock_out_is_also_checked_against_radius_and_mock_location(): void
    {
        $this->clockIn()->assertSuccessful();

        $this->clockOut(null, ['latitude' => -6.3000])->assertStatus(422);
        $this->clockOut(null, ['is_mocked' => '1'])->assertStatus(422);

        $this->assertNull(Attendance::firstOrFail()->clock_out_at);
        $this->assertCount(1, Storage::disk('public')->allFiles('attendance-photos'), 'Hanya foto absen masuk yang tersisa.');
    }

    // ------------------------------------------------------------- riwayat

    public function test_history_lists_only_my_month_and_sums_lateness(): void
    {
        $other = $this->makeStaff($this->store);
        foreach ([['2026-10-05', 10], ['2026-10-06', 20]] as [$date, $late]) {
            Attendance::create(['user_id' => $this->staff->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => 'clock', 'late_minutes' => $late]);
        }
        Attendance::create(['user_id' => $this->staff->id, 'store_id' => $this->store->id, 'date' => '2026-09-30', 'entry_type' => 'clock', 'late_minutes' => 99]);
        Attendance::create(['user_id' => $other->id, 'store_id' => $this->store->id, 'date' => '2026-10-05', 'entry_type' => 'clock', 'late_minutes' => 50]);

        $october = $this->actingAs($this->staff, 'api')->getJson('/api/staff/attendance/history')->assertSuccessful();
        $this->assertSame(['2026-10-06', '2026-10-05'], collect($october->json('attendances'))->pluck('date')->all());
        $this->assertSame(30, $october->json('total_late_minutes'));

        $this->actingAs($this->staff, 'api')->getJson('/api/staff/attendance/history?month=2026-09')
            ->assertJsonPath('total_late_minutes', 99);

        $this->actingAs($this->staff, 'api')->getJson('/api/staff/attendance/history?month=bukan-bulan')->assertStatus(422);
    }

    // ------------------------------------------------------------- koreksi

    public function test_correction_request_is_pending_and_leaves_attendance_untouched(): void
    {
        $response = $this->actingAs($this->staff, 'api')->postJson('/api/staff/attendance/corrections', [
            'date' => '2026-10-06', 'clock_in_at' => '09:00', 'clock_out_at' => '18:00', 'reason' => 'Lupa absen, HP mati',
        ])->assertStatus(201);

        $this->assertSame(AttendanceCorrectionRequest::STATUS_PENDING, $response->json('correction.status'));
        $this->assertSame(0, Attendance::count(), 'Data absensi baru berubah setelah disetujui.');
        $this->assertSame(1, AttendanceCorrectionRequest::where('user_id', $this->staff->id)->count());
    }

    public function test_correction_validation_pending_duplicate_and_missing_store(): void
    {
        $post = fn (User $who, array $data) => $this->actingAs($who, 'api')->postJson('/api/staff/attendance/corrections', $data);
        $valid = ['date' => '2026-10-06', 'clock_in_at' => '09:00', 'reason' => 'Lupa absen'];

        $post($this->staff, array_diff_key($valid, ['reason' => 1]))->assertStatus(422);
        $post($this->staff, [...$valid, 'date' => '2026-10-20'])->assertStatus(422);
        $post($this->staff, [...$valid, 'clock_in_at' => '9 pagi'])->assertStatus(422);
        $post($this->staff, array_diff_key($valid, ['clock_in_at' => 1]))->assertStatus(422)
            ->assertJsonPath('message', 'Isi minimal salah satu: jam masuk atau jam keluar.');
        $post($this->makeStaff(null), $valid)->assertStatus(422);

        $post($this->staff, $valid)->assertStatus(201);
        $post($this->staff, $valid)->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(1, AttendanceCorrectionRequest::count());
    }

    public function test_correction_list_shows_only_my_own_requests(): void
    {
        $other = $this->makeStaff($this->store);
        $this->actingAs($this->staff, 'api')->postJson('/api/staff/attendance/corrections', ['date' => '2026-10-06', 'clock_in_at' => '09:00', 'reason' => 'Lupa'])->assertStatus(201);
        $this->actingAs($other, 'api')->postJson('/api/staff/attendance/corrections', ['date' => '2026-10-06', 'clock_in_at' => '09:30', 'reason' => 'Lupa juga'])->assertStatus(201);

        $list = $this->actingAs($this->staff, 'api')->getJson('/api/staff/attendance/corrections')->assertSuccessful()->json('corrections');

        $this->assertCount(1, $list);
        $this->assertSame('Lupa', $list[0]['reason']);
    }

    // ------------------------------------------------------------- izin & cuti

    private function requestLeave(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->staff, 'api')->postJson('/api/staff/leave-requests', array_merge([
            'type' => 'izin', 'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'reason' => 'Urusan keluarga',
        ], $data));
    }

    public function test_leave_request_is_created_as_pending_with_a_number(): void
    {
        $response = $this->requestLeave([])->assertStatus(201);

        $this->assertSame('pending', $response->json('leave_request.status'));
        $this->assertNotEmpty($response->json('leave_request.request_number'));
        $this->assertSame($this->store->id, LeaveRequest::firstOrFail()->store_id);
    }

    public function test_leave_request_validation(): void
    {
        $this->requestLeave(['type' => 'liburan'])->assertStatus(422);
        $this->requestLeave(['start_date' => '2026-10-01', 'end_date' => '2026-10-02'])->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->requestLeave(['end_date' => '2026-10-11'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->requestLeave(['reason' => ''])->assertStatus(422);
        $this->requestLeave(['document' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);
        $this->requestLeave([], $this->makeStaff(null))->assertStatus(422);

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_leave_longer_than_the_maximum_is_rejected(): void
    {
        $this->requestLeave(['start_date' => '2026-10-12', 'end_date' => '2026-11-20'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'maksimal ' . LeaveRequest::MAX_DURATION_DAYS));
    }

    public function test_overlapping_leave_is_rejected_but_cancelled_leave_frees_the_dates(): void
    {
        $first = $this->requestLeave([])->assertStatus(201)->json('leave_request.id');

        $this->requestLeave(['start_date' => '2026-10-13', 'end_date' => '2026-10-14'])->assertStatus(422);

        $this->actingAs($this->staff, 'api')->postJson("/api/staff/leave-requests/{$first}/cancel")->assertSuccessful()
            ->assertJsonPath('leave_request.status', 'cancelled');
        $this->requestLeave(['start_date' => '2026-10-13', 'end_date' => '2026-10-14'])->assertStatus(201);
    }

    public function test_annual_leave_is_limited_by_the_remaining_quota(): void
    {
        $quota = LeaveRequest::annualQuotaFor($this->staff, 2026);
        $this->assertSame(LeaveRequest::ANNUAL_CUTI_QUOTA_DAYS, $quota, 'Karyawan sejak 2025 punya jatah penuh.');

        $this->requestLeave(['type' => 'cuti', 'start_date' => '2026-10-12', 'end_date' => '2026-10-' . (12 + $quota)])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Sisa jatah cuti'));

        $newcomer = $this->makeStaff($this->store, ['join_date' => '2026-10-01']);
        $this->requestLeave(['type' => 'cuti'], $newcomer)->assertStatus(422);

        $this->requestLeave(['type' => 'sakit'], $newcomer)->assertStatus(201); // sakit/izin tidak memotong jatah
    }

    public function test_only_my_own_pending_requests_can_be_cancelled(): void
    {
        $other = $this->makeStaff($this->store);
        $mine = $this->requestLeave([])->assertStatus(201)->json('leave_request.id');
        $approved = LeaveRequest::create([
            'user_id' => $this->staff->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
            'reason' => 'Acara', 'status' => 'approved',
        ]);

        $this->actingAs($other, 'api')->postJson("/api/staff/leave-requests/{$mine}/cancel")->assertStatus(404);
        $this->actingAs($this->staff, 'api')->postJson("/api/staff/leave-requests/{$approved->id}/cancel")->assertStatus(422);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->actingAs($this->staff, 'api')->postJson("/api/staff/leave-requests/{$mine}/cancel")->assertSuccessful();
    }

    public function test_leave_list_is_mine_only_and_carries_the_quota(): void
    {
        $other = $this->makeStaff($this->store);
        $this->requestLeave([])->assertStatus(201);
        $this->requestLeave([], $other)->assertStatus(201);

        $response = $this->actingAs($this->staff, 'api')->getJson('/api/staff/leave-requests')->assertSuccessful();

        $this->assertCount(1, $response->json('leave_requests'));
        $this->assertSame(LeaveRequest::ANNUAL_CUTI_QUOTA_DAYS, $response->json('cuti_quota'));
        $this->assertSame(0, $response->json('cuti_used'));
        $this->assertFalse($response->json('has_more'));
    }

    // ------------------------------------------------------------- Alpha/Izin otomatis

    public function test_mark_absences_fills_yesterdays_gaps_as_alpha_or_leave(): void
    {
        Carbon::setTestNow('2026-10-08 01:00:00'); // kemarin = Rabu 7 Okt
        $absent = $this->staff;
        $onLeave = $this->makeStaff($this->store);
        $pendingLeave = $this->makeStaff($this->store);
        $present = $this->makeStaff($this->store);
        $inactive = $this->makeStaff($this->store, ['is_active' => false]);
        $notStarted = $this->makeStaff($this->store, ['join_date' => '2026-10-08']);
        $partner = $this->makeStaff($this->store);
        $partner->syncRoles(['partner']);

        foreach ([[$onLeave, 'approved'], [$pendingLeave, 'pending']] as [$user, $status]) {
            LeaveRequest::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-10-06', 'end_date' => '2026-10-08', 'reason' => 'x', 'status' => $status]);
        }
        Attendance::create(['user_id' => $present->id, 'store_id' => $this->store->id, 'date' => '2026-10-07', 'entry_type' => 'clock', 'clock_in_at' => '2026-10-07 09:00:00']);

        $this->artisan('attendance:mark-absences')->assertSuccessful();

        $type = fn (User $u) => Attendance::where('user_id', $u->id)->where('date', '2026-10-07')->value('entry_type');
        $this->assertSame('alpha', $type($absent));
        $this->assertSame('leave', $type($onLeave));
        $this->assertSame('alpha', $type($pendingLeave), 'Izin yang belum disetujui tetap Alpha.');
        $this->assertSame('clock', $type($present), 'Baris yang sudah ada tidak ditimpa.');
        $this->assertNull($type($inactive));
        $this->assertNull($type($notStarted));
        $this->assertNull($type($partner));
    }

    public function test_mark_absences_skips_closed_days_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-12 01:00:00'); // kemarin = Minggu 11 Okt, toko tutup

        $this->artisan('attendance:mark-absences')->assertSuccessful();
        $this->assertSame(0, Attendance::count());

        Carbon::setTestNow('2026-10-08 01:00:00');
        $this->artisan('attendance:mark-absences')->assertSuccessful();
        $this->artisan('attendance:mark-absences')->assertSuccessful();
        $this->assertSame(1, Attendance::where('user_id', $this->staff->id)->count());
    }

    // ------------------------------------------------------------- peringatan belum absen

    private function shiftFor(User $user, string $start = '09:00'): void
    {
        $shift = Shift::firstOrCreate(['store_id' => $this->store->id, 'name' => 'Pagi ' . $start], ['start_time' => $start, 'end_time' => '17:00', 'is_active' => true]);
        ScheduleDayOverride::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'date' => '2026-10-07', 'shift_id' => $shift->id]);
    }

    public function test_missing_clock_in_warning_is_sent_once_when_the_threshold_just_passed(): void
    {
        $this->shiftFor($this->staff);
        $clockedIn = $this->makeStaff($this->store);
        $this->shiftFor($clockedIn);
        Attendance::create(['user_id' => $clockedIn->id, 'store_id' => $this->store->id, 'date' => '2026-10-07', 'entry_type' => 'clock', 'clock_in_at' => '2026-10-07 09:00:00']);
        $noShift = $this->makeStaff($this->store);

        Carbon::setTestNow('2026-10-07 10:00:00'); // ambang 09:00 + 15 + 30 = 09:45, dalam jendela 65 mnt
        $this->mock(PushNotificationService::class, function ($mock) use ($noShift, $clockedIn) {
            $mock->shouldReceive('sendToStoreStaff')->once()->withArgs(function (int $storeId, string $title, string $body) use ($noShift, $clockedIn) {
                return $storeId === $this->store->id
                    && str_contains($body, $this->staff->name)
                    && ! str_contains($body, $clockedIn->name)
                    && ! str_contains($body, $noShift->name);
            });
        });

        $this->artisan('attendance:notify-missing-clockins')->assertSuccessful();
    }

    public function test_missing_clock_in_warning_is_not_repeated_after_the_window(): void
    {
        $this->shiftFor($this->staff);

        Carbon::setTestNow('2026-10-07 12:00:00'); // ambang 09:45 sudah lewat > 65 mnt
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToStoreStaff'));

        $this->artisan('attendance:notify-missing-clockins')->assertSuccessful();
    }

    public function test_missing_clock_in_warning_is_not_sent_before_the_threshold(): void
    {
        $this->shiftFor($this->staff);
        Carbon::setTestNow('2026-10-07 09:30:00'); // belum lewat 09:45
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToStoreStaff'));
        $this->artisan('attendance:notify-missing-clockins')->assertSuccessful();
    }
}
