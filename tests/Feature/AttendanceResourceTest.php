<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\AttendanceResource\Pages\CreateAttendance;
use App\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Absensi Karyawan di Filament: daftar & filter (telat, di luar radius),
 * isolasi per toko, "Tandai Ditinjau", entri manual oleh atasan (aturan
 * jam, gabung dengan baris yang sudah ada), aturan hapus, dan pembagian
 * wewenang: atasan langsung ubah, staf biasa lewat "Ajukan Koreksi".
 */
class AttendanceResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private string $yesterday;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true, 'attendance_radius_meters' => 100]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->yesterday = now()->subDay()->toDateString();
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function row(User $user, array $overrides = []): Attendance
    {
        return Attendance::create(array_merge([
            'user_id' => $user->id, 'store_id' => $user->store_id, 'date' => $this->yesterday, 'entry_type' => 'clock',
            'clock_in_at' => "{$this->yesterday} 09:00:00", 'late_minutes' => 0,
        ], $overrides));
    }

    // ------------------------------------------------------------- daftar

    public function test_store_manager_sees_only_their_own_store_while_admins_see_everything(): void
    {
        $manager = $this->user('store_manager');
        $mine = $this->row($this->user('kasir'));
        $theirs = $this->row($this->user('kasir', $this->otherStore));

        $this->actingAs($manager, 'web');
        Livewire::test(ListAttendances::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

        $this->actingAs($this->user('super_admin'), 'web');
        Livewire::test(ListAttendances::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_filters_for_lateness_radius_and_entry_type(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $onTime = $this->row($this->user('kasir'));
        $late = $this->row($this->user('kasir'), ['late_minutes' => 20]);
        $far = $this->row($this->user('kasir'), ['clock_in_distance_meters' => 500]);
        $near = $this->row($this->user('kasir'), ['clock_in_distance_meters' => 30]);
        $alpha = $this->row($this->user('kasir'), ['entry_type' => 'alpha', 'clock_in_at' => null]);

        Livewire::test(ListAttendances::class)->filterTable('is_late')
            ->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$onTime, $far, $alpha]);

        Livewire::test(ListAttendances::class)->filterTable('outside_radius')
            ->assertCanSeeTableRecords([$far])->assertCanNotSeeTableRecords([$near, $onTime, $late]);

        Livewire::test(ListAttendances::class)->filterTable('entry_type', 'alpha')
            ->assertCanSeeTableRecords([$alpha])->assertCanNotSeeTableRecords([$onTime, $late]);

        Livewire::test(ListAttendances::class)->filterTable('needs_review')
            ->assertCanSeeTableRecords([$late, $far, $alpha])->assertCanNotSeeTableRecords([$onTime, $near]);
    }

    public function test_mark_reviewed_is_offered_only_for_anomalies_and_clears_them_from_the_review_filter(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $late = $this->row($this->user('kasir'), ['late_minutes' => 20]);
        $clean = $this->row($this->user('kasir'));

        Livewire::test(ListAttendances::class)
            ->assertTableActionVisible('review', $late)
            ->assertTableActionHidden('review', $clean)
            ->callTableAction('review', $late);

        $fresh = $late->fresh();
        $this->assertTrue($fresh->isAcknowledged());
        $this->assertSame($admin->id, $fresh->reviewed_by);

        Livewire::test(ListAttendances::class)
            ->assertTableActionHidden('review', $fresh)
            ->filterTable('needs_review')
            ->assertCanNotSeeTableRecords([$fresh]);
    }

    // ------------------------------------------------------------- entri manual

    private function manualForm(User $employee, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $employee->id, 'store_id' => $employee->store_id, 'date' => $this->yesterday, 'entry_type' => 'manual',
            'clock_in_at' => "{$this->yesterday} 09:00:00", 'clock_out_at' => "{$this->yesterday} 18:00:00", 'note' => 'Device absen toko mati',
        ], $overrides);
    }

    public function test_admin_records_a_manual_entry_with_audit_fields(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $employee = $this->user('kasir');

        Livewire::test(CreateAttendance::class)->fillForm($this->manualForm($employee))->call('create')->assertHasNoFormErrors();

        $row = Attendance::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('manual', $row->entry_type);
        $this->assertSame($admin->id, $row->recorded_by);
        $this->assertSame('Device absen toko mati', $row->note);
        $this->assertSame($this->store->id, $row->store_id);
    }

    public function test_field_duty_entries_are_supported(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');

        Livewire::test(CreateAttendance::class)
            ->fillForm($this->manualForm($employee, ['entry_type' => 'field_duty', 'note' => 'Ambil kendaraan client']))
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('field_duty', Attendance::where('user_id', $employee->id)->value('entry_type'));
    }

    public function test_a_store_manager_entry_is_forced_into_their_own_store(): void
    {
        $manager = $this->user('store_manager');
        $this->actingAs($manager, 'web');
        $employee = $this->user('kasir');

        Livewire::test(CreateAttendance::class)
            ->fillForm($this->manualForm($employee, ['store_id' => $this->otherStore->id]))
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($this->store->id, Attendance::where('user_id', $employee->id)->value('store_id'));
    }

    public function test_manual_entry_validation(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $today = now()->toDateString();
        $create = fn (array $over) => Livewire::test(CreateAttendance::class)->fillForm($this->manualForm($employee, $over))->call('create');

        $create(['note' => ''])->assertHasFormErrors(['note' => 'required']);
        $create(['date' => now()->addDays(2)->toDateString(), 'clock_in_at' => null, 'clock_out_at' => null])->assertHasFormErrors(['date']);
        $create(['clock_in_at' => "{$today} 09:00:00"])->assertHasFormErrors(['clock_in_at']);
        $create(['clock_out_at' => "{$this->yesterday} 08:00:00"])->assertHasFormErrors(['clock_out_at']);
        $create(['clock_out_at' => "{$this->yesterday} 09:00:00"])->assertHasFormErrors(['clock_out_at']);

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_manual_entry_completes_the_existing_row_of_that_day_instead_of_duplicating_it(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $employee = $this->user('kasir');
        $this->row($employee, ['clock_out_at' => null]);

        Livewire::test(CreateAttendance::class)
            ->fillForm($this->manualForm($employee, ['note' => 'Lupa absen pulang']))
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(1, Attendance::where('user_id', $employee->id)->count());
        $this->assertNotNull(Attendance::where('user_id', $employee->id)->value('clock_out_at'));
    }

    // ------------------------------------------------------------- hapus

    public function test_real_clock_rows_can_never_be_deleted_but_manual_rows_can_by_full_access_only(): void
    {
        $admin = $this->user('super_admin');
        $employee = $this->user('kasir');
        $clock = $this->row($employee);
        $manual = $this->row($employee, ['date' => now()->subDays(2)->toDateString(), 'entry_type' => 'manual']);

        $this->actingAs($admin, 'web');
        $this->assertFalse((bool) AttendanceResource::canDelete($clock));
        $this->assertTrue((bool) AttendanceResource::canDelete($manual));

        Livewire::test(ListAttendances::class)
            ->assertTableActionHidden('delete', $clock)
            ->assertTableActionVisible('delete', $manual)
            ->callTableAction('delete', $manual);

        $this->assertNull(Attendance::find($manual->id));
        $this->assertNotNull(Attendance::find($clock->id));

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertFalse((bool) AttendanceResource::canDelete($this->row($employee, ['date' => now()->subDays(3)->toDateString(), 'entry_type' => 'manual'])));
    }

    // ------------------------------------------------------------- wewenang

    public function test_store_managers_edit_directly_but_regular_staff_must_request_a_correction(): void
    {
        $record = $this->row($this->user('kasir'));

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertTrue(AttendanceResource::canCreate());
        $this->assertTrue(AttendanceResource::canEdit($record));

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(AttendanceResource::canViewAny());
        $this->assertFalse(AttendanceResource::canCreate());
        $this->assertFalse(AttendanceResource::canEdit($record));

        $granted = $this->user('kasir', null, ['menu_permissions' => [AttendanceResource::class => ['create', 'update']]]);
        $this->actingAs($granted, 'web');
        $this->assertTrue(AttendanceResource::canCreate(), 'Izin eksplisit lewat Hak Akses Detail.');
        $this->assertTrue(AttendanceResource::canEdit($record));

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(AttendanceResource::canViewAny());
        $this->assertFalse(AttendanceResource::canCreate());
    }

    public function test_regular_staff_submit_a_correction_request_that_stays_pending(): void
    {
        $staff = $this->user('kasir');
        $record = $this->row($staff, ['clock_out_at' => null]);
        $this->actingAs($staff, 'web');

        Livewire::test(ListAttendances::class)
            ->assertTableActionVisible('requestCorrectionExisting', $record)
            ->callTableAction('requestCorrectionExisting', $record, data: [
                'clock_in_at' => "{$this->yesterday} 09:00:00", 'clock_out_at' => "{$this->yesterday} 18:00:00", 'reason' => 'Lupa absen pulang',
            ])
            ->assertHasNoTableActionErrors();

        $request = AttendanceCorrectionRequest::where('user_id', $staff->id)->firstOrFail();
        $this->assertSame(AttendanceCorrectionRequest::STATUS_PENDING, $request->status);
        $this->assertNull($record->fresh()->clock_out_at, 'Data absensi baru berubah setelah disetujui.');
    }

    public function test_correction_action_is_hidden_for_managers_and_admins(): void
    {
        $record = $this->row($this->user('kasir'));

        foreach (['store_manager', 'super_admin'] as $role) {
            $this->actingAs($this->user($role), 'web');
            Livewire::test(ListAttendances::class)->assertTableActionHidden('requestCorrectionExisting', $record);
        }
    }
}
