<?php

namespace Tests\Feature;

use App\Filament\Resources\LeaveRequestResource;
use App\Filament\Resources\LeaveRequestResource\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequestResource\Pages\EditLeaveRequest;
use App\Filament\Resources\LeaveRequestResource\Pages\ListLeaveRequests;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Izin & Cuti (sisi admin/persetujuan): daftar per toko, buat atas nama
 * karyawan (validasi tanggal, durasi, tumpang tindih, jatah cuti), setujui/
 * tolak hanya oleh full-access dan hanya sekali, dampak ke absensi Alpha,
 * pengajuan yang sudah diputuskan terkunci, hapus, dan izin per aksi.
 */
class LeaveRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'spv_finance'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x',
            'store_id' => ($store ?? $this->store)->id, 'join_date' => '2024-01-01',
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function leave(User $employee, array $overrides = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'user_id' => $employee->id, 'store_id' => $employee->store_id, 'type' => 'izin',
            'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(4)->toDateString(), 'reason' => 'Urusan keluarga', 'status' => 'pending',
        ], $overrides));
    }

    private function form(User $employee, array $overrides = []): array
    {
        // Form memakai Jenis (leave_type_id); kategori 'type' diturunkan darinya. 'type' di sini hanya jalan pintas memilih jenis dasar.
        $category = $overrides['type'] ?? 'izin';
        unset($overrides['type']);

        return array_merge([
            'user_id' => $employee->id, 'store_id' => $employee->store_id, 'leave_type_id' => \App\Models\LeaveType::forCategory($category)->id,
            'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(4)->toDateString(), 'reason' => 'Urusan keluarga',
        ], $overrides);
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_store_scoped_filterable_and_the_badge_counts_pending_in_scope(): void
    {
        $manager = $this->user('store_manager');
        $mine = $this->leave($this->user('kasir'));
        $approved = $this->leave($this->user('kasir'), ['type' => 'cuti', 'status' => 'approved']);
        $theirs = $this->leave($this->user('kasir', $this->otherStore));

        $this->actingAs($manager, 'web');
        Livewire::test(ListLeaveRequests::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine, $approved])->assertCanNotSeeTableRecords([$theirs])
            ->filterTable('status', 'approved')
            ->assertCanSeeTableRecords([$approved])->assertCanNotSeeTableRecords([$mine]);
        Livewire::test(ListLeaveRequests::class)->filterTable('leave_type_id', \App\Models\LeaveType::forCategory('cuti')->id)
            ->assertCanSeeTableRecords([$approved])->assertCanNotSeeTableRecords([$mine]);
        $this->assertSame('1', LeaveRequestResource::getNavigationBadge());

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListLeaveRequests::class)->assertCanSeeTableRecords([$mine, $approved, $theirs]);
        $this->assertSame('2', LeaveRequestResource::getNavigationBadge());
    }

    // ------------------------------------------------------------- buat

    public function test_admin_creates_a_pending_request_for_an_employee_with_a_number(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');

        Livewire::test(CreateLeaveRequest::class)->fillForm($this->form($employee))->call('create')->assertHasNoFormErrors();

        $request = LeaveRequest::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertNotEmpty($request->request_number);
        $this->assertSame($this->store->id, $request->store_id);
    }

    public function test_a_store_managers_request_is_forced_into_their_own_store_and_pending(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');
        $employee = $this->user('kasir');

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm($this->form($employee, ['store_id' => $this->otherStore->id]))
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($this->store->id, LeaveRequest::where('user_id', $employee->id)->value('store_id'));
        $this->assertSame('pending', LeaveRequest::where('user_id', $employee->id)->value('status'));
    }

    public function test_create_form_validates_dates_duration_overlap_and_required_fields(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $this->leave($employee, ['start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(12)->toDateString()]);
        $create = fn (array $over) => Livewire::test(CreateLeaveRequest::class)->fillForm($this->form($employee, $over))->call('create');

        $create(['end_date' => now()->addDay()->toDateString()])->assertHasFormErrors(['end_date']);
        $create(['start_date' => now()->addDays(20)->toDateString(), 'end_date' => now()->addDays(60)->toDateString()])->assertHasFormErrors(['end_date']);
        $create(['start_date' => now()->addDays(11)->toDateString(), 'end_date' => now()->addDays(13)->toDateString()])->assertHasFormErrors(['end_date']);
        $create(['reason' => ''])->assertHasFormErrors(['reason' => 'required']);
        $create(['user_id' => null])->assertHasFormErrors(['user_id' => 'required']);

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_annual_leave_cannot_exceed_the_remaining_quota(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $newcomer = $this->user('kasir', null, ['join_date' => now()->toDateString()]);
        $tooLong = LeaveRequest::ANNUAL_CUTI_QUOTA_DAYS + 1;

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm($this->form($employee, ['type' => 'cuti', 'end_date' => now()->addDays(3 + $tooLong - 1)->toDateString()]))
            ->call('create')->assertHasFormErrors(['end_date']);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm($this->form($newcomer, ['type' => 'cuti']))
            ->call('create')->assertHasFormErrors(['end_date']);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm($this->form($newcomer, ['type' => 'sakit', 'end_date' => now()->addDays(3)->toDateString()]))
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(1, LeaveRequest::count(), 'Sakit tidak memotong jatah cuti.');
    }

    public function test_create_locked_rechecks_overlap_and_quota_inside_the_lock(): void
    {
        $employee = $this->user('kasir');
        $this->leave($employee, ['start_date' => '2026-12-10', 'end_date' => '2026-12-12']);

        foreach ([
            ['start_date' => '2026-12-12', 'end_date' => '2026-12-14', 'type' => 'izin'],
            ['start_date' => '2026-12-20', 'end_date' => '2027-01-05', 'type' => 'cuti'],
        ] as $data) {
            try {
                LeaveRequest::createLocked($data + ['user_id' => $employee->id, 'store_id' => $this->store->id, 'reason' => 'x', 'status' => 'pending']);
                $this->fail('Seharusnya ditolak di dalam lock.');
            } catch (RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertSame(1, LeaveRequest::count());
    }

    // ------------------------------------------------------------- setujui & tolak

    public function test_only_full_access_sees_decision_buttons_and_only_on_pending_requests(): void
    {
        $employee = $this->user('kasir');
        $pending = $this->leave($employee);
        $approved = $this->leave($employee, ['status' => 'approved', 'start_date' => now()->addDays(20)->toDateString(), 'end_date' => now()->addDays(21)->toDateString()]);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionVisible('approve', $pending)->assertTableActionVisible('reject', $pending)
            ->assertTableActionHidden('approve', $approved)->assertTableActionHidden('reject', $approved);

        $this->actingAs($this->user('store_manager'), 'web');
        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionVisible('approve', $pending)->assertTableActionVisible('reject', $pending)
            ->assertTableActionHidden('approve', $approved);
    }

    public function test_approving_records_the_reviewer_notifies_the_employee_and_turns_alpha_days_into_leave(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir');
        $start = now()->subDays(2)->startOfDay();
        $request = $this->leave($employee, ['start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(3)->toDateString()]);
        $alpha = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $start->copy()->addDay(), 'entry_type' => 'alpha']);
        $clock = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $start, 'entry_type' => 'clock', 'clock_in_at' => $start->copy()->setTime(9, 0)]);
        $outside = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $start->copy()->subDays(5), 'entry_type' => 'alpha']);
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => $ids === [$employee->id] && $title === 'Pengajuan Izin Disetujui');
        });
        $this->actingAs($admin, 'web');

        Livewire::test(ListLeaveRequests::class)->callTableAction('approve', $request);

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame('leave', $alpha->fresh()->entry_type, 'Alpha di rentang cuti menjadi Izin/Cuti.');
        $this->assertSame('clock', $clock->fresh()->entry_type, 'Absen asli tidak disentuh.');
        $this->assertSame('alpha', $outside->fresh()->entry_type, 'Alpha di luar rentang tidak disentuh.');
        $this->assertTrue(Activity::where('log_name', 'leave_request')->where('subject_id', $request->id)->where('event', 'updated')->exists());
    }

    public function test_rejecting_requires_a_reason_which_is_saved_and_sent_to_the_employee(): void
    {
        $employee = $this->user('kasir');
        $request = $this->leave($employee);
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
                && $title === 'Pengajuan Izin Ditolak' && str_contains($body, 'Stok karyawan kurang'));
        });
        $this->actingAs($this->user('super_admin'), 'web');

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('reject', $request, data: ['review_note' => ''])
            ->assertHasTableActionErrors(['review_note' => 'required']);
        $this->assertSame('pending', $request->fresh()->status);

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('reject', $request, data: ['review_note' => 'Stok karyawan kurang'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame('Stok karyawan kurang', $request->fresh()->review_note);
    }

    public function test_a_request_can_be_decided_only_once(): void
    {
        $admin = $this->user('super_admin');
        $request = $this->leave($this->user('kasir'));

        LeaveRequest::approveLocked($request->id, $admin->id);

        foreach ([fn () => LeaveRequest::approveLocked($request->id, $admin->id), fn () => LeaveRequest::rejectLocked($request->id, $admin->id, 'terlambat')] as $second) {
            try {
                $second();
                $this->fail('Keputusan kedua seharusnya ditolak.');
            } catch (RuntimeException $e) {
                $this->assertSame('Permintaan ini sudah diputuskan sebelumnya.', $e->getMessage());
            }
        }

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_an_employee_who_cancelled_cannot_be_approved_afterwards(): void
    {
        $request = $this->leave($this->user('kasir'), ['status' => 'cancelled']);

        try {
            LeaveRequest::approveLocked($request->id, $this->user('super_admin')->id);
            $this->fail('Pengajuan yang dibatalkan sendiri tidak bisa disetujui.');
        } catch (RuntimeException) {
        }

        $this->assertSame('cancelled', $request->fresh()->status);
    }

    // ------------------------------------------------------------- edit & hapus

    public function test_a_pending_request_can_be_edited_and_keeps_its_status(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');
        $request = $this->leave($this->user('kasir'));

        Livewire::test(EditLeaveRequest::class, ['record' => $request->getKey()])
            ->fillForm(['reason' => 'Alasan diperbarui', 'end_date' => now()->addDays(5)->toDateString()])
            ->call('save')->assertHasNoFormErrors();

        $fresh = $request->fresh();
        $this->assertSame('Alasan diperbarui', $fresh->reason);
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->reviewed_by);
    }

    public function test_a_decided_request_is_locked_even_when_the_edit_page_is_opened_directly(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $approved = $this->leave($this->user('kasir'), ['status' => 'approved']);
        $rejected = $this->leave($this->user('kasir'), ['status' => 'rejected']);

        foreach ([$approved, $rejected] as $decided) {
            $this->assertFalse(LeaveRequestResource::canEdit($decided));
            Livewire::test(EditLeaveRequest::class, ['record' => $decided->getKey()])->assertForbidden();
        }

        $this->assertSame(now()->addDays(4)->toDateString(), $approved->fresh()->end_date->toDateString());
    }

    public function test_only_full_access_can_delete_and_only_pending_requests(): void
    {
        $employee = $this->user('kasir');
        $pending = $this->leave($employee);
        $approved = $this->leave($employee, ['status' => 'approved', 'start_date' => now()->addDays(20)->toDateString(), 'end_date' => now()->addDays(21)->toDateString()]);

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertFalse((bool) LeaveRequestResource::canDelete($pending));

        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue((bool) LeaveRequestResource::canDelete($pending));
        $this->assertFalse((bool) LeaveRequestResource::canDelete($approved));

        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionHidden('delete', $approved)
            ->callTableAction('delete', $pending);

        $this->assertNull(LeaveRequest::find($pending->id));
        $this->assertNotNull(LeaveRequest::find($approved->id));
    }

    // ------------------------------------------------------------- izin

    public function test_permissions_follow_menu_access_and_module_actions(): void
    {
        $request = $this->leave($this->user('kasir'));
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]);
        $ownRequest = $this->leave($withMenu);

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(LeaveRequestResource::canViewAny());
        $this->assertTrue(LeaveRequestResource::canCreate());
        $this->assertTrue(LeaveRequestResource::canEdit($ownRequest));
        $this->assertFalse(LeaveRequestResource::canEdit($request), 'Pengajuan rekan bukan urusan staf biasa.');
        $this->assertFalse((bool) LeaveRequestResource::canDelete($request));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(LeaveRequestResource::canViewAny());
        $this->assertFalse(LeaveRequestResource::canCreate());
        $this->assertFalse(LeaveRequestResource::canEdit($request));
        $this->assertFalse(LeaveRequestResource::canEdit($ownRequest));
    }

    public function test_approving_rechecks_the_annual_quota_so_two_pending_requests_cannot_exceed_it(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir');
        $year = now()->year;
        $quota = LeaveRequest::annualQuotaFor($employee, $year);
        $this->assertGreaterThan(8, $quota);

        // Dua pengajuan menunggu yang masing-masing lolos saat diajukan (yang menunggu belum dihitung), tetapi bersama melewati jatah.
        $days = intdiv($quota, 2) + 1;
        $first = $this->leave($employee, ['type' => 'cuti', 'start_date' => "{$year}-03-02", 'end_date' => now()->setDate($year, 3, 2)->addDays($days - 1)->toDateString()]);
        $second = $this->leave($employee, ['type' => 'cuti', 'start_date' => "{$year}-05-04", 'end_date' => now()->setDate($year, 5, 4)->addDays($days - 1)->toDateString()]);

        LeaveRequest::approveLocked($first->id, $admin->id);

        try {
            LeaveRequest::approveLocked($second->id, $admin->id);
            $this->fail('Persetujuan kedua melewati jatah, seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak cukup', $e->getMessage());
        }

        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_approving_leave_that_does_not_use_the_quota_is_never_blocked_by_it(): void
    {
        $employee = $this->user('kasir', null, ['join_date' => now()->toDateString()]);
        $request = $this->leave($employee, ['type' => 'izin']);

        LeaveRequest::approveLocked($request->id, $this->user('super_admin')->id);

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_only_full_access_can_record_leave_for_past_dates_and_approval_turns_that_alpha_into_leave(): void
    {
        $employee = $this->user('kasir');
        $day = now()->subDays(2)->toDateString();
        $alpha = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $day, 'entry_type' => 'alpha']);
        $past = $this->form($employee, ['type' => 'sakit', 'start_date' => $day, 'end_date' => $day]);

        $this->actingAs($this->user('store_manager'), 'web');
        Livewire::test(CreateLeaveRequest::class)->fillForm($past)->call('create')->assertHasFormErrors(['start_date']);

        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        Livewire::test(CreateLeaveRequest::class)->fillForm($past)->call('create')->assertHasNoFormErrors();

        $request = LeaveRequest::where('user_id', $employee->id)->firstOrFail();
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        Livewire::test(ListLeaveRequests::class)->callTableAction('approve', $request);

        $this->assertSame('leave', $alpha->fresh()->entry_type, 'Sakit yang dicatat belakangan menghapus Alpha-nya.');
    }

    public function test_full_access_users_are_notified_of_a_new_request_but_not_the_requester(): void
    {
        $admin = $this->user('super_admin');
        $otherAdmin = $this->user('super_admin');
        $request = $this->leave($otherAdmin);

        $request->notifyApprovers();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $otherAdmin->notifications()->count(), 'Pengaju tidak diberi tahu tentang pengajuannya sendiri.');
        $this->assertSame(0, $this->user('kasir')->notifications()->count());
    }

    public function test_the_approval_modal_shows_reason_duration_quota_and_colleagues_on_leave_the_same_days(): void
    {
        $employee = $this->user('kasir', null, ['name' => 'Rina Pengaju']);
        $colleague = $this->user('kasir', null, ['name' => 'Budi Rekan']);
        $year = now()->year;
        $request = $this->leave($employee, ['type' => 'cuti', 'reason' => 'Pulang kampung', 'start_date' => "{$year}-03-02", 'end_date' => "{$year}-03-04"]);
        $this->leave($colleague, ['type' => 'izin', 'start_date' => "{$year}-03-03", 'end_date' => "{$year}-03-03"]);

        $method = new \ReflectionMethod(LeaveRequestResource::class, 'approvalContext');
        $method->setAccessible(true);
        $html = (string) $method->invoke(null, $request->fresh());

        $this->assertStringContainsString('3 hari', $html);
        $this->assertStringContainsString('Pulang kampung', $html);
        $this->assertStringContainsString('Sisa jatah cuti', $html);
        $this->assertStringContainsString('Tanpa lampiran', $html);
        $this->assertStringContainsString('Budi Rekan (menunggu)', $html);
    }

    public function test_the_list_shows_duration_reason_and_attachment_columns(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $request = $this->leave($this->user('kasir'), ['reason' => 'Urusan keluarga mendesak', 'document' => 'leave-requests/surat.pdf']);

        Livewire::test(ListLeaveRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertTableColumnStateSet('duration', 2, $request)
            ->assertTableColumnStateSet('document', true, $request)
            ->assertSee('Urusan keluarga mendesak');
    }

    public function test_regular_staff_only_see_and_file_their_own_leave_while_managers_see_the_store(): void
    {
        $staff = $this->user('kasir');
        $colleague = $this->user('kasir');
        $mine = $this->leave($staff);
        $theirs = $this->leave($colleague, ['type' => 'sakit']);

        $this->actingAs($staff, 'web');
        $this->assertFalse(LeaveRequestResource::seesWholeStore());
        Livewire::test(ListLeaveRequests::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm($this->form($colleague, ['start_date' => now()->addDays(20)->toDateString(), 'end_date' => now()->addDays(21)->toDateString()]))
            ->call('create');

        $this->assertSame(0, LeaveRequest::where('user_id', $colleague->id)->where('start_date', now()->addDays(20)->toDateString())->count());

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertTrue(LeaveRequestResource::seesWholeStore());
        Livewire::test(ListLeaveRequests::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_revoking_an_approval_frees_the_quota_and_restores_the_attendance_rows(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir');
        $year = now()->year;
        $request = $this->leave($employee, ['type' => 'cuti', 'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->addDays(2)->toDateString()]);
        $usedBefore = LeaveRequest::usedCutiDaysFor($employee, now()->subDays(2)->year);
        LeaveRequest::approveLocked($request->id, $admin->id);
        $this->assertSame(5, LeaveRequest::usedCutiDaysFor($employee, now()->subDays(2)->year) - $usedBefore);

        $past = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => now()->subDay()->toDateString(), 'entry_type' => 'leave']);
        $future = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => now()->addDay()->toDateString(), 'entry_type' => 'leave']);
        $worked = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => now()->subDays(2)->toDateString(), 'entry_type' => 'clock', 'clock_in_at' => now()->subDays(2)->setTime(9, 0)]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => $title === 'Persetujuan Izin Dicabut'));
        $this->actingAs($admin, 'web');

        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionVisible('revoke', $request->fresh())
            ->callTableAction('revoke', $request, data: ['review_note' => ''])
            ->assertHasTableActionErrors(['review_note' => 'required'])
            ->callTableAction('revoke', $request, data: ['review_note' => 'Rencana batal'])
            ->assertHasNoTableActionErrors();

        $fresh = $request->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertTrue($fresh->isRevoked());
        $this->assertSame('Rencana batal', $fresh->review_note);
        $this->assertSame($usedBefore, LeaveRequest::usedCutiDaysFor($employee, now()->subDays(2)->year), 'Jatah kembali.');
        $this->assertSame('alpha', $past->fresh()->entry_type, 'Tanggal yang sudah lewat kembali Alpha.');
        $this->assertNull(Attendance::find($future->id), 'Hari mendatang dihapus.');
        $this->assertSame('clock', $worked->fresh()->entry_type, 'Absen asli tidak disentuh.');

        $this->expectException(RuntimeException::class);
        LeaveRequest::revokeLocked($request->id, $admin->id, 'lagi');
    }

    public function test_the_revoke_action_is_only_for_full_access_on_approved_requests(): void
    {
        $pending = $this->leave($this->user('kasir'));
        $approved = $this->leave($this->user('kasir'), ['status' => 'approved']);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionHidden('revoke', $pending)->assertTableActionVisible('revoke', $approved);

        $this->actingAs($this->user('store_manager'), 'web');
        Livewire::test(ListLeaveRequests::class)->assertTableActionHidden('revoke', $approved);
    }

    public function test_store_manager_and_spv_finance_decide_for_their_own_store_but_never_their_own_request(): void
    {
        $employee = $this->user('kasir');
        $elsewhere = $this->user('kasir', $this->otherStore);
        $pending = $this->leave($employee);
        $otherStorePending = $this->leave($elsewhere);

        foreach (['store_manager', 'spv_finance'] as $role) {
            $decider = $this->user($role);
            $own = $this->leave($decider, ['start_date' => now()->addDays(30)->toDateString(), 'end_date' => now()->addDays(31)->toDateString()]);

            $this->assertTrue($decider->canDecideLeaveFor($pending), $role);
            $this->assertFalse($decider->canDecideLeaveFor($own), "{$role}: pengajuan sendiri");
            $this->assertFalse($decider->canDecideLeaveFor($otherStorePending), "{$role}: toko lain");

            $this->actingAs($decider, 'web');
            Livewire::test(ListLeaveRequests::class)
                ->assertTableActionVisible('approve', $pending)
                ->assertTableActionHidden('approve', $own);
        }

        $this->assertFalse($this->user('kasir')->canDecideLeaveFor($pending));
        $this->assertTrue($this->user('super_admin', $this->otherStore)->canDecideLeaveFor($pending), 'Akses penuh memutuskan semua toko.');
    }

    public function test_the_server_refuses_a_decision_from_someone_who_may_not_decide(): void
    {
        $request = $this->leave($this->user('kasir'));

        foreach ([$this->user('kasir'), $this->user('store_manager', $this->otherStore)] as $outsider) {
            try {
                LeaveRequest::approveLocked($request->id, $outsider->id);
                $this->fail('Seharusnya ditolak.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('tidak berhak', $e->getMessage());
            }
        }

        $this->assertSame('pending', $request->fresh()->status);

        LeaveRequest::approveLocked($request->id, $this->user('store_manager')->id);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_the_deciders_of_the_store_are_notified_of_a_new_request(): void
    {
        $manager = $this->user('store_manager');
        $finance = $this->user('spv_finance');
        $admin = $this->user('super_admin');
        $otherManager = $this->user('store_manager', $this->otherStore);
        $employee = $this->user('kasir');

        $this->leave($employee)->notifyApprovers();

        foreach ([$manager, $finance, $admin] as $who) {
            $this->assertSame(1, $who->notifications()->count());
        }
        $this->assertSame(0, $otherManager->notifications()->count());
        $this->assertSame(0, $employee->notifications()->count());
    }
}
