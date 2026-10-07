<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkScheduleCalendarReport;
use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
use App\Models\LeaveRequest;
use App\Models\ScheduleDayOverride;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Jadwal Kerja Karyawan (kalender mingguan): isi sel dari jadwal template,
 * override harian, cuti disetujui dan hari tutup toko; pilihan minggu/cabang
 * yang aman; serta ubah jadwal per hari (validasi server, pemberitahuan ke
 * karyawan, audit) tanpa mengubah template.
 */
class WorkScheduleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private Shift $morning;
    private Shift $evening;
    private Carbon $nextMonday;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'], ['days' => ['sun'], 'closed' => true]],
        ]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->morning = Shift::create(['store_id' => $this->store->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        $this->evening = Shift::create(['store_id' => $this->store->id, 'name' => 'Sore', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
        $this->nextMonday = now()->addWeek()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function schedule(Shift $shift, string $name, array $offDays = ['sun']): WorkSchedule
    {
        return WorkSchedule::create([
            'store_id' => $shift->store_id, 'name' => $name, 'is_active' => true,
            'days' => collect(WorkSchedule::DAYS)->map(fn ($day) => ['day' => $day, 'shift_id' => in_array($day, $offDays, true) ? null : $shift->id])->all(),
        ]);
    }

    private function assign(WorkSchedule $schedule, User $user, array $overrides = []): EmployeeScheduleAssignment
    {
        return EmployeeScheduleAssignment::create(array_merge([
            'user_id' => $user->id, 'work_schedule_id' => $schedule->id, 'store_id' => $schedule->store_id, 'effective_from' => today()->subMonths(2),
        ], $overrides));
    }

    private function page(User $viewer, ?Carbon $week = null, ?Store $store = null)
    {
        $this->actingAs($viewer, 'web');

        $component = Livewire::test(WorkScheduleCalendarReport::class);
        $component->set('weekOf', ($week ?? $this->nextMonday)->toDateString());
        if ($viewer->isFullAccess()) {
            $component->set('storeId', ($store ?? $this->store)->id);
        }

        return $component;
    }

    private function row($component, User $employee): array
    {
        $found = $component->instance()->getCalendar()['rows']->first(fn ($r) => $r['employee']->id === $employee->id);
        $this->assertNotNull($found, "{$employee->name} tidak muncul di kalender.");

        return $found;
    }

    private function cell($component, User $employee, int $dayIndex): array
    {
        return $this->row($component, $employee)['cells']->all()[$dayIndex];
    }

    // ------------------------------------------------------------- isi kalender

    public function test_without_a_chosen_store_an_admin_sees_no_rows(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $calendar = Livewire::test(WorkScheduleCalendarReport::class)->instance()->getCalendar();

        $this->assertCount(0, $calendar['rows']);
        $this->assertCount(7, $calendar['days']);
    }

    public function test_only_active_non_partner_employees_of_the_store_are_listed_alphabetically(): void
    {
        $budi = $this->user('kasir', null, ['name' => 'Budi']);
        $ani = $this->user('kasir', null, ['name' => 'Ani']);
        $this->user('kasir', null, ['name' => 'Nonaktif', 'is_active' => false]);
        $this->user('partner', null, ['name' => 'Mitra']);
        $this->user('kasir', $this->otherStore, ['name' => 'Toko Lain']);

        $names = $this->page($this->user('super_admin'))->instance()->getCalendar()['rows']->map(fn ($r) => $r['employee']->name)->all();

        $this->assertSame(['Ani', 'Budi'], $names);
        $this->assertNotNull($budi);
        $this->assertNotNull($ani);
    }

    public function test_cells_follow_the_assigned_template_with_days_off_and_unassigned_markers(): void
    {
        $assigned = $this->user('kasir');
        $unassigned = $this->user('kasir');
        $this->assign($this->schedule($this->morning, 'Reguler'), $assigned);

        $page = $this->page($this->user('super_admin'));

        $monday = $this->cell($page, $assigned, 0);
        $this->assertSame('Pagi', $monday['label']);
        $this->assertSame('08:00–17:00', $monday['time']);
        $this->assertFalse($monday['is_override']);
        $this->assertSame('Libur', $this->cell($page, $assigned, 6)['label'], 'Minggu libur di pola.');
        $this->assertSame('—', $this->cell($page, $unassigned, 0)['label'], 'Belum punya jadwal.');
    }

    public function test_the_newest_assignment_wins_when_two_overlap(): void
    {
        $employee = $this->user('kasir');
        $this->assign($this->schedule($this->morning, 'Lama'), $employee, ['effective_from' => today()->subMonths(3)]);
        $this->assign($this->schedule($this->evening, 'Baru'), $employee, ['effective_from' => today()->subMonth()]);

        $this->assertSame('Sore', $this->cell($this->page($this->user('super_admin')), $employee, 1)['label']);
    }

    public function test_an_assignment_that_starts_mid_week_only_applies_from_that_day(): void
    {
        $employee = $this->user('kasir');
        $this->assign($this->schedule($this->morning, 'Mulai Rabu'), $employee, ['effective_from' => $this->nextMonday->copy()->addDays(2)]);

        $page = $this->page($this->user('super_admin'));

        $this->assertSame('—', $this->cell($page, $employee, 1)['label']);
        $this->assertSame('Pagi', $this->cell($page, $employee, 2)['label']);
    }

    public function test_day_overrides_replace_the_template_for_that_day_only(): void
    {
        $employee = $this->user('kasir');
        $this->assign($this->schedule($this->morning, 'Reguler'), $employee);
        ScheduleDayOverride::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $this->nextMonday->copy()->addDay(), 'shift_id' => $this->evening->id, 'reason' => 'Tukar shift']);
        ScheduleDayOverride::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $this->nextMonday->copy()->addDays(2), 'shift_id' => null, 'reason' => 'Libur khusus']);

        $page = $this->page($this->user('super_admin'));

        $tuesday = $this->cell($page, $employee, 1);
        $this->assertSame('Sore', $tuesday['label']);
        $this->assertTrue($tuesday['is_override']);
        $this->assertSame('Tukar shift', $tuesday['reason']);
        $wednesday = $this->cell($page, $employee, 2);
        $this->assertSame('Libur', $wednesday['label']);
        $this->assertTrue($wednesday['is_override']);
        $this->assertSame('Pagi', $this->cell($page, $employee, 0)['label'], 'Hari lain tetap ikut template.');
    }

    public function test_only_approved_leave_is_marked_on_the_cells(): void
    {
        $employee = $this->user('kasir');
        $this->assign($this->schedule($this->morning, 'Reguler'), $employee);
        LeaveRequest::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'type' => 'cuti', 'start_date' => $this->nextMonday->copy()->addDay(), 'end_date' => $this->nextMonday->copy()->addDays(2), 'reason' => 'Liburan', 'status' => 'approved']);
        LeaveRequest::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => $this->nextMonday->copy()->addDays(4), 'end_date' => $this->nextMonday->copy()->addDays(4), 'reason' => 'Acara', 'status' => 'pending']);

        $page = $this->page($this->user('super_admin'));

        $this->assertNull($this->cell($page, $employee, 0)['leave']);
        $this->assertSame('CUTI', $this->cell($page, $employee, 1)['leave']);
        $this->assertSame('CUTI', $this->cell($page, $employee, 2)['leave']);
        $this->assertNull($this->cell($page, $employee, 3)['leave']);
        $this->assertNull($this->cell($page, $employee, 4)['leave'], 'Pengajuan yang belum disetujui tidak ditandai.');
    }

    public function test_store_closed_days_are_flagged(): void
    {
        $calendar = $this->page($this->user('super_admin'))->instance()->getCalendar();

        $this->assertSame([false, false, false, false, false, false, true], $calendar['closedDays']);
    }

    // ------------------------------------------------------------- minggu & cabang

    public function test_any_date_resolves_to_its_monday_week_and_the_week_can_be_shifted(): void
    {
        $page = $this->page($this->user('super_admin'), $this->nextMonday->copy()->addDays(4));

        $calendar = $page->instance()->getCalendar();
        $this->assertTrue($calendar['weekStart']->isSameDay($this->nextMonday));
        $this->assertTrue($calendar['days'][6]->isSameDay($this->nextMonday->copy()->addDays(6)));

        $page->call('shiftWeek', 7);
        $this->assertTrue($page->instance()->getCalendar()['weekStart']->isSameDay($this->nextMonday->copy()->addWeek()));
        $page->call('shiftWeek', -14);
        $this->assertTrue($page->instance()->getCalendar()['weekStart']->isSameDay($this->nextMonday->copy()->subWeek()));
    }

    public function test_a_garbage_week_value_falls_back_to_the_current_week_without_crashing(): void
    {
        $page = $this->page($this->user('super_admin'));

        $page->set('weekOf', 'bukan-tanggal');

        $this->assertTrue($page->instance()->getCalendar()['weekStart']->isSameDay(now()->startOfWeek(Carbon::MONDAY)));
    }

    public function test_a_store_manager_always_sees_their_own_store_even_if_the_store_param_is_tampered(): void
    {
        $mine = $this->user('kasir');
        $theirs = $this->user('kasir', $this->otherStore);
        $manager = $this->user('store_manager');

        $page = $this->page($manager);
        $page->set('storeId', $this->otherStore->id);

        $ids = $page->instance()->getCalendar()['rows']->map(fn ($r) => $r['employee']->id)->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_access_follows_the_menu_access(): void
    {
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(WorkScheduleCalendarReport::canAccess());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(WorkScheduleCalendarReport::canAccess());
    }

    // ------------------------------------------------------------- ubah jadwal per hari

    private function override($page, User $employee, Carbon $date, array $data)
    {
        return $page->callAction('overrideDay', $data, ['userId' => $employee->id, 'date' => $date->toDateString()]);
    }

    private function overrideRow(User $employee, Carbon $date): ?ScheduleDayOverride
    {
        return ScheduleDayOverride::where('user_id', $employee->id)->whereDate('date', $date)->first();
    }

    public function test_setting_a_shift_creates_an_override_notifies_the_employee_and_leaves_the_template_alone(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir');
        $schedule = $this->schedule($this->morning, 'Reguler');
        $this->assign($schedule, $employee);
        $date = $this->nextMonday->copy()->addDay();
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
                && $title === 'Jadwal Kerja Diubah' && str_contains($body, 'Sore'));
        });

        $this->override($this->page($admin), $employee, $date, ['shift_choice' => $this->evening->id, 'reason' => 'Tukar shift dadakan'])->assertHasNoActionErrors();

        $row = $this->overrideRow($employee, $date);
        $this->assertSame($this->evening->id, $row->shift_id);
        $this->assertSame('Tukar shift dadakan', $row->reason);
        $this->assertSame($admin->id, $row->created_by);
        $this->assertSame($this->morning->id, $schedule->fresh()->shiftIdFor('tue'), 'Template tidak berubah.');
        $this->assertTrue(Activity::where('log_name', 'schedule_day_override')->where('subject_id', $row->id)->where('event', 'created')->exists());
    }

    public function test_marking_a_day_off_stores_a_null_shift_override(): void
    {
        $employee = $this->user('kasir');
        $date = $this->nextMonday->copy()->addDays(2);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => str_contains($body, 'LIBUR')));

        $this->override($this->page($this->user('super_admin')), $employee, $date, ['shift_choice' => '__off'])->assertHasNoActionErrors();

        $row = $this->overrideRow($employee, $date);
        $this->assertNotNull($row);
        $this->assertNull($row->shift_id);
    }

    public function test_changing_an_existing_override_updates_it_instead_of_duplicating(): void
    {
        $employee = $this->user('kasir');
        $date = $this->nextMonday->copy()->addDay();
        ScheduleDayOverride::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $date, 'shift_id' => $this->morning->id]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());

        $this->override($this->page($this->user('super_admin')), $employee, $date, ['shift_choice' => $this->evening->id])->assertHasNoActionErrors();

        $this->assertSame(1, ScheduleDayOverride::where('user_id', $employee->id)->count());
        $this->assertSame($this->evening->id, $this->overrideRow($employee, $date)->shift_id);
    }

    public function test_following_the_normal_schedule_deletes_the_override_and_is_audited(): void
    {
        $employee = $this->user('kasir');
        $date = $this->nextMonday->copy()->addDay();
        $override = ScheduleDayOverride::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $date, 'shift_id' => $this->evening->id]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => str_contains($body, 'jadwal normal')));

        $this->override($this->page($this->user('super_admin')), $employee, $date, ['shift_choice' => '__default'])->assertHasNoActionErrors();

        $this->assertNull($this->overrideRow($employee, $date));
        $this->assertTrue(Activity::where('log_name', 'schedule_day_override')->where('subject_id', $override->id)->where('event', 'deleted')->exists());
    }

    public function test_following_the_normal_schedule_when_nothing_is_overridden_sends_no_push(): void
    {
        $employee = $this->user('kasir');
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));

        $this->override($this->page($this->user('super_admin')), $employee, $this->nextMonday, ['shift_choice' => '__default'])->assertHasNoActionErrors();
    }

    public function test_invalid_targets_are_rejected_without_writing_or_notifying(): void
    {
        $admin = $this->user('super_admin');
        $good = $this->user('kasir');
        $foreigner = $this->user('kasir', $this->otherStore);
        $inactive = $this->user('kasir', null, ['is_active' => false]);
        $partner = $this->user('partner');
        $date = $this->nextMonday->copy()->addDay();
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));
        $page = $this->page($admin);

        foreach ([$foreigner, $inactive, $partner] as $bad) {
            $this->override($page, $bad, $date, ['shift_choice' => $this->morning->id]);
            $this->assertNull($this->overrideRow($bad, $date), "Karyawan tidak sah: {$bad->name}");
        }

        $this->override($page, $good, $this->nextMonday->copy()->addWeeks(2), ['shift_choice' => $this->morning->id]);
        $this->assertSame(0, ScheduleDayOverride::where('user_id', $good->id)->count(), 'Tanggal di luar minggu yang ditampilkan.');

        $page->callAction('overrideDay', ['shift_choice' => $this->morning->id], ['userId' => $good->id, 'date' => 'bukan-tanggal']);
        $this->assertSame(0, ScheduleDayOverride::where('user_id', $good->id)->count());
    }

    public function test_only_active_shifts_of_the_employees_own_store_can_be_used(): void
    {
        $employee = $this->user('kasir');
        $date = $this->nextMonday->copy()->addDay();
        $foreignShift = Shift::create(['store_id' => $this->otherStore->id, 'name' => 'Asing', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        $inactiveShift = Shift::create(['store_id' => $this->store->id, 'name' => 'Mati', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => false]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));
        $page = $this->page($this->user('super_admin'));

        foreach ([$foreignShift, $inactiveShift] as $badShift) {
            $this->override($page, $employee, $date, ['shift_choice' => $badShift->id]);
            $this->assertNull($this->overrideRow($employee, $date), "Shift tidak sah: {$badShift->name}");
        }
    }

    public function test_approved_leave_blocks_a_work_shift_but_still_allows_marking_the_day_off(): void
    {
        $employee = $this->user('kasir');
        $date = $this->nextMonday->copy()->addDay();
        LeaveRequest::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'type' => 'cuti', 'start_date' => $date, 'end_date' => $date, 'reason' => 'Liburan', 'status' => 'approved']);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $page = $this->page($this->user('super_admin'));

        $this->override($page, $employee, $date, ['shift_choice' => $this->morning->id]);
        $this->assertNull($this->overrideRow($employee, $date), 'Tidak boleh dipanggil masuk saat cuti disetujui.');

        $this->override($page, $employee, $date, ['shift_choice' => '__off']);
        $this->assertNotNull($this->overrideRow($employee, $date));
    }

    public function test_a_past_day_with_attendance_cannot_be_overridden_but_a_past_day_without_it_can_without_a_push(): void
    {
        $employee = $this->user('kasir');
        $pastWeek = now()->subWeeks(2)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $withAttendance = $pastWeek->copy()->addDay();
        $withoutAttendance = $pastWeek->copy()->addDays(2);
        Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => $withAttendance, 'entry_type' => 'clock', 'clock_in_at' => $withAttendance->copy()->setTime(9, 0)]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));
        $page = $this->page($this->user('super_admin'), $pastWeek);

        $this->override($page, $employee, $withAttendance, ['shift_choice' => '__off']);
        $this->assertNull($this->overrideRow($employee, $withAttendance), 'Telat historis tidak boleh berubah klasifikasi.');

        $this->override($page, $employee, $withoutAttendance, ['shift_choice' => '__off']);
        $this->assertNotNull($this->overrideRow($employee, $withoutAttendance));
    }

    public function test_a_store_manager_can_only_change_their_own_stores_employees(): void
    {
        $manager = $this->user('store_manager');
        $mine = $this->user('kasir');
        $theirs = $this->user('kasir', $this->otherStore);
        $date = $this->nextMonday->copy()->addDay();
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $page = $this->page($manager);

        $this->override($page, $mine, $date, ['shift_choice' => '__off']);
        $this->override($page, $theirs, $date, ['shift_choice' => '__off']);

        $this->assertNotNull($this->overrideRow($mine, $date));
        $this->assertNull($this->overrideRow($theirs, $date));
    }
}
