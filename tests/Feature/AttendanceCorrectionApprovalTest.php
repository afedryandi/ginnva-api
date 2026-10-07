<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceCorrectionRequestResource;
use App\Filament\Resources\AttendanceCorrectionRequestResource\Pages\ListAttendanceCorrectionRequests;
use App\Filament\Resources\AttendanceCorrectionRequestResource\Pages\ViewAttendanceCorrectionRequest;
use App\Filament\Resources\AttendanceResource\Pages\CreateAttendance;
use App\Filament\Resources\AttendanceResource\Pages\EditAttendance;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\ScheduleDayOverride;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Koreksi Absensi (sisi persetujuan): pengajuan memberi tahu atasan yang
 * tepat, setujui/tolak di Filament dengan pemisahan wewenang (tidak boleh
 * memutuskan pengajuan sendiri), keputusan hanya sekali, staf pengaju
 * diberi tahu, dan jam hasil koreksi MENGHITUNG ULANG menit telat/pulang
 * cepat (dipakai potongan gaji) — tidak lagi menempel dari absen lama.
 */
class AttendanceCorrectionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-09-21'; // Senin

    private Store $store;
    private Store $otherStore;
    private AttendanceCorrectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $hours = [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'open' => '09:00', 'close' => '18:00']];
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'late_tolerance_minutes' => 15, 'opening_hours' => $hours]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true, 'opening_hours' => $hours]);
        $this->service = app(AttendanceCorrectionService::class);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function submit(User $employee, array $overrides = [], ?User $by = null): AttendanceCorrectionRequest
    {
        return $this->service->submit(array_merge([
            'attendance_id' => null, 'user_id' => $employee->id, 'store_id' => $employee->store_id, 'date' => self::DATE,
            'entry_type' => 'manual', 'clock_in_at' => self::DATE . ' 08:55:00', 'clock_out_at' => self::DATE . ' 18:00:00', 'reason' => 'Lupa absen',
        ], $overrides), ($by ?? $employee)->id);
    }

    private function clockRow(User $employee, array $overrides = []): Attendance
    {
        return Attendance::create(array_merge([
            'user_id' => $employee->id, 'store_id' => $employee->store_id, 'date' => self::DATE, 'entry_type' => 'clock',
            'clock_in_at' => self::DATE . ' 09:40:00', 'late_minutes' => 25, 'clock_out_at' => self::DATE . ' 16:00:00', 'early_leave_minutes' => 105,
        ], $overrides));
    }

    // ------------------------------------------------------------- pengajuan & notifikasi

    public function test_submitting_notifies_full_access_and_the_same_store_manager_only(): void
    {
        $admin = $this->user('super_admin');
        $manager = $this->user('store_manager');
        $otherManager = $this->user('store_manager', $this->otherStore);
        $inactiveAdmin = $this->user('direksi', null, ['is_active' => false]);
        $colleague = $this->user('kasir');
        $employee = $this->user('kasir');

        $this->submit($employee);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame(0, $otherManager->notifications()->count());
        $this->assertSame(0, $inactiveAdmin->notifications()->count());
        $this->assertSame(0, $colleague->notifications()->count());
        $this->assertSame(0, Attendance::count(), 'Data absensi baru berubah setelah disetujui.');
    }

    // ------------------------------------------------------------- setujui

    public function test_approving_creates_the_attendance_and_notifies_the_requester(): void
    {
        $employee = $this->user('kasir');
        $manager = $this->user('store_manager');
        $request = $this->submit($employee, ['reason' => 'Device absen mati']);

        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
                && $title === 'Koreksi Absensi Disetujui');
        });

        $attendance = $this->service->approve($request, $manager->id, 'OK, sudah dicek CCTV');

        $this->assertSame('manual', $attendance->entry_type);
        $this->assertSame('Device absen mati', $attendance->note);
        $this->assertSame($manager->id, $attendance->recorded_by);
        $this->assertSame('2026-09-21 08:55:00', $attendance->clock_in_at->format('Y-m-d H:i:s'));

        $fresh = $request->fresh();
        $this->assertSame(AttendanceCorrectionRequest::STATUS_APPROVED, $fresh->status);
        $this->assertSame($manager->id, $fresh->reviewed_by);
        $this->assertSame('OK, sudah dicek CCTV', $fresh->review_notes);
        $this->assertSame($attendance->id, $fresh->attendance_id);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_approving_replaces_the_stale_lateness_of_the_old_row(): void
    {
        $employee = $this->user('kasir');
        $manager = $this->user('store_manager');
        $row = $this->clockRow($employee); // masuk 09:40 (telat 25), pulang 16:00 (pulang cepat 105)

        $this->service->approve($this->submit($employee, ['attendance_id' => $row->id]), $manager->id);

        $fresh = $row->fresh();
        $this->assertSame('manual', $fresh->entry_type);
        $this->assertSame(0, $fresh->late_minutes, 'Masuk 08:55 = tidak telat; telat 25 mnt dari absen lama harus hilang.');
        $this->assertSame(0, $fresh->early_leave_minutes, 'Pulang 18:00 = tidak pulang cepat.');
    }

    public function test_approved_times_that_are_actually_late_are_counted_as_late(): void
    {
        $employee = $this->user('kasir');
        $manager = $this->user('store_manager');

        $this->service->approve($this->submit($employee, [
            'clock_in_at' => self::DATE . ' 10:00:00', 'clock_out_at' => self::DATE . ' 16:00:00',
        ]), $manager->id);

        $row = Attendance::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame(45, $row->late_minutes, '60 menit setelah buka - toleransi 15.');
        $this->assertSame(105, $row->early_leave_minutes, '120 menit sebelum tutup - toleransi 15.');
    }

    public function test_lateness_follows_the_individual_shift_and_field_duty_is_never_late(): void
    {
        $employee = $this->user('kasir');
        $manager = $this->user('store_manager');
        $shift = Shift::create(['store_id' => $this->store->id, 'name' => 'Siang', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
        ScheduleDayOverride::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => self::DATE, 'shift_id' => $shift->id]);

        $this->service->approve($this->submit($employee, ['clock_in_at' => self::DATE . ' 14:10:00', 'clock_out_at' => self::DATE . ' 22:00:00']), $manager->id);
        $this->assertSame(0, Attendance::where('user_id', $employee->id)->value('late_minutes'), 'Shift siang mulai 14:00, bukan jam buka toko.');

        $driver = $this->user('kasir');
        $this->service->approve($this->submit($driver, ['entry_type' => 'field_duty', 'clock_in_at' => self::DATE . ' 13:00:00', 'clock_out_at' => self::DATE . ' 15:00:00']), $manager->id);
        $duty = Attendance::where('user_id', $driver->id)->firstOrFail();
        $this->assertSame(0, $duty->late_minutes);
        $this->assertSame(0, $duty->early_leave_minutes);
    }

    // ------------------------------------------------------------- tolak & aturan keputusan

    public function test_rejecting_keeps_attendance_untouched_and_tells_the_requester_why(): void
    {
        $employee = $this->user('kasir');
        $manager = $this->user('store_manager');
        $row = $this->clockRow($employee);
        $request = $this->submit($employee, ['attendance_id' => $row->id]);

        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
                && $title === 'Koreksi Absensi Ditolak' && str_contains($body, 'Tidak ada bukti'));
        });

        $this->service->reject($request, $manager->id, 'Tidak ada bukti');

        $this->assertSame(AttendanceCorrectionRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame('Tidak ada bukti', $request->fresh()->review_notes);
        $this->assertSame(25, $row->fresh()->late_minutes);
        $this->assertSame('clock', $row->fresh()->entry_type);
    }

    public function test_a_request_can_only_be_decided_once(): void
    {
        $employee = $this->user('kasir');
        $manager = $this->user('store_manager');
        $admin = $this->user('super_admin');
        $request = $this->submit($employee);
        $this->service->approve($request, $manager->id);

        foreach ([fn () => $this->service->approve($request, $admin->id), fn () => $this->service->reject($request, $admin->id, 'terlambat')] as $second) {
            try {
                $second();
                $this->fail('Keputusan kedua seharusnya ditolak.');
            } catch (RuntimeException $e) {
                $this->assertSame('Permintaan ini sudah diputuskan sebelumnya.', $e->getMessage());
            }
        }

        $this->assertSame(AttendanceCorrectionRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_nobody_can_decide_their_own_request(): void
    {
        $manager = $this->user('store_manager');
        $own = $this->submit($manager);

        foreach ([fn () => $this->service->approve($own, $manager->id), fn () => $this->service->reject($own, $manager->id, 'x')] as $attempt) {
            try {
                $attempt();
                $this->fail('Memutuskan pengajuan sendiri seharusnya ditolak.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('milik sendiri', $e->getMessage());
            }
        }

        $this->assertTrue($own->fresh()->isPending());
        $this->assertSame(0, Attendance::count());
    }

    // ------------------------------------------------------------- Filament

    public function test_list_is_scoped_by_role(): void
    {
        $manager = $this->user('store_manager');
        $mine = $this->user('kasir');
        $colleague = $this->user('kasir');
        $elsewhere = $this->user('kasir', $this->otherStore);
        $own = $this->submit($mine);
        $colleaguesRequest = $this->submit($colleague);
        $otherStoreRequest = $this->submit($elsewhere);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListAttendanceCorrectionRequests::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$own, $colleaguesRequest, $otherStoreRequest]);

        $this->actingAs($manager, 'web');
        Livewire::test(ListAttendanceCorrectionRequests::class)
            ->assertCanSeeTableRecords([$own, $colleaguesRequest])->assertCanNotSeeTableRecords([$otherStoreRequest]);

        $this->actingAs($mine, 'web');
        Livewire::test(ListAttendanceCorrectionRequests::class)
            ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$colleaguesRequest, $otherStoreRequest]);
    }

    public function test_decision_buttons_are_for_managers_on_other_peoples_pending_requests_only(): void
    {
        $manager = $this->user('store_manager');
        $employee = $this->user('kasir');
        $request = $this->submit($employee);
        $managersOwn = $this->submit($manager);
        $decided = $this->submit($this->user('kasir'));
        $this->service->reject($decided, $this->user('super_admin')->id, 'tidak valid');

        $this->actingAs($manager, 'web');
        Livewire::test(ListAttendanceCorrectionRequests::class)
            ->assertTableActionVisible('approve', $request)->assertTableActionVisible('reject', $request)
            ->assertTableActionHidden('approve', $managersOwn)->assertTableActionHidden('reject', $managersOwn)
            ->assertTableActionHidden('approve', $decided->fresh())->assertTableActionHidden('reject', $decided->fresh());

        $this->actingAs($this->user('kasir', null, ['name' => 'Pengaju Lain']), 'web');
        $colleagueOwn = $this->submit(User::where('name', 'Pengaju Lain')->firstOrFail());
        Livewire::test(ListAttendanceCorrectionRequests::class)
            ->assertTableActionHidden('approve', $colleagueOwn)->assertTableActionHidden('reject', $colleagueOwn);
    }

    public function test_approve_action_applies_the_correction_and_reject_requires_a_reason(): void
    {
        $manager = $this->user('store_manager');
        $employee = $this->user('kasir');
        $toApprove = $this->submit($employee, ['reason' => 'Lupa absen masuk']);
        $toReject = $this->submit($this->user('kasir'));
        $this->actingAs($manager, 'web');

        Livewire::test(ListAttendanceCorrectionRequests::class)
            ->callTableAction('approve', $toApprove, data: ['review_notes' => 'Disetujui'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('reject', $toReject, data: ['review_notes' => ''])
            ->assertHasTableActionErrors(['review_notes' => 'required']);

        $this->assertSame(AttendanceCorrectionRequest::STATUS_APPROVED, $toApprove->fresh()->status);
        $this->assertSame('Lupa absen masuk', Attendance::where('user_id', $employee->id)->value('note'));
        $this->assertTrue($toReject->fresh()->isPending());

        Livewire::test(ListAttendanceCorrectionRequests::class)
            ->callTableAction('reject', $toReject, data: ['review_notes' => 'Tidak sesuai CCTV'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(AttendanceCorrectionRequest::STATUS_REJECTED, $toReject->fresh()->status);
        $this->assertSame('Tidak sesuai CCTV', $toReject->fresh()->review_notes);
    }

    public function test_status_filter_and_detail_page(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $pending = $this->submit($this->user('kasir'));
        $approved = $this->submit($this->user('kasir'));
        $this->service->approve($approved, $this->user('store_manager')->id);

        Livewire::test(ListAttendanceCorrectionRequests::class)->filterTable('status', AttendanceCorrectionRequest::STATUS_PENDING)
            ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$approved]);

        Livewire::test(ViewAttendanceCorrectionRequest::class, ['record' => $pending->getKey()])->assertSuccessful();
    }

    public function test_requests_cannot_be_created_edited_or_deleted_from_the_panel(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $request = $this->submit($this->user('kasir'));

        $this->assertFalse(AttendanceCorrectionRequestResource::canCreate());
        $this->assertFalse(AttendanceCorrectionRequestResource::canEdit($request));
        $this->assertFalse(AttendanceCorrectionRequestResource::canDelete($request));
        $this->assertTrue(AttendanceCorrectionRequestResource::canViewAny());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(AttendanceCorrectionRequestResource::canViewAny());
    }

    // ------------------------------------------------------------- edit langsung atasan

    public function test_a_managers_direct_edit_also_refreshes_lateness(): void
    {
        $manager = $this->user('store_manager');
        $employee = $this->user('kasir');
        $row = $this->clockRow($employee);
        $this->actingAs($manager, 'web');

        Livewire::test(EditAttendance::class, ['record' => $row->getKey()])
            ->fillForm(['clock_in_at' => self::DATE . ' 08:58:00', 'clock_out_at' => self::DATE . ' 18:05:00', 'note' => 'Koreksi atasan, salah sensor'])
            ->call('save')->assertHasNoFormErrors();

        $fresh = $row->fresh();
        $this->assertSame(0, $fresh->late_minutes);
        $this->assertSame(0, $fresh->early_leave_minutes);
    }

    public function test_completing_an_existing_row_with_a_manual_entry_refreshes_lateness(): void
    {
        $manager = $this->user('store_manager');
        $employee = $this->user('kasir');
        $this->clockRow($employee, ['clock_out_at' => null, 'early_leave_minutes' => 0]);
        $this->actingAs($manager, 'web');

        Livewire::test(CreateAttendance::class)
            ->fillForm([
                'user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => self::DATE, 'entry_type' => 'manual',
                'clock_in_at' => self::DATE . ' 09:05:00', 'clock_out_at' => self::DATE . ' 18:00:00', 'note' => 'Lupa absen pulang',
            ])
            ->call('create')->assertHasNoFormErrors();

        $row = Attendance::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame(0, $row->late_minutes, 'Jam masuk dikoreksi ke 09:05 (dalam toleransi).');
        $this->assertSame(0, $row->early_leave_minutes);
    }
}
