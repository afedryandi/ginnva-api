<?php

namespace Tests\Feature;

use App\Filament\Pages\MyAttendance;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\FieldDutyRequest;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Absen Saya": absen dari panel untuk karyawan tanpa aplikasi staff (mis. iPhone). Memakai Attendance::clockIn/clockOut yang sama dengan
 * aplikasi, jadi radius toko dan selfie wajib tetap berlaku. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class MyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        Storage::fake('local');
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('direksi', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-07 09:10:00');

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'latitude' => -6.2000, 'longitude' => 106.8000, 'attendance_radius_meters' => 100, 'late_tolerance_minutes' => 15,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'],
                ['days' => ['sun'], 'closed' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role = 'kasir', ?array $menuAccess = null, bool $withStore = true): User
    {
        return tap(User::create([
            'name' => 'Staff ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true,
            'store_id' => $withStore ? $this->store->id : null, 'join_date' => '2025-01-01', 'menu_access' => $menuAccess,
        ]), fn (User $u) => $u->assignRole($role));
    }

    private function photo(int $size = 400): UploadedFile
    {
        return UploadedFile::fake()->image('selfie.jpg', $size, $size);
    }

    public function test_access_follows_menu_access_and_attendance_exemption(): void
    {
        $this->actingAs($this->staff());
        $this->assertTrue(MyAttendance::canAccess());

        $this->actingAs($this->staff('kasir', ['BookingResource']));
        $this->assertFalse(MyAttendance::canAccess(), 'Menu tidak dicentang di Hak Akses.');

        $this->actingAs($this->staff('kasir', ['MyAttendance']));
        $this->assertTrue(MyAttendance::canAccess());

        $this->actingAs($this->staff('direksi'));
        $this->assertFalse(MyAttendance::canAccess(), 'Direksi tidak wajib absen.');
    }

    public function test_clocking_in_and_out_records_the_same_attendance_as_the_app(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertHasNoErrors()
            ->assertNotified('Absen masuk tercatat');

        $row = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('clock', $row->entry_type);
        $this->assertNotNull($row->clock_in_at);
        $this->assertNotNull($row->clock_in_photo);
        $this->assertNull($row->clock_in_is_mocked);
        $this->assertGreaterThanOrEqual(0, (int) $row->late_minutes);
        $this->assertNotNull($row->clock_in_distance_meters);

        Carbon::setTestNow('2026-10-07 18:05:00');
        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'out', -6.2000, 106.8000)
            ->assertNotified('Absen keluar tercatat');

        $this->assertNotNull($row->fresh()->clock_out_at);
        $this->assertNotNull($row->fresh()->clock_out_photo);
    }

    public function test_the_store_radius_and_a_second_clock_in_are_refused(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        // Sekitar 1 km dari toko, radius toko 100 m.
        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', -6.2100, 106.8000)
            ->assertNotified('Absen ditolak');
        $this->assertSame(0, Attendance::where('user_id', $user->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Foto dihapus kalau absen ditolak.');

        Livewire::test(MyAttendance::class)->set('photo', $this->photo())->call('submitClock', 'in', -6.2000, 106.8000);
        Livewire::test(MyAttendance::class)->set('photo', $this->photo())->call('submitClock', 'in', -6.2000, 106.8000)->assertNotified('Absen ditolak');
        $this->assertSame(1, Attendance::where('user_id', $user->id)->count());
    }

    public function test_a_selfie_is_required_and_must_be_large_enough(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertHasErrors(['photo' => 'required']);

        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo(50))
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertHasErrors(['photo' => 'dimensions']);

        $this->assertSame(0, Attendance::where('user_id', $user->id)->count());
    }

    public function test_invalid_coordinates_and_users_without_a_store_or_access_are_refused(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', 123.0, 106.8)
            ->assertNotified('Lokasi tidak valid');

        $this->actingAs($this->staff('kasir', null, false));
        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertNotified('Tidak bisa absen');

        $this->actingAs($this->staff('direksi'));
        Livewire::test(MyAttendance::class)->assertForbidden();
        $this->assertSame(0, Attendance::count());
        $this->assertNotNull($user);
    }

    // ------------------------------------------------------------- tab Pengajuan (2026-11-22)

    public function test_staff_with_only_the_absen_saya_menu_can_file_a_full_day_field_duty(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'pengajuan')
            ->callAction('fieldDuty', ['date' => '2026-10-08', 'partial' => false, 'description' => 'Kanvasing area Kelapa Gading'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan dinas luar terkirim');

        $duty = FieldDutyRequest::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2026-10-08', $duty->date->toDateString());
        $this->assertFalse($duty->isPartialDay());
        $this->assertSame('pending', $duty->status);
        $this->assertSame($this->store->id, (int) $duty->store_id);
    }

    public function test_a_part_day_field_duty_keeps_its_hours(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->callAction('fieldDuty', ['date' => '2026-10-08', 'partial' => true, 'start_time' => '10:00', 'end_time' => '13:00', 'description' => 'Antar dokumen ke klien'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan dinas luar terkirim');

        $duty = FieldDutyRequest::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($duty->isPartialDay());
        $this->assertSame('10:00–13:00', $duty->windowLabel());
    }

    public function test_the_same_rules_as_the_app_apply_to_a_field_duty_filed_from_the_panel(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        $component = Livewire::test(MyAttendance::class);
        $component->callAction('fieldDuty', ['date' => '2026-10-08', 'partial' => false, 'description' => 'Pertama']);

        $component->callAction('fieldDuty', ['date' => '2026-10-08', 'partial' => false, 'description' => 'Dobel'])
            ->assertNotified('Pengajuan ditolak');

        $this->assertSame(1, FieldDutyRequest::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_a_correction_can_be_filed_from_the_panel_and_follows_the_service_rules(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->callAction('correction', ['date' => '2026-10-06', 'clock_in_at' => '09:00', 'clock_out_at' => null, 'reason' => 'Lupa absen masuk'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan koreksi terkirim');

        $request = AttendanceCorrectionRequest::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('manual', $request->entry_type);
        $this->assertSame('2026-10-06 09:00', $request->clock_in_at->format('Y-m-d H:i'));
        $this->assertNull($request->clock_out_at);
        $this->assertSame('pending', $request->status);
        $this->assertSame($user->id, (int) $request->requested_by);

        // Tanpa satu pun jam, dan dobel untuk tanggal yang sama, ditolak oleh layanan yang sama dengan aplikasi.
        Livewire::test(MyAttendance::class)
            ->callAction('correction', ['date' => '2026-10-05', 'clock_in_at' => null, 'clock_out_at' => null, 'reason' => 'Tanpa jam'])
            ->assertNotified('Pengajuan ditolak');
        Livewire::test(MyAttendance::class)
            ->callAction('correction', ['date' => '2026-10-06', 'clock_in_at' => '09:05', 'clock_out_at' => null, 'reason' => 'Dobel'])
            ->assertNotified('Pengajuan ditolak');

        $this->assertSame(1, AttendanceCorrectionRequest::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_staff_without_a_store_cannot_file_a_correction(): void
    {
        $this->actingAs($this->staff('kasir', ['MyAttendance'], withStore: false));

        Livewire::test(MyAttendance::class)
            ->callAction('correction', ['date' => '2026-10-06', 'clock_in_at' => '09:00', 'clock_out_at' => null, 'reason' => 'Lupa'])
            ->assertNotified('Pengajuan ditolak');

        $this->assertSame(0, AttendanceCorrectionRequest::withoutGlobalScopes()->count());
    }

    public function test_the_tab_lists_only_my_own_requests_and_they_can_be_cancelled(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $other = $this->staff('kasir', ['MyAttendance']);
        $mine = FieldDutyRequest::withoutGlobalScopes()->create(['user_id' => $me->id, 'store_id' => $this->store->id, 'date' => '2026-10-09', 'description' => 'Tujuan milik saya', 'status' => 'pending']);
        $theirs = FieldDutyRequest::withoutGlobalScopes()->create(['user_id' => $other->id, 'store_id' => $this->store->id, 'date' => '2026-10-09', 'description' => 'Tujuan orang lain', 'status' => 'pending']);

        $this->actingAs($me);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'pengajuan')
            ->assertSee('Tujuan milik saya')
            ->assertDontSee('Tujuan orang lain')
            ->callAction('cancelFieldDuty', arguments: ['id' => $theirs->id])
            ->assertNotified('Pengajuan tidak ditemukan')
            ->callAction('cancelFieldDuty', arguments: ['id' => $mine->id])
            ->assertNotified('Pengajuan dinas luar dibatalkan');

        $this->assertSame('cancelled', $mine->fresh()->status);
        $this->assertSame('pending', $theirs->fresh()->status, 'Pengajuan orang lain tidak tersentuh.');
    }

    public function test_the_correction_can_be_cancelled_by_its_owner_while_pending(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($me);

        Livewire::test(MyAttendance::class)->callAction('correction', ['date' => '2026-10-06', 'clock_in_at' => '09:00', 'clock_out_at' => null, 'reason' => 'Lupa']);
        $request = AttendanceCorrectionRequest::withoutGlobalScopes()->where('user_id', $me->id)->firstOrFail();

        Livewire::test(MyAttendance::class)
            ->set('tab', 'pengajuan')
            ->assertSee('Lupa')
            ->callAction('cancelCorrection', arguments: ['id' => $request->id])
            ->assertNotified('Pengajuan koreksi dibatalkan');

        $this->assertSame('cancelled', $request->fresh()->status);
    }

    public function test_the_field_duty_photo_page_needs_only_the_absen_saya_menu(): void
    {
        $this->actingAs($this->staff('kasir', ['MyAttendance']));
        $this->assertTrue(\App\Filament\Pages\FieldDutyPhoto::canAccess());

        $this->actingAs($this->staff('kasir', ['BookingResource']));
        $this->assertFalse(\App\Filament\Pages\FieldDutyPhoto::canAccess());
    }

    public function test_the_page_opens_on_the_absen_tab_and_unknown_tabs_fall_back(): void
    {
        $this->actingAs($this->staff('kasir', ['MyAttendance']));

        Livewire::test(MyAttendance::class)->assertSet('tab', 'absen')->assertSee('Absen Masuk hari ini');
        Livewire::test(MyAttendance::class)->set('tab', 'tidak-ada')->assertSet('tab', 'absen');
    }

    // ------------------------------------------------------------- Izin / Cuti / Sakit di tab Pengajuan (2026-11-22)

    private function leaveType(string $category): int
    {
        \App\Models\LeaveType::ensureDefaults();

        return (int) \App\Models\LeaveType::forCategory($category)->id;
    }

    public function test_staff_with_only_the_absen_saya_menu_can_file_an_izin(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'pengajuan')
            ->callAction('leave', ['leave_type_id' => $this->leaveType('izin'), 'start_date' => '2026-10-08', 'end_date' => '2026-10-09', 'reason' => 'Urusan keluarga'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan terkirim');

        $leave = \App\Models\LeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $leave->status);
        $this->assertSame('2026-10-08', $leave->start_date->toDateString());
        $this->assertSame('2026-10-09', $leave->end_date->toDateString());
        $this->assertSame($this->store->id, (int) $leave->store_id);
        $this->assertNotEmpty($leave->request_number);
    }

    public function test_only_sick_leave_may_be_filed_after_the_fact_and_izin_must_come_before_the_day(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        // 7 Okt 2026 (Rabu); 6 Okt (kemarin) sudah lewat.
        Livewire::test(MyAttendance::class)
            ->callAction('leave', ['leave_type_id' => $this->leaveType('izin'), 'start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'reason' => 'Lupa mengajukan'])
            ->assertNotified('Pengajuan ditolak');

        $this->assertSame(0, \App\Models\LeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->count());

        // Sakit: hari H dan besoknya saja (kemarin masih boleh, lusa tidak), dan wajib surat keterangan sakit dari dokter (2026-11-23).
        Livewire::test(MyAttendance::class)
            ->callAction('leave', ['leave_type_id' => $this->leaveType('sakit'), 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'reason' => 'Demam', 'document' => UploadedFile::fake()->image('surat.jpg', 600, 600)])
            ->assertHasActionErrors(['start_date']);   // lusa: tanggal sudah di luar batas kalender (hari H dan kemarin)

        Livewire::test(MyAttendance::class)
            ->callAction('leave', ['leave_type_id' => $this->leaveType('sakit'), 'start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'reason' => 'Demam'])
            ->assertNotified('Pengajuan ditolak');

        Livewire::test(MyAttendance::class)
            ->callAction('leave', ['leave_type_id' => $this->leaveType('sakit'), 'start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'reason' => 'Demam', 'document' => UploadedFile::fake()->image('surat.jpg', 600, 600)])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan terkirim');

        $this->assertSame(1, \App\Models\LeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_overlapping_and_over_quota_requests_are_refused_like_in_the_app(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->callAction('leave', ['leave_type_id' => $this->leaveType('izin'), 'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'reason' => 'Pertama'])
            ->assertNotified('Pengajuan terkirim');

        Livewire::test(MyAttendance::class)
            ->callAction('leave', ['leave_type_id' => $this->leaveType('izin'), 'start_date' => '2026-10-13', 'end_date' => '2026-10-14', 'reason' => 'Tumpang tindih'])
            ->assertNotified('Pengajuan ditolak');

        // Cuti tahunan lebih lama dari sisa jatah.
        $remaining = \App\Models\LeaveRequest::remainingCutiFor($user, 2026);
        $end = \Illuminate\Support\Carbon::parse('2026-11-02')->addDays($remaining)->toDateString();   // remaining + 1 hari

        Livewire::test(MyAttendance::class)
            ->callAction('leave', ['leave_type_id' => $this->leaveType('cuti'), 'start_date' => '2026-11-02', 'end_date' => $end, 'reason' => 'Terlalu panjang'])
            ->assertNotified('Pengajuan ditolak');

        $this->assertSame(1, \App\Models\LeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_a_sick_note_can_be_attached_from_the_panel(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->callAction('leave', [
                'leave_type_id' => $this->leaveType('sakit'),
                'start_date' => '2026-10-07',
                'end_date' => '2026-10-07',
                'reason' => 'Flu berat',
                'document' => UploadedFile::fake()->image('surat-dokter.jpg', 600, 600),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan terkirim');

        $leave = \App\Models\LeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($leave->document, 'Lampiran tersimpan.');
        Storage::disk('local')->assertExists($leave->document);
    }

    public function test_the_leave_list_shows_only_my_requests_and_they_can_be_cancelled(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $other = $this->staff('kasir', ['MyAttendance']);
        $izin = $this->leaveType('izin');
        $mine = \App\Models\LeaveRequest::createLocked(['user_id' => $me->id, 'store_id' => $this->store->id, 'type' => 'izin', 'leave_type_id' => $izin, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'reason' => 'Alasan milik saya', 'status' => 'pending']);
        $theirs = \App\Models\LeaveRequest::createLocked(['user_id' => $other->id, 'store_id' => $this->store->id, 'type' => 'izin', 'leave_type_id' => $izin, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'reason' => 'Alasan orang lain', 'status' => 'pending']);

        $this->actingAs($me);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'pengajuan')
            ->assertSee('Alasan milik saya')
            ->assertDontSee('Alasan orang lain')
            ->callAction('cancelLeave', arguments: ['id' => $theirs->id])
            ->assertNotified('Pengajuan tidak ditemukan')
            ->callAction('cancelLeave', arguments: ['id' => $mine->id])
            ->assertNotified('Pengajuan dibatalkan');

        $this->assertSame('cancelled', $mine->fresh()->status);
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_a_decided_leave_cannot_be_cancelled_from_the_panel(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $leave = \App\Models\LeaveRequest::createLocked(['user_id' => $me->id, 'store_id' => $this->store->id, 'type' => 'izin', 'leave_type_id' => $this->leaveType('izin'), 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'reason' => 'Sudah diputuskan', 'status' => 'approved']);

        $this->actingAs($me);

        Livewire::test(MyAttendance::class)
            ->callAction('cancelLeave', arguments: ['id' => $leave->id])
            ->assertNotified('Tidak bisa dibatalkan');

        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_the_pengajuan_tab_shows_the_cuti_quota_line(): void
    {
        $this->actingAs($this->staff('kasir', ['MyAttendance']));

        Livewire::test(MyAttendance::class)->set('tab', 'pengajuan')->assertSee('Jatah cuti 2026');
    }

    // ------------------------------------------------------------- Izin Sebagian Hari di tab Pengajuan (2026-11-22)

    public function test_staff_with_only_the_absen_saya_menu_can_file_a_late_arrival_and_an_early_leave(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'pengajuan')
            ->callAction('partialLeave', ['category' => 'late_arrival', 'date' => '2026-10-08', 'time' => '10:30', 'reason' => 'Antar anak ke dokter'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan izin sebagian hari terkirim');

        Livewire::test(MyAttendance::class)
            ->callAction('partialLeave', ['category' => 'early_leave', 'date' => '2026-10-09', 'time' => '15:00', 'reason' => 'Urusan bank'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan izin sebagian hari terkirim');

        $rows = \App\Models\PartialLeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Masuk paling lambat 10:30', $rows[0]->windowLabel());
        $this->assertSame('Pulang mulai 15:00', $rows[1]->windowLabel());
        $this->assertSame('pending', $rows[0]->status);
        $this->assertSame($this->store->id, (int) $rows[0]->store_id);
    }

    public function test_leaving_the_office_needs_a_return_time_after_the_exit_time(): void
    {
        $user = $this->staff('kasir', ['MyAttendance']);
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->callAction('partialLeave', ['category' => 'leave_office', 'date' => '2026-10-08', 'start_time' => '14:00', 'end_time' => '13:00', 'reason' => 'Kebalik'])
            ->assertNotified('Pengajuan ditolak');

        $this->assertSame(0, \App\Models\PartialLeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->count());

        Livewire::test(MyAttendance::class)
            ->callAction('partialLeave', ['category' => 'leave_office', 'date' => '2026-10-08', 'start_time' => '13:00', 'end_time' => '14:30', 'reason' => 'Makan siang dengan klien'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pengajuan izin sebagian hari terkirim');

        $row = \App\Models\PartialLeaveRequest::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('leave_office', $row->category);
        $this->assertStringContainsString('13:00', $row->windowLabel());
        $this->assertStringContainsString('14:30', $row->windowLabel());
    }

    public function test_the_partial_leave_date_window_is_enforced(): void
    {
        $this->actingAs($this->staff('kasir', ['MyAttendance']));

        Livewire::test(MyAttendance::class)
            ->callAction('partialLeave', ['category' => 'late_arrival', 'date' => '2026-11-30', 'time' => '10:00', 'reason' => 'Terlalu jauh'])
            ->assertHasActionErrors(['date']);

        $this->assertSame(0, \App\Models\PartialLeaveRequest::withoutGlobalScopes()->count());
    }

    public function test_the_partial_leave_list_shows_only_mine_and_a_pending_one_can_be_cancelled(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $other = $this->staff('kasir', ['MyAttendance']);
        $mine = \App\Models\PartialLeaveRequest::withoutGlobalScopes()->create(['user_id' => $me->id, 'store_id' => $this->store->id, 'date' => '2026-10-09', 'category' => 'late_arrival', 'start_time' => '00:00:00', 'end_time' => '10:00:00', 'reason' => 'Alasan sebagian milik saya', 'status' => 'pending']);
        $theirs = \App\Models\PartialLeaveRequest::withoutGlobalScopes()->create(['user_id' => $other->id, 'store_id' => $this->store->id, 'date' => '2026-10-09', 'category' => 'late_arrival', 'start_time' => '00:00:00', 'end_time' => '10:00:00', 'reason' => 'Alasan sebagian orang lain', 'status' => 'pending']);

        $this->actingAs($me);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'pengajuan')
            ->assertSee('Alasan sebagian milik saya')
            ->assertDontSee('Alasan sebagian orang lain')
            ->callAction('cancelPartialLeave', arguments: ['id' => $theirs->id])
            ->assertNotified('Pengajuan tidak ditemukan')
            ->callAction('cancelPartialLeave', arguments: ['id' => $mine->id])
            ->assertNotified('Pengajuan izin sebagian hari dibatalkan');

        $this->assertSame('cancelled', $mine->fresh()->status);
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    // ------------------------------------------------------------- tab Riwayat (2026-11-22)

    private function historyRow(User $user, string $date, string $type = 'clock', ?string $in = '09:05', ?string $out = '18:00', int $late = 0, ?string $note = null): Attendance
    {
        return Attendance::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => $type,
            'clock_in_at' => $in ? "$date $in:00" : null, 'clock_out_at' => $out ? "$date $out:00" : null,
            'late_minutes' => $late, 'early_leave_minutes' => 0, 'note' => $note,
        ]);
    }

    public function test_the_riwayat_tab_shows_my_month_with_a_summary_and_hides_other_peoples_rows(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $other = $this->staff('kasir', ['MyAttendance']);
        $this->historyRow($me, '2026-10-06', late: 5, note: 'Catatan milik saya');
        $this->historyRow($me, '2026-10-05', type: 'alpha', in: null, out: null);
        $this->historyRow($me, '2026-09-30', note: 'Catatan bulan lalu');
        $this->historyRow($other, '2026-10-06', note: 'Catatan orang lain');

        $this->actingAs($me);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'riwayat')
            ->assertSee('Riwayat Absensi Saya')
            ->assertSee('Catatan milik saya')
            ->assertDontSee('Catatan orang lain')
            ->assertDontSee('Catatan bulan lalu')
            ->assertSee('Alpha (Tanpa Keterangan)');

        $this->assertSame(1, Livewire::test(MyAttendance::class)->set('tab', 'riwayat')->instance()->myHistory()->where('entry_type', 'alpha')->count());
    }

    public function test_the_month_can_be_changed_and_invalid_months_fall_back_to_this_month(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $this->historyRow($me, '2026-10-06', note: 'Catatan Oktober');
        $this->historyRow($me, '2026-09-30', note: 'Catatan September');
        $this->actingAs($me);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'riwayat')
            ->set('month', '2026-09')
            ->assertSee('Catatan September')
            ->assertDontSee('Catatan Oktober')
            ->set('month', 'bukan-bulan')
            ->assertSee('Catatan Oktober')
            ->set('month', '2019-01')   // di luar 12 bulan terakhir
            ->assertSee('Catatan Oktober');
    }

    public function test_only_rows_that_need_a_correction_get_the_correction_button(): void
    {
        $me = $this->staff('kasir', ['MyAttendance']);
        $this->historyRow($me, '2026-10-06');                                    // lengkap
        $this->historyRow($me, '2026-10-05', type: 'alpha', in: null, out: null); // Alpha
        $this->historyRow($me, '2026-10-02', out: null);                          // lupa absen keluar
        $this->historyRow($me, '2026-10-01', type: 'leave', in: null, out: null); // izin
        $this->actingAs($me);

        $html = Livewire::test(MyAttendance::class)->set('tab', 'riwayat')->html();

        // Tiap tombol koreksi memanggil mountAction('correction', {date: ...}); hitung pemanggilnya, bukan tulisan di halaman.
        preg_match_all('/mountAction\(.{1,12}?correction.{1,60}?(\d{4}-\d{2}-\d{2})/', $html, $matches);
        $dates = collect($matches[1] ?? [])->unique()->sort()->values()->all();

        $this->assertSame(['2026-10-02', '2026-10-05'], $dates, 'Hanya Alpha dan yang lupa absen keluar yang bisa dikoreksi.');
    }

    public function test_the_correction_form_opens_with_the_date_of_the_chosen_row(): void
    {
        $this->actingAs($this->staff('kasir', ['MyAttendance']));

        Livewire::test(MyAttendance::class)
            ->mountAction('correction', arguments: ['date' => '2026-10-05'])
            ->assertActionDataSet(['date' => '2026-10-05']);

        Livewire::test(MyAttendance::class)
            ->mountAction('correction')
            ->assertActionDataSet(['date' => '2026-10-06']);   // bawaan: kemarin
    }
}
