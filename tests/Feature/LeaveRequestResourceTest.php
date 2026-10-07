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
        foreach (['kasir', 'store_manager'] as $role) {
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
        return array_merge([
            'user_id' => $employee->id, 'store_id' => $employee->store_id, 'type' => 'izin',
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
        Livewire::test(ListLeaveRequests::class)->filterTable('type', 'cuti')
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

        $create(['start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->toDateString()])->assertHasFormErrors(['start_date']);
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
            ->fillForm($this->form($newcomer, ['type' => 'sakit']))
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
            ->assertTableActionHidden('approve', $pending)->assertTableActionHidden('reject', $pending);
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

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(LeaveRequestResource::canViewAny());
        $this->assertTrue(LeaveRequestResource::canCreate());
        $this->assertTrue(LeaveRequestResource::canEdit($request));
        $this->assertFalse((bool) LeaveRequestResource::canDelete($request));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(LeaveRequestResource::canViewAny());
        $this->assertFalse(LeaveRequestResource::canCreate());
        $this->assertFalse(LeaveRequestResource::canEdit($request));
    }
}
