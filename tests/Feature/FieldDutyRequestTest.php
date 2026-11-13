<?php

namespace Tests\Feature;

use App\Filament\Resources\FieldDutyRequestResource;
use App\Filament\Resources\FieldDutyRequestResource\Pages\ListFieldDutyRequests;
use App\Models\Attendance;
use App\Models\FieldDutyRequest;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceAudience;
use App\Services\AttendanceCorrectionService;
use App\Services\FieldDutyRequestService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pengajuan Dinas Luar (2026-11-12): karyawan yang bekerja satu hari penuh di luar toko mengajukan dengan tujuan yang jelas, boleh susulan.
 * Disetujui akses penuh, store manager toko itu, atau pengguna yang diberi aksi Ubah; setelah itu absensi hari tersebut tercatat Dinas Luar.
 * "Sekarang" dibekukan Kamis 12 November 2026 pukul 10:00.
 */
class FieldDutyRequestTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private User $andi;
    private User $manager;
    private User $otherManager;
    private User $admin;
    private FieldDutyRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'super_admin', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-12 10:00:00');

        $this->store = $this->store('Toko A');
        $this->otherStore = $this->store('Toko B');
        $this->andi = $this->user('kasir', $this->store, 'Andi');
        $this->manager = $this->user('store_manager', $this->store, 'Manajer A');
        $this->otherManager = $this->user('store_manager', $this->otherStore, 'Manajer B');
        $this->admin = $this->user('super_admin', null, 'Admin');
        $this->service = app(FieldDutyRequestService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function store(string $name): Store
    {
        return Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => $name, 'is_active' => true,
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

    private function submit(array $override = [], ?User $as = null): FieldDutyRequest
    {
        return $this->service->submit($as ?? $this->andi, $override + [
            'date' => '2026-11-13',
            'description' => 'Kanvasing area Kelapa Gading dan sekitarnya',
        ]);
    }

    private function assertRejected(callable $submit, string $contains): void
    {
        try {
            $submit();
            $this->fail('Seharusnya ditolak: ' . $contains);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    // ------------------------------------------------------------- pengajuan

    public function test_a_valid_request_is_created_pending_and_tells_the_deciders(): void
    {
        $request = $this->submit();

        $this->assertSame('pending', $request->status);
        $this->assertSame('2026-11-13', $request->date->toDateString());
        $this->assertNotEmpty($this->manager->notifications()->get()->where('data.title', 'Pengajuan Dinas Luar Baru'), 'Store manager toko itu diberi tahu.');
        $this->assertNotEmpty($this->admin->notifications()->get()->where('data.title', 'Pengajuan Dinas Luar Baru'), 'Akses penuh diberi tahu.');
        $this->assertSame([], $this->otherManager->notifications()->get()->all(), 'Store manager toko lain tidak diganggu.');
        $this->assertSame([], $this->andi->notifications()->get()->all(), 'Pemohon tidak diberi tahu soal pengajuannya sendiri.');
    }

    public function test_it_can_be_filed_after_the_fact_but_only_within_the_window(): void
    {
        $this->assertSame('2026-11-11', $this->submit(['date' => '2026-11-11'])->date->toDateString());
        $this->assertSame('2026-11-05', $this->submit(['date' => '2026-11-05'])->date->toDateString(), '7 hari ke belakang masih boleh.');

        $this->assertRejected(fn () => $this->submit(['date' => '2026-11-04']), 'paling lama 7 hari');
        $this->assertRejected(fn () => $this->submit(['date' => '2026-11-27']), '14 hari ke depan');
    }

    public function test_the_purpose_is_required(): void
    {
        $this->assertRejected(fn () => $this->submit(['description' => '']), 'Jelaskan tujuan');
        $this->assertRejected(fn () => $this->submit(['description' => '   ']), 'Jelaskan tujuan');
    }

    public function test_one_request_per_day_and_not_on_leave_or_after_attending(): void
    {
        $this->submit();
        $this->assertRejected(fn () => $this->submit(), 'sudah punya pengajuan');

        LeaveRequest::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-11-16', 'end_date' => '2026-11-16', 'reason' => 'x', 'status' => 'approved']);
        $this->assertRejected(fn () => $this->submit(['date' => '2026-11-16']), 'izin/cuti');

        Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-12', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-12 08:55:00']);
        $this->assertRejected(fn () => $this->submit(['date' => '2026-11-12']), 'sudah ada catatan absensi');
    }

    public function test_an_alpha_day_can_still_be_filed_after_the_fact(): void
    {
        Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'alpha']);

        $this->assertSame('pending', $this->submit(['date' => '2026-11-11'])->status);
    }

    // ------------------------------------------------------------- keputusan

    public function test_approval_records_the_day_as_field_duty_and_tells_the_requester(): void
    {
        $request = $this->submit();
        $this->service->approve($request, $this->manager, 'Hati-hati');

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($this->manager->id, $request->reviewed_by);

        $attendance = Attendance::withoutGlobalScopes()->where('user_id', $this->andi->id)->whereDate('date', '2026-11-13')->first();
        $this->assertSame('field_duty', $attendance->entry_type);
        $this->assertNull($attendance->clock_in_at);
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame('Kanvasing area Kelapa Gading dan sekitarnya', $attendance->note);
        $this->assertNotEmpty($this->andi->notifications()->get()->where('data.title', 'Pengajuan Dinas Luar Disetujui'));
    }

    public function test_approval_replaces_a_system_alpha_for_the_same_day(): void
    {
        Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-11', 'entry_type' => 'alpha']);
        $request = $this->submit(['date' => '2026-11-11']);

        $this->service->approve($request, $this->admin);

        $rows = Attendance::withoutGlobalScopes()->where('user_id', $this->andi->id)->whereDate('date', '2026-11-11')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('field_duty', $rows->first()->entry_type);
    }

    public function test_approval_never_overwrites_an_attendance_made_after_filing(): void
    {
        $request = $this->submit(['date' => '2026-11-12']);
        Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-12', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-12 09:05:00']);

        $this->assertRejected(fn () => $this->service->approve($request, $this->manager), 'sudah punya catatan absensi');
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_only_the_right_people_can_decide(): void
    {
        $request = $this->submit();

        $this->assertRejected(fn () => $this->service->approve($request, $this->andi), 'milik sendiri');
        $this->assertRejected(fn () => $this->service->approve($request, $this->otherManager), 'Hanya akses penuh');
        $this->assertRejected(fn () => $this->service->approve($request, $this->user('kasir', $this->store, 'Rekan')), 'Hanya akses penuh');

        $managerRequest = $this->submit(['date' => '2026-11-14'], $this->manager);
        $this->assertRejected(fn () => $this->service->approve($managerRequest, $this->manager), 'milik sendiri');
        $this->service->approve($managerRequest, $this->admin);
        $this->assertSame('approved', $managerRequest->fresh()->status);
    }

    public function test_a_user_granted_the_update_action_can_decide_for_their_own_store_only(): void
    {
        $granted = $this->user('kasir', $this->store, 'Admin HR Toko A');
        $granted->update([
            'menu_access' => ['FieldDutyRequestResource'],
            'menu_permissions' => ['FieldDutyRequestResource' => ['update']],
        ]);

        $otherStaff = $this->user('kasir', $this->otherStore, 'Staf Toko B');
        $otherRequest = $this->submit(['date' => '2026-11-14'], $otherStaff);
        $this->assertRejected(fn () => $this->service->approve($otherRequest, $granted), 'Hanya akses penuh');

        $request = $this->submit();
        $this->assertNotEmpty($granted->notifications()->get()->where('data.title', 'Pengajuan Dinas Luar Baru'), 'Pemegang hak ikut diberi tahu.');

        $this->service->approve($request, $granted);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_rejection_needs_a_reason_and_leaves_attendance_alone(): void
    {
        $request = $this->submit();

        $this->assertRejected(fn () => $this->service->reject($request, $this->manager, '  '), 'Alasan penolakan wajib');

        $this->service->reject($request, $this->manager, 'Tidak ada surat tugas');

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(0, Attendance::withoutGlobalScopes()->where('user_id', $this->andi->id)->count());
        $this->assertStringContainsString('Tidak ada surat tugas', $this->andi->notifications()->get()->firstWhere('data.title', 'Pengajuan Dinas Luar Ditolak')->data['body']);
    }

    public function test_a_decided_request_cannot_be_decided_twice(): void
    {
        $request = $this->submit();
        $this->service->approve($request, $this->manager);

        $this->assertRejected(fn () => $this->service->reject($request, $this->admin, 'Salah'), 'sudah diputuskan');
    }

    // ------------------------------------------------------------- pembatalan

    public function test_cancelling_an_approved_future_day_removes_the_field_duty_record(): void
    {
        $request = $this->submit();
        $this->service->approve($request, $this->manager);

        $this->service->cancel($request, $this->andi->id);

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame(0, Attendance::withoutGlobalScopes()->where('user_id', $this->andi->id)->count());
    }

    public function test_an_approved_day_that_has_arrived_cannot_be_cancelled_and_others_cannot_cancel(): void
    {
        $request = $this->submit(['date' => '2026-11-12']);
        $this->service->approve($request, $this->manager);

        $this->assertRejected(fn () => $this->service->cancel($request, $this->andi->id), 'tidak bisa dibatalkan');
        $this->assertRejected(fn () => $this->service->cancel($request, $this->manager->id), 'bukan milik Anda');
    }

    // ------------------------------------------------------------- dinas sebagian hari

    private function partial(string $date, string $from, string $to, ?User $as = null): FieldDutyRequest
    {
        return $this->submit(['date' => $date, 'start_time' => $from, 'end_time' => $to], $as);
    }

    private function clockRow(string $date, string $in, ?string $out = null, int $late = 0): Attendance
    {
        return Attendance::create([
            'user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => 'clock',
            'clock_in_at' => "{$date} {$in}", 'clock_out_at' => $out ? "{$date} {$out}" : null, 'late_minutes' => $late,
        ]);
    }

    public function test_partial_day_needs_a_valid_window(): void
    {
        $this->assertRejected(fn () => $this->submit(['date' => '2026-11-13', 'start_time' => '10:00']), 'jam mulai dan jam selesai');
        $this->assertRejected(fn () => $this->partial('2026-11-13', '13:00', '10:00'), 'harus setelah jam mulai');
        $this->assertRejected(fn () => $this->partial('2026-11-13', '10:00', '10:20'), 'minimal 30 menit');

        $request = $this->partial('2026-11-13', '10:00', '13:00');
        $this->assertTrue($request->isPartialDay());
        $this->assertSame('10:00–13:00', $request->windowLabel());
    }

    public function test_a_day_can_have_several_non_overlapping_windows_but_not_a_full_day_beside_them(): void
    {
        $this->partial('2026-11-13', '10:00', '12:00');
        $this->partial('2026-11-13', '14:00', '16:00');

        $this->assertRejected(fn () => $this->partial('2026-11-13', '11:00', '13:00'), 'bertabrakan');
        $this->assertRejected(fn () => $this->submit(['date' => '2026-11-13']), 'sudah punya pengajuan');

        $this->submit(['date' => '2026-11-14']);
        $this->assertRejected(fn () => $this->partial('2026-11-14', '10:00', '12:00'), 'sudah punya pengajuan');
    }

    public function test_a_partial_day_can_be_filed_after_clocking_in_unlike_a_full_day(): void
    {
        $this->clockRow('2026-11-11', '14:00', null, 300);

        $this->assertRejected(fn () => $this->submit(['date' => '2026-11-11']), 'sudah ada catatan absensi');
        $this->assertSame('pending', $this->partial('2026-11-11', '10:00', '13:00')->status);
    }

    public function test_approval_discounts_only_the_covered_minutes_from_lateness(): void
    {
        // Toko buka 09:00, absen masuk 14:00 = 300 menit telat; dinas 10:00-13:00 menutup 180 menit.
        $row = $this->clockRow('2026-11-11', '14:00', null, 300);
        $request = $this->partial('2026-11-11', '10:00', '13:00');

        $this->service->approve($request, $this->manager);

        $this->assertSame(120, $row->fresh()->late_minutes);
        $this->assertSame('clock', $row->fresh()->entry_type, 'Dinas sebagian hari tidak mengubah jenis absensi.');
    }

    public function test_the_approver_can_widen_the_window_to_what_really_happened(): void
    {
        $row = $this->clockRow('2026-11-11', '14:00', null, 300);
        $request = $this->partial('2026-11-11', '10:00', '13:00');

        $this->service->approve($request, $this->manager, null, ['start_time' => '09:00', 'end_time' => '14:00']);

        $this->assertSame('09:00–14:00', $request->fresh()->windowLabel());
        $this->assertSame(0, $row->fresh()->late_minutes);

        $other = $this->partial('2026-11-13', '10:00', '12:00');
        $this->assertRejected(fn () => $this->service->approve($other, $this->manager, null, ['start_time' => '12:00', 'end_time' => '10:00']), 'harus setelah jam mulai');
    }

    public function test_a_window_that_reaches_closing_time_closes_the_open_clock_out(): void
    {
        $row = $this->clockRow('2026-11-11', '08:55');
        $request = $this->partial('2026-11-11', '10:00', '20:00');

        $this->service->approve($request, $this->manager);

        $row->refresh();
        $this->assertSame('2026-11-11 18:00:00', $row->clock_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $row->early_leave_minutes);
        $this->assertStringContainsString('otomatis', $row->note);
    }

    public function test_a_window_that_ends_before_closing_leaves_the_clock_out_to_the_employee(): void
    {
        $row = $this->clockRow('2026-11-11', '08:55');

        $this->service->approve($this->partial('2026-11-11', '10:00', '13:00'), $this->manager);

        $this->assertNull($row->fresh()->clock_out_at);
    }

    public function test_the_nightly_pass_closes_a_day_approved_in_advance(): void
    {
        $row = $this->clockRow('2026-11-12', '08:55');
        $request = $this->partial('2026-11-12', '10:00', '20:00');

        $this->service->approve($request, $this->manager); // sekarang 10:00, jam tutup belum lewat
        $this->assertNull($row->fresh()->clock_out_at);

        Carbon::setTestNow('2026-11-13 03:00:00');
        $this->assertSame(1, $this->service->closeCoveredRows(Carbon::parse('2026-11-12')));
        $this->assertSame('2026-11-12 18:00:00', $row->fresh()->clock_out_at->format('Y-m-d H:i:s'));
    }

    public function test_early_leave_covered_by_a_window_is_not_counted(): void
    {
        // Keluar 15:00 padahal tutup 18:00: 180 menit, dikurangi toleransi 15 = 165 menit pulang cepat.
        $row = $this->clockRow('2026-11-11', '09:00', '15:00');
        $row->recalculateTiming();
        $this->assertSame(165, $row->fresh()->early_leave_minutes);

        $this->service->approve($this->partial('2026-11-11', '15:00', '19:00'), $this->manager);

        $this->assertSame(0, $row->fresh()->early_leave_minutes);
    }

    /** Koreksi Absen karyawan yang lupa absen sama sekali (masuk 12:00, pulang jam tutup), lewat layanan yang sama dengan aplikasi dan panel. */
    private function correct(string $date, string $in, string $out): Attendance
    {
        $correction = app(AttendanceCorrectionService::class)->submit([
            'attendance_id' => null, 'user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => $date,
            'entry_type' => 'manual', 'clock_in_at' => "{$date} {$in}", 'clock_out_at' => "{$date} {$out}",
            'reason' => 'Lupa absen masuk dan keluar karena dinas luar pagi hari',
        ], $this->andi->id);

        return app(AttendanceCorrectionService::class)->approve($correction, $this->manager->id);
    }

    public function test_a_partial_duty_then_a_correction_for_the_rest_of_the_day_is_a_full_attendance_without_lateness(): void
    {
        // Toko 09:00-18:00. Dinas 08:30-12:00 disetujui dulu, lalu koreksi masuk 12:00 pulang 18:00.
        $this->service->approve($this->partial('2026-11-11', '08:30', '12:00'), $this->manager);

        $row = $this->correct('2026-11-11', '12:00', '18:00');

        $this->assertSame('manual', $row->entry_type, 'Tetap tercatat hadir (bukan alpha, bukan field_duty).');
        $this->assertSame(0, $row->fresh()->late_minutes, 'Jam 09:00-12:00 tercakup dinas.');
        $this->assertSame(0, $row->fresh()->early_leave_minutes);
    }

    public function test_the_order_does_not_matter_correction_first_then_the_duty_is_approved(): void
    {
        $request = $this->partial('2026-11-11', '08:30', '12:00');
        $row = $this->correct('2026-11-11', '12:00', '18:00');
        $this->assertSame(180, $row->fresh()->late_minutes, 'Tanpa dinas yang disetujui, 3 jam itu dihitung telat.');

        $this->service->approve($request, $this->manager);

        $this->assertSame(0, $row->fresh()->late_minutes);
        $this->assertSame('manual', $row->fresh()->entry_type);
    }

    public function test_only_the_hours_outside_the_approved_duty_are_still_counted_late(): void
    {
        // Dinas hanya 10:00-12:00: jam 09:00-10:00 tidak tercakup, tetap 60 menit telat.
        $this->service->approve($this->partial('2026-11-11', '10:00', '12:00'), $this->manager);

        $row = $this->correct('2026-11-11', '12:00', '18:00');

        $this->assertSame(60, $row->fresh()->late_minutes);
    }

    public function test_a_partial_window_does_not_excuse_the_missing_clock_in_reminder(): void
    {
        $audience = app(AttendanceAudience::class);
        $this->service->approve($this->partial('2026-11-13', '10:00', '13:00'), $this->manager);

        $this->assertFalse($audience->onLeaveIds($this->store, Carbon::parse('2026-11-13'))->contains($this->andi->id));
    }

    public function test_staff_api_files_a_partial_day(): void
    {
        $this->actingAs($this->andi, 'api');

        $this->postJson('/api/staff/field-duty-requests', ['date' => '2026-11-13', 'start_time' => '10:00', 'end_time' => '13:00', 'description' => 'Kanvasing area Kelapa Gading'])
            ->assertCreated()
            ->assertJsonPath('field_duty_request.is_full_day', false)
            ->assertJsonPath('field_duty_request.start_time', '10:00')
            ->assertJsonPath('field_duty_request.end_time', '13:00');

        $this->postJson('/api/staff/field-duty-requests', ['date' => '2026-11-14', 'start_time' => '10:00', 'description' => 'Kanvasing area Kelapa Gading'])->assertStatus(422);
    }

    // ------------------------------------------------------------- pengingat absen

    public function test_a_field_duty_day_is_not_chased_as_a_missing_clock_in(): void
    {
        $request = $this->submit(['date' => '2026-11-12']);
        $audience = app(AttendanceAudience::class);

        $this->assertFalse($audience->onLeaveIds($this->store, Carbon::parse('2026-11-12'))->contains($this->andi->id), 'Menunggu: masih diharapkan absen.');

        $this->service->approve($request, $this->manager);

        $this->assertTrue($audience->onLeaveIds($this->store, Carbon::parse('2026-11-12'))->contains($this->andi->id));
    }

    // ------------------------------------------------------------- API aplikasi

    public function test_staff_api_can_file_list_and_cancel_their_own_requests(): void
    {
        $this->actingAs($this->andi, 'api');

        $response = $this->postJson('/api/staff/field-duty-requests', ['date' => '2026-11-13', 'description' => 'Kanvasing area Kelapa Gading'])
            ->assertCreated()
            ->assertJsonPath('field_duty_request.status', 'pending');
        $id = $response->json('field_duty_request.id');

        $this->getJson('/api/staff/field-duty-requests')
            ->assertOk()
            ->assertJsonCount(1, 'field_duty_requests')
            ->assertJsonPath('field_duty_requests.0.can_cancel', true);

        $this->postJson("/api/staff/field-duty-requests/{$id}/cancel")->assertOk();
        $this->assertSame('cancelled', FieldDutyRequest::withoutGlobalScopes()->find($id)->status);
    }

    public function test_staff_api_rejects_a_bad_request_and_hides_other_peoples_requests(): void
    {
        $others = $this->submit(['date' => '2026-11-13'], $this->manager);

        $this->actingAs($this->andi, 'api');
        $this->postJson('/api/staff/field-duty-requests', ['date' => '2026-11-13', 'description' => ''])->assertStatus(422);
        $this->postJson('/api/staff/field-duty-requests', ['date' => '2026-11-13'])->assertStatus(422);
        $this->getJson('/api/staff/field-duty-requests')->assertJsonCount(0, 'field_duty_requests');
        $this->postJson("/api/staff/field-duty-requests/{$others->id}/cancel")->assertStatus(404);
    }

    // ------------------------------------------------------------- panel

    public function test_the_panel_lists_by_role_and_the_deciders_see_the_approve_action(): void
    {
        $mine = $this->submit();
        $this->submit(['date' => '2026-11-14'], $this->user('kasir', $this->store, 'Rekan'));
        $this->submit(['date' => '2026-11-13'], $this->user('kasir', $this->otherStore, 'Staf Toko B'));

        $this->actingAs($this->andi, 'web');
        $this->assertSame([$mine->id], FieldDutyRequestResource::getEloquentQuery()->pluck('id')->all(), 'Karyawan biasa hanya melihat miliknya.');
        Livewire::test(ListFieldDutyRequests::class)
            ->assertTableActionHidden('approve', $mine)
            ->assertTableActionVisible('cancel', $mine);

        $this->actingAs($this->manager, 'web');
        $this->assertCount(2, FieldDutyRequestResource::getEloquentQuery()->get(), 'Store manager melihat pengajuan tokonya.');

        $this->actingAs($this->admin, 'web');
        $this->assertCount(3, FieldDutyRequestResource::getEloquentQuery()->get());
        Livewire::test(ListFieldDutyRequests::class)
            ->assertTableActionVisible('approve', $mine)
            ->callTableAction('approve', $mine, ['review_notes' => 'Setuju']);

        $this->assertSame('approved', $mine->fresh()->status);
    }
}
