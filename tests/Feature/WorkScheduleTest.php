<?php

namespace Tests\Feature;

use App\Filament\Resources\WorkScheduleResource;
use App\Filament\Resources\WorkScheduleResource\Pages\CreateWorkSchedule;
use App\Filament\Resources\WorkScheduleResource\Pages\EditWorkSchedule;
use App\Filament\Resources\WorkScheduleResource\Pages\ListWorkSchedules;
use App\Filament\Resources\WorkScheduleResource\RelationManagers\AssignmentsRelationManager;
use App\Models\EmployeeScheduleAssignment;
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
 * Daftar Jadwal Kerja: template pola 7 hari (validasi server yang ketat),
 * penerapan massal ke karyawan (aturan toko/aktif/tanggal, riwayat tidak
 * dihapus), pemberitahuan karyawan terdampak, riwayat penugasan, isolasi
 * per toko, dan izin per aksi.
 */
class WorkScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private Shift $morning;
    private Shift $evening;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->morning = Shift::create(['store_id' => $this->store->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        $this->evening = Shift::create(['store_id' => $this->store->id, 'name' => 'Sore', 'start_time' => '14:00', 'end_time' => '22:00', 'is_active' => true]);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    /** @return array<int, array{day: string, shift_id: ?int}> */
    private function days(?Shift $shift = null, array $offDays = ['sun']): array
    {
        $shift ??= $this->morning;

        return collect(WorkSchedule::DAYS)->map(fn ($day) => ['day' => $day, 'shift_id' => in_array($day, $offDays, true) ? null : $shift->id])->all();
    }

    private function schedule(string $name = 'Reguler', array $overrides = []): WorkSchedule
    {
        return WorkSchedule::create(array_merge([
            'store_id' => $this->store->id, 'name' => $name, 'is_active' => true, 'days' => $this->days(),
        ], $overrides));
    }

    private function assign(WorkSchedule $schedule, User $user, array $overrides = []): EmployeeScheduleAssignment
    {
        return EmployeeScheduleAssignment::create(array_merge([
            'user_id' => $user->id, 'work_schedule_id' => $schedule->id, 'store_id' => $schedule->store_id, 'effective_from' => today()->subMonth(),
        ], $overrides));
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    // ------------------------------------------------------------- model: validasi pola

    public function test_validate_days_accepts_a_proper_seven_day_pattern(): void
    {
        $this->assertNull(WorkSchedule::validateDays($this->days(), $this->store->id));
        $this->assertNull(WorkSchedule::validateDays($this->days(null, WorkSchedule::DAYS), $this->store->id), 'Semua hari libur tetap pola yang sah.');
    }

    public function test_validate_days_rejects_a_wrong_length_duplicate_days_and_foreign_shifts(): void
    {
        $days = $this->days();

        $this->assertNotNull(WorkSchedule::validateDays('bukan array', $this->store->id));
        $this->assertNotNull(WorkSchedule::validateDays(array_slice($days, 0, 6), $this->store->id));
        $this->assertNotNull(WorkSchedule::validateDays([...$days, ['day' => 'mon', 'shift_id' => null]], $this->store->id));

        $duplicated = $days;
        $duplicated[6]['day'] = 'mon'; // 7 entri tapi Minggu hilang, Senin dobel
        $this->assertSame('Tiap hari (Senin-Minggu) harus muncul tepat satu kali.', WorkSchedule::validateDays($duplicated, $this->store->id));

        $foreignShift = Shift::create(['store_id' => $this->otherStore->id, 'name' => 'Asing', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        $this->assertNotNull(WorkSchedule::validateDays($this->days($foreignShift), $this->store->id));

        $days[0]['shift_id'] = 99999;
        $this->assertNotNull(WorkSchedule::validateDays($days, $this->store->id));
    }

    public function test_shift_id_for_reads_the_pattern_by_day_code(): void
    {
        $schedule = $this->schedule();

        $this->assertSame($this->morning->id, $schedule->shiftIdFor('wed'));
        $this->assertNull($schedule->shiftIdFor('sun'));
        $this->assertNull($schedule->shiftIdFor('xyz'));
    }

    public function test_active_assignees_exclude_expired_assignments(): void
    {
        $schedule = $this->schedule();
        $open = $this->user('kasir');
        $ending = $this->user('kasir');
        $expired = $this->user('kasir');
        $this->assign($schedule, $open);
        $this->assign($schedule, $ending, ['effective_to' => today()->addDays(3)]);
        $this->assign($schedule, $expired, ['effective_from' => today()->subMonths(3), 'effective_to' => today()->subMonth()]);

        $this->assertEqualsCanonicalizing([$open->id, $ending->id], $schedule->activeAssigneeIds());
    }

    public function test_a_schedule_with_assignment_history_cannot_be_deleted(): void
    {
        $used = $this->schedule('Dipakai');
        $this->assign($used, $this->user('kasir'), ['effective_from' => today()->subMonths(3), 'effective_to' => today()->subMonth()]);
        $unused = $this->schedule('Kosong');

        try {
            $used->delete();
            $this->fail('Jadwal dengan riwayat tidak boleh terhapus.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('riwayat penugasan', $e->getMessage());
        }

        $unused->delete();
        $this->assertNotNull(WorkSchedule::find($used->id));
        $this->assertNull(WorkSchedule::find($unused->id));
    }

    // ------------------------------------------------------------- model: penerapan massal

    public function test_assign_bulk_rejects_an_inactive_schedule_and_ineligible_employees(): void
    {
        $schedule = $this->schedule();
        $cases = [
            'jadwal nonaktif' => [$this->schedule('Mati', ['is_active' => false]), [$this->user('kasir')->id], today()],
            'karyawan toko lain' => [$schedule, [$this->user('kasir', $this->otherStore)->id], today()],
            'karyawan nonaktif' => [$schedule, [$this->user('kasir', null, ['is_active' => false])->id], today()],
            'partner' => [$schedule, [$this->user('partner')->id], today()],
            'tanggal terlalu lampau' => [$schedule, [$this->user('kasir')->id], today()->subDays(EmployeeScheduleAssignment::MAX_BACKDATE_DAYS + 1)],
        ];

        foreach ($cases as $label => [$target, $userIds, $date]) {
            try {
                EmployeeScheduleAssignment::assignBulk($target, $userIds, Carbon::instance($date), null);
                $this->fail("Seharusnya ditolak: {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage(), $label);
            }
        }

        $this->assertSame(0, EmployeeScheduleAssignment::count());
    }

    public function test_assign_bulk_is_all_or_nothing(): void
    {
        $schedule = $this->schedule();
        $valid = $this->user('kasir');
        $invalid = $this->user('kasir', $this->otherStore);

        try {
            EmployeeScheduleAssignment::assignBulk($schedule, [$valid->id, $invalid->id], today(), null);
            $this->fail('Satu karyawan tidak sah harus membatalkan seluruh penerapan.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, EmployeeScheduleAssignment::count(), 'Karyawan yang valid pun tidak ikut tersimpan.');
    }

    public function test_assigning_on_the_same_effective_date_replaces_instead_of_duplicating(): void
    {
        $first = $this->schedule('Satu');
        $second = $this->schedule('Dua');
        $employee = $this->user('kasir');

        EmployeeScheduleAssignment::assignBulk($first, [$employee->id], today(), null);
        EmployeeScheduleAssignment::assignBulk($second, [$employee->id], today(), null);

        $this->assertSame(1, EmployeeScheduleAssignment::where('user_id', $employee->id)->count());
        $this->assertSame($second->id, EmployeeScheduleAssignment::activeFor($employee->id)->work_schedule_id);
    }

    // ------------------------------------------------------------- Filament: daftar

    public function test_list_is_store_scoped_and_summarises_the_pattern(): void
    {
        $manager = $this->user('store_manager');
        $mine = $this->schedule('Reguler');
        $off = $this->schedule('Lama', ['is_active' => false]);
        $theirs = $this->schedule('Reguler', ['store_id' => $this->otherStore->id, 'days' => $this->days(Shift::create(['store_id' => $this->otherStore->id, 'name' => 'Pagi', 'start_time' => '09:00', 'end_time' => '18:00', 'is_active' => true]))]);
        $this->assign($mine, $this->user('kasir'));
        $this->assign($mine, $this->user('kasir'));

        $this->actingAs($manager, 'web');
        Livewire::test(ListWorkSchedules::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine, $off])->assertCanNotSeeTableRecords([$theirs])
            ->assertTableColumnStateSet('days', 'Sen: Pagi · Sel: Pagi · Rab: Pagi · Kam: Pagi · Jum: Pagi · Sab: Pagi · Min: Libur', $mine)
            ->assertTableColumnStateSet('active_assignments_count', 2, $mine)
            ->assertTableColumnStateSet('work_days', '6 / 7 hari', $mine)
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$mine]);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListWorkSchedules::class)->assertCanSeeTableRecords([$mine, $off, $theirs]);
    }

    // ------------------------------------------------------------- Filament: buat & ubah

    public function test_admin_creates_a_schedule_template(): void
    {
        $this->asAdmin();

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Reguler Senin-Sabtu', 'days' => $this->days(), 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();

        $schedule = WorkSchedule::where('name', 'Reguler Senin-Sabtu')->firstOrFail();
        $this->assertSame($this->morning->id, $schedule->shiftIdFor('mon'));
        $this->assertNull($schedule->shiftIdFor('sun'));
        $this->assertTrue(Activity::where('log_name', 'work_schedule')->where('subject_id', $schedule->id)->where('event', 'created')->exists());
    }

    public function test_a_store_manager_creates_templates_in_their_own_store(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm(['name' => 'Sore', 'days' => $this->days($this->evening)])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($this->store->id, WorkSchedule::where('name', 'Sore')->value('store_id'));
    }

    public function test_the_form_validates_name_uniqueness_and_the_pattern_on_the_server(): void
    {
        $this->asAdmin();
        $this->schedule('Reguler');
        $foreignShift = Shift::create(['store_id' => $this->otherStore->id, 'name' => 'Asing', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Reguler', 'days' => $this->days()])
            ->call('create')->assertHasFormErrors(['name' => 'unique']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => '', 'days' => $this->days()])
            ->call('create')->assertHasFormErrors(['name' => 'required']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Enam Hari', 'days' => array_slice($this->days(), 0, 6)])
            ->call('create')->assertHasFormErrors(['days']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Shift Asing', 'days' => $this->days($foreignShift)])
            ->call('create')->assertHasFormErrors(['days']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm(['store_id' => $this->otherStore->id, 'name' => 'Reguler', 'days' => $this->days($foreignShift)])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(2, WorkSchedule::count());
    }

    public function test_the_store_of_an_existing_template_cannot_be_changed(): void
    {
        $this->asAdmin();
        $schedule = $this->schedule();

        Livewire::test(EditWorkSchedule::class, ['record' => $schedule->getKey()])
            ->fillForm(['store_id' => $this->otherStore->id, 'name' => 'Reguler Baru'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame($this->store->id, $schedule->fresh()->store_id);
        $this->assertSame('Reguler Baru', $schedule->fresh()->name);
    }

    public function test_a_tampered_store_cannot_be_used_to_smuggle_foreign_shifts_into_the_pattern(): void
    {
        $this->asAdmin();
        $schedule = $this->schedule();
        $foreignShift = Shift::create(['store_id' => $this->otherStore->id, 'name' => 'Asing', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);

        Livewire::test(EditWorkSchedule::class, ['record' => $schedule->getKey()])
            ->fillForm(['store_id' => $this->otherStore->id, 'days' => $this->days($foreignShift)])
            ->call('save')->assertHasFormErrors(['days']);

        $this->assertSame($this->morning->id, $schedule->fresh()->shiftIdFor('mon'));
    }

    public function test_changing_the_pattern_of_a_used_template_notifies_the_assigned_employees(): void
    {
        $this->asAdmin();
        $schedule = $this->schedule();
        $employee = $this->user('kasir');
        $this->assign($schedule, $employee);
        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => $ids === [$employee->id] && $title === 'Jadwal Kerja Berubah');
        });

        Livewire::test(EditWorkSchedule::class, ['record' => $schedule->getKey()])
            ->fillForm(['days' => $this->days($this->evening)])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame($this->evening->id, $schedule->fresh()->shiftIdFor('mon'));
        $this->assertTrue(Activity::where('log_name', 'work_schedule')->where('subject_id', $schedule->id)->where('event', 'updated')->exists());
    }

    public function test_no_push_when_the_pattern_is_unchanged_or_nobody_uses_the_template(): void
    {
        $this->asAdmin();
        $used = $this->schedule('Dipakai');
        $this->assign($used, $this->user('kasir'));
        $unused = $this->schedule('Kosong');
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));

        Livewire::test(EditWorkSchedule::class, ['record' => $used->getKey()])->fillForm(['name' => 'Dipakai Utama'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditWorkSchedule::class, ['record' => $unused->getKey()])->fillForm(['days' => $this->days($this->evening)])->call('save')->assertHasNoFormErrors();
    }

    // ------------------------------------------------------------- Filament: terapkan ke karyawan

    public function test_apply_to_employees_creates_assignments_and_notifies_them(): void
    {
        $admin = $this->asAdmin();
        $schedule = $this->schedule();
        $a = $this->user('kasir');
        $b = $this->user('kasir');
        $this->mock(PushNotificationService::class, function ($mock) use ($a, $b) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => collect($ids)->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all()
                && $title === 'Jadwal Kerja Baru');
        });

        Livewire::test(ListWorkSchedules::class)
            ->callTableAction('assignToEmployees', $schedule, data: ['user_ids' => [$a->id, $b->id], 'effective_from' => today()->toDateString()])
            ->assertHasNoTableActionErrors();

        foreach ([$a, $b] as $employee) {
            $row = EmployeeScheduleAssignment::where('user_id', $employee->id)->firstOrFail();
            $this->assertSame($schedule->id, $row->work_schedule_id);
            $this->assertNull($row->effective_to);
            $this->assertSame($admin->id, $row->assigned_by);
        }
    }

    public function test_applying_to_an_ineligible_employee_creates_nothing_and_sends_no_push(): void
    {
        $this->asAdmin();
        $schedule = $this->schedule();
        $foreigner = $this->user('kasir', $this->otherStore);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));

        Livewire::test(ListWorkSchedules::class)
            ->callTableAction('assignToEmployees', $schedule, data: ['user_ids' => [$foreigner->id], 'effective_from' => today()->toDateString()]);

        $this->assertSame(0, EmployeeScheduleAssignment::count());
    }

    public function test_the_apply_action_is_hidden_for_inactive_templates_and_the_date_cannot_be_too_old(): void
    {
        $this->asAdmin();
        $active = $this->schedule();
        $inactive = $this->schedule('Mati', ['is_active' => false]);
        $employee = $this->user('kasir');

        Livewire::test(ListWorkSchedules::class)
            ->assertTableActionVisible('assignToEmployees', $active)
            ->assertTableActionHidden('assignToEmployees', $inactive)
            ->callTableAction('assignToEmployees', $active, data: [
                'user_ids' => [$employee->id], 'effective_from' => today()->subDays(EmployeeScheduleAssignment::MAX_BACKDATE_DAYS + 5)->toDateString(),
            ])
            ->assertHasTableActionErrors(['effective_from']);

        $this->assertSame(0, EmployeeScheduleAssignment::count());
    }

    public function test_reapplying_closes_the_previous_assignment_and_keeps_history(): void
    {
        $this->asAdmin();
        $old = $this->schedule('Lama');
        $new = $this->schedule('Baru', ['days' => $this->days($this->evening)]);
        $employee = $this->user('kasir');
        $this->assign($old, $employee, ['effective_from' => today()->subMonth()]);

        Livewire::test(ListWorkSchedules::class)
            ->callTableAction('assignToEmployees', $new, data: ['user_ids' => [$employee->id], 'effective_from' => today()->toDateString()])
            ->assertHasNoTableActionErrors();

        $rows = EmployeeScheduleAssignment::where('user_id', $employee->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(today()->subDay()->toDateString(), $rows[0]->effective_to->toDateString());
        $this->assertNull($rows[1]->effective_to);
        $this->assertSame($new->id, EmployeeScheduleAssignment::activeFor($employee->id)->work_schedule_id);
    }

    // ------------------------------------------------------------- Filament: hapus & riwayat

    public function test_delete_is_hidden_when_assignment_history_exists_and_bulk_delete_skips_it(): void
    {
        $this->asAdmin();
        $used = $this->schedule('Dipakai');
        $this->assign($used, $this->user('kasir'));
        $unused = $this->schedule('Kosong');
        $other = $this->schedule('Kosong Lain');

        $this->assertFalse((bool) WorkScheduleResource::canDelete($used));
        $this->assertTrue((bool) WorkScheduleResource::canDelete($unused));

        Livewire::test(ListWorkSchedules::class)
            ->assertTableActionHidden('delete', $used)
            ->callTableAction('delete', $unused)
            ->callTableBulkAction('deleteUnused', [$used, $other]);

        $this->assertNotNull(WorkSchedule::find($used->id));
        $this->assertNull(WorkSchedule::find($unused->id));
        $this->assertNull(WorkSchedule::find($other->id));
    }

    public function test_assignments_relation_manager_shows_status_per_row(): void
    {
        $this->asAdmin();
        $schedule = $this->schedule();
        $active = $this->assign($schedule, $this->user('kasir'));
        $upcoming = $this->assign($schedule, $this->user('kasir'), ['effective_from' => today()->addDays(5)]);
        $ended = $this->assign($schedule, $this->user('kasir'), ['effective_from' => today()->subMonths(3), 'effective_to' => today()->subMonth()]);

        Livewire::test(AssignmentsRelationManager::class, ['ownerRecord' => $schedule, 'pageClass' => EditWorkSchedule::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$active, $upcoming, $ended])
            ->assertTableColumnStateSet('status', 'Aktif', $active)
            ->assertTableColumnStateSet('status', 'Akan mulai', $upcoming)
            ->assertTableColumnStateSet('status', 'Berakhir', $ended);
    }

    // ------------------------------------------------------------- izin

    public function test_permissions_follow_menu_access_and_module_actions(): void
    {
        $schedule = $this->schedule();
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]);

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(WorkScheduleResource::canViewAny());
        $this->assertTrue(WorkScheduleResource::canCreate());
        $this->assertTrue(WorkScheduleResource::canEdit($schedule));
        $this->assertTrue((bool) WorkScheduleResource::canDelete($schedule));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(WorkScheduleResource::canViewAny());
        $this->assertFalse(WorkScheduleResource::canCreate());
        $this->assertFalse(WorkScheduleResource::canEdit($schedule));
        $this->assertFalse((bool) WorkScheduleResource::canDelete($schedule));
    }
}
