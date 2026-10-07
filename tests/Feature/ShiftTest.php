<?php

namespace Tests\Feature;

use App\Filament\Resources\ShiftResource;
use App\Filament\Resources\ShiftResource\Pages\CreateShift;
use App\Filament\Resources\ShiftResource\Pages\EditShift;
use App\Filament\Resources\ShiftResource\Pages\ListShifts;
use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
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
 * Daftar Shift: perhitungan jam kerja bersih (istirahat, lintas tengah
 * malam), pemakaian di Jadwal Kerja/override, shift yang masih dipakai tidak
 * bisa dihapus (pindahkan dulu), pemberitahuan ke karyawan terdampak saat jam
 * berubah, validasi form, isolasi per toko, dan izin per aksi.
 */
class ShiftTest extends TestCase
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

    private function shift(string $name, array $overrides = [], ?Store $store = null): Shift
    {
        return Shift::create(array_merge([
            'store_id' => ($store ?? $this->store)->id, 'name' => $name, 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true,
        ], $overrides));
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function schedule(Shift $shift, string $name = 'Jadwal Reguler', ?Store $store = null): WorkSchedule
    {
        return WorkSchedule::create([
            'store_id' => ($store ?? $this->store)->id, 'name' => $name, 'is_active' => true,
            'days' => collect(WorkSchedule::DAYS)->map(fn ($day) => ['day' => $day, 'shift_id' => $day === 'sun' ? null : $shift->id])->toArray(),
        ]);
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    // ------------------------------------------------------------- model

    public function test_net_minutes_handles_breaks_and_midnight_crossing(): void
    {
        $this->assertSame(540, $this->shift('Reguler')->netMinutes());
        $this->assertSame(480, $this->shift('Dengan Istirahat', ['break_start_time' => '12:00', 'break_end_time' => '13:00'])->netMinutes());
        $this->assertSame(480, $this->shift('Malam', ['start_time' => '22:00', 'end_time' => '06:00'])->netMinutes());
        $this->assertSame(420, $this->shift('Malam Istirahat', ['start_time' => '22:00', 'end_time' => '06:00', 'break_start_time' => '01:00', 'break_end_time' => '02:00'])->netMinutes());
    }

    public function test_usage_summary_counts_schedules_and_overrides(): void
    {
        $shift = $this->shift('Pagi');
        $unused = $this->shift('Tak Dipakai');
        $this->schedule($shift, 'A');
        $this->schedule($shift, 'B');
        $employee = $this->user('kasir');
        ScheduleDayOverride::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => '2026-10-10', 'shift_id' => $shift->id]);

        $this->assertSame(['schedules' => 2, 'overrides' => 1], $shift->usageSummary());
        $this->assertTrue($shift->isInUse());
        $this->assertSame(['schedules' => 0, 'overrides' => 0], $unused->usageSummary());
        $this->assertFalse($unused->isInUse());
    }

    public function test_a_shift_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = $this->shift('Pagi');
        $this->schedule($used);
        $unused = $this->shift('Tak Dipakai');

        try {
            $used->delete();
            $this->fail('Shift yang dipakai tidak boleh terhapus.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('masih dipakai', $e->getMessage());
        }

        $unused->delete();
        $this->assertNotNull(Shift::find($used->id));
        $this->assertNull(Shift::find($unused->id));
    }

    public function test_replace_usage_moves_schedules_and_overrides_to_another_shift_of_the_same_store(): void
    {
        $old = $this->shift('Lama');
        $new = $this->shift('Baru', ['start_time' => '09:00']);
        $schedule = $this->schedule($old);
        $employee = $this->user('kasir');
        $override = ScheduleDayOverride::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => '2026-10-10', 'shift_id' => $old->id]);

        $old->replaceUsageWith($new);

        $this->assertSame($new->id, $schedule->fresh()->shiftIdFor('mon'));
        $this->assertNull($schedule->fresh()->shiftIdFor('sun'), 'Hari libur tetap libur.');
        $this->assertSame($new->id, $override->fresh()->shift_id);
        $this->assertFalse($old->fresh()->isInUse());

        $old->delete();
        $this->assertNull(Shift::find($old->id));
    }

    public function test_replacement_must_be_a_different_shift_in_the_same_store(): void
    {
        $shift = $this->shift('Pagi');
        $foreign = $this->shift('Pagi', [], $this->otherStore);
        $this->schedule($shift);

        foreach ([$shift, $foreign] as $invalid) {
            try {
                $shift->replaceUsageWith($invalid);
                $this->fail('Pengganti tidak valid seharusnya ditolak.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('toko yang sama', $e->getMessage());
            }
        }

        $this->assertTrue($shift->fresh()->isInUse());
    }

    public function test_affected_users_are_current_assignments_and_upcoming_overrides_only(): void
    {
        $shift = $this->shift('Pagi');
        $schedule = $this->schedule($shift);
        $current = $this->user('kasir');
        $expired = $this->user('kasir');
        $futureOverride = $this->user('kasir');
        $pastOverride = $this->user('kasir');
        $unrelated = $this->user('kasir');

        EmployeeScheduleAssignment::create(['user_id' => $current->id, 'work_schedule_id' => $schedule->id, 'store_id' => $this->store->id, 'effective_from' => today()->subMonth()]);
        EmployeeScheduleAssignment::create(['user_id' => $expired->id, 'work_schedule_id' => $schedule->id, 'store_id' => $this->store->id, 'effective_from' => today()->subMonths(3), 'effective_to' => today()->subMonth()]);
        ScheduleDayOverride::create(['user_id' => $futureOverride->id, 'store_id' => $this->store->id, 'date' => today()->addDays(3), 'shift_id' => $shift->id]);
        ScheduleDayOverride::create(['user_id' => $pastOverride->id, 'store_id' => $this->store->id, 'date' => today()->subDays(3), 'shift_id' => $shift->id]);

        $ids = $shift->affectedUserIds();

        $this->assertEqualsCanonicalizing([$current->id, $futureOverride->id], $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_an_inactive_shift_still_applies_to_schedules_that_already_use_it(): void
    {
        $shift = $this->shift('Pagi');
        $schedule = $this->schedule($shift);
        $employee = $this->user('kasir');
        EmployeeScheduleAssignment::create(['user_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'store_id' => $this->store->id, 'effective_from' => today()->subMonth()]);

        $shift->update(['is_active' => false]);

        $this->assertSame($shift->id, Attendance::resolveShiftFor($employee->id, Carbon::parse('2026-10-07'))?->id);
    }

    // ------------------------------------------------------------- Filament: daftar

    public function test_list_is_scoped_to_the_store_and_shows_usage_and_filters(): void
    {
        $manager = $this->user('store_manager');
        $mine = $this->shift('Pagi');
        $off = $this->shift('Lama', ['is_active' => false]);
        $theirs = $this->shift('Pagi', [], $this->otherStore);
        $this->schedule($mine);

        $this->actingAs($manager, 'web');
        Livewire::test(ListShifts::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine, $off])->assertCanNotSeeTableRecords([$theirs])
            ->assertTableColumnStateSet('usage', '1 jadwal, 0 override', $mine)
            ->assertTableColumnStateSet('usage', 'Belum dipakai', $off)
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$mine]);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListShifts::class)->assertCanSeeTableRecords([$mine, $off, $theirs]);
    }

    // ------------------------------------------------------------- Filament: buat

    public function test_admin_creates_a_shift_with_break_and_colour(): void
    {
        $this->asAdmin();

        Livewire::test(CreateShift::class)
            ->fillForm([
                'store_id' => $this->store->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00',
                'break_start_time' => '12:00', 'break_end_time' => '13:00', 'color' => '#22c55e', 'is_active' => true,
            ])
            ->call('create')->assertHasNoFormErrors();

        $shift = Shift::where('name', 'Pagi')->firstOrFail();
        $this->assertSame('08:00', substr($shift->start_time, 0, 5));
        $this->assertSame('13:00', substr($shift->break_end_time, 0, 5));
        $this->assertSame(480, $shift->netMinutes());
        $this->assertTrue(Activity::where('log_name', 'shift')->where('subject_id', $shift->id)->where('event', 'created')->exists());
    }

    public function test_a_store_manager_creates_shifts_in_their_own_store(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');

        Livewire::test(CreateShift::class)
            ->fillForm(['name' => 'Sore', 'start_time' => '14:00', 'end_time' => '22:00'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($this->store->id, Shift::where('name', 'Sore')->value('store_id'));
    }

    public function test_name_is_unique_per_store_and_required(): void
    {
        $this->asAdmin();
        $this->shift('Pagi');

        Livewire::test(CreateShift::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00'])
            ->call('create')->assertHasFormErrors(['name' => 'unique']);

        Livewire::test(CreateShift::class)
            ->fillForm(['store_id' => $this->otherStore->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00'])
            ->call('create')->assertHasNoFormErrors();

        Livewire::test(CreateShift::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => '', 'start_time' => null, 'end_time' => null])
            ->call('create')->assertHasFormErrors(['name' => 'required', 'start_time' => 'required', 'end_time' => 'required']);
    }

    public function test_end_time_cannot_equal_start_time(): void
    {
        $this->asAdmin();

        Livewire::test(CreateShift::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Aneh', 'start_time' => '08:00', 'end_time' => '08:00'])
            ->call('create')->assertHasFormErrors(['end_time']);

        $this->assertSame(0, Shift::count());
    }

    public function test_overnight_shifts_are_accepted_with_a_break_inside_them(): void
    {
        $this->asAdmin();

        Livewire::test(CreateShift::class)
            ->fillForm(['store_id' => $this->store->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00', 'break_start_time' => '01:00', 'break_end_time' => '02:00'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(420, Shift::where('name', 'Malam')->firstOrFail()->netMinutes());
    }

    public function test_break_must_be_complete_inside_the_shift_and_end_after_it_starts(): void
    {
        $this->asAdmin();
        $base = ['store_id' => $this->store->id, 'name' => 'Uji', 'start_time' => '08:00', 'end_time' => '17:00'];

        Livewire::test(CreateShift::class)->fillForm([...$base, 'break_start_time' => '12:00'])
            ->call('create')->assertHasFormErrors(['break_end_time']);
        Livewire::test(CreateShift::class)->fillForm([...$base, 'break_end_time' => '13:00'])
            ->call('create')->assertHasFormErrors(['break_start_time']);
        Livewire::test(CreateShift::class)->fillForm([...$base, 'break_start_time' => '13:00', 'break_end_time' => '12:00'])
            ->call('create')->assertHasFormErrors(['break_end_time']);
        Livewire::test(CreateShift::class)->fillForm([...$base, 'break_start_time' => '17:30', 'break_end_time' => '18:00'])
            ->call('create')->assertHasFormErrors(['break_end_time']);

        $this->assertSame(0, Shift::count());
    }

    // ------------------------------------------------------------- Filament: ubah

    public function test_editing_keeps_the_store_locked_and_is_audited(): void
    {
        $this->asAdmin();
        $shift = $this->shift('Pagi');

        Livewire::test(EditShift::class, ['record' => $shift->getKey()])
            ->fillForm(['store_id' => $this->otherStore->id, 'name' => 'Pagi Baru'])
            ->call('save')->assertHasNoFormErrors();

        $fresh = $shift->fresh();
        $this->assertSame($this->store->id, $fresh->store_id, 'Toko tidak bisa dipindah setelah dibuat.');
        $this->assertSame('Pagi Baru', $fresh->name);
        $this->assertTrue(Activity::where('log_name', 'shift')->where('subject_id', $shift->id)->where('event', 'updated')->exists());
    }

    public function test_changing_hours_of_a_used_shift_pushes_a_notice_to_the_affected_employees(): void
    {
        $this->asAdmin();
        $shift = $this->shift('Pagi');
        $schedule = $this->schedule($shift);
        $employee = $this->user('kasir');
        EmployeeScheduleAssignment::create(['user_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'store_id' => $this->store->id, 'effective_from' => today()->subMonth()]);

        $this->mock(PushNotificationService::class, function ($mock) use ($employee) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$employee->id]
                && $title === 'Jam Shift Berubah' && str_contains($body, '09:00 - 17:00'));
        });

        Livewire::test(EditShift::class, ['record' => $shift->getKey()])
            ->fillForm(['start_time' => '09:00'])
            ->call('save')->assertHasNoFormErrors();
    }

    public function test_no_push_when_the_shift_is_unused_or_the_hours_did_not_change(): void
    {
        $this->asAdmin();
        $unused = $this->shift('Tak Dipakai');
        $used = $this->shift('Pagi');
        $this->schedule($used);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));

        Livewire::test(EditShift::class, ['record' => $unused->getKey()])->fillForm(['start_time' => '09:30'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditShift::class, ['record' => $used->getKey()])->fillForm(['name' => 'Pagi Utama', 'color' => '#ef4444'])->call('save')->assertHasNoFormErrors();
    }

    public function test_changing_a_used_shifts_hours_does_not_rewrite_recorded_lateness(): void
    {
        $this->asAdmin();
        $shift = $this->shift('Pagi');
        $this->schedule($shift);
        $employee = $this->user('kasir');
        $row = Attendance::create(['user_id' => $employee->id, 'store_id' => $this->store->id, 'date' => '2026-10-01', 'entry_type' => 'clock', 'clock_in_at' => '2026-10-01 08:40:00', 'late_minutes' => 25]);

        Livewire::test(EditShift::class, ['record' => $shift->getKey()])->fillForm(['start_time' => '10:00'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(25, $row->fresh()->late_minutes, 'Telat yang sudah tercatat (dasar potongan gaji) tidak berubah.');
    }

    // ------------------------------------------------------------- Filament: hapus & pindahkan

    public function test_delete_is_hidden_for_a_used_shift_and_works_for_an_unused_one(): void
    {
        $this->asAdmin();
        $used = $this->shift('Pagi');
        $this->schedule($used);
        $unused = $this->shift('Tak Dipakai');

        $this->assertFalse((bool) ShiftResource::canDelete($used));
        $this->assertTrue((bool) ShiftResource::canDelete($unused));

        Livewire::test(ListShifts::class)
            ->assertTableActionHidden('delete', $used)
            ->callTableAction('delete', $unused);

        $this->assertNull(Shift::find($unused->id));
        $this->assertNotNull(Shift::find($used->id));
    }

    public function test_bulk_delete_skips_shifts_that_are_in_use(): void
    {
        $this->asAdmin();
        $used = $this->shift('Pagi');
        $this->schedule($used);
        $unused = $this->shift('Tak Dipakai');

        Livewire::test(ListShifts::class)->callTableBulkAction('deleteUnused', [$used, $unused]);

        $this->assertNotNull(Shift::find($used->id));
        $this->assertNull(Shift::find($unused->id));
    }

    public function test_move_usage_action_only_offers_active_shifts_of_the_same_store_and_frees_the_old_one(): void
    {
        $this->asAdmin();
        $old = $this->shift('Lama');
        $this->schedule($old);
        $target = $this->shift('Baru');
        $inactive = $this->shift('Nonaktif', ['is_active' => false]);
        $foreign = $this->shift('Asing', [], $this->otherStore);
        $unused = $this->shift('Tak Dipakai');

        Livewire::test(ListShifts::class)
            ->assertTableActionVisible('replaceUsage', $old)
            ->assertTableActionHidden('replaceUsage', $unused)
            ->callTableAction('replaceUsage', $old, data: ['target_id' => $target->id])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($old->fresh()->isInUse());
        $this->assertTrue($target->fresh()->isInUse());
        $this->assertNotNull($inactive);
        $this->assertNotNull($foreign);

        Livewire::test(ListShifts::class)->assertTableActionHidden('replaceUsage', $old->fresh());
    }

    // ------------------------------------------------------------- izin

    public function test_permissions_follow_menu_access_and_module_actions(): void
    {
        $shift = $this->shift('Pagi');
        $withMenu = $this->user('kasir');
        $noMenu = $this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]);

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(ShiftResource::canViewAny());
        $this->assertTrue(ShiftResource::canCreate());
        $this->assertTrue(ShiftResource::canEdit($shift));
        $this->assertTrue((bool) ShiftResource::canDelete($shift));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(ShiftResource::canViewAny());
        $this->assertFalse(ShiftResource::canCreate());
        $this->assertFalse(ShiftResource::canEdit($shift));
        $this->assertFalse((bool) ShiftResource::canDelete($shift));
    }
}
