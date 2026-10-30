<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeTypeResource;
use App\Filament\Resources\EmployeeTypeResource\Pages\CreateEmployeeType;
use App\Filament\Resources\EmployeeTypeResource\Pages\EditEmployeeType;
use App\Filament\Resources\EmployeeTypeResource\Pages\ListEmployeeTypes;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\EmployeeType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tipe Karyawan: master tipe kepegawaian (nama unik, "memiliki tanggal
 * berakhir", aktif), perubahan flag yang berdampak diberitahukan ke admin
 * lain, tipe yang dipakai tidak bisa dihapus, tipe nonaktif tidak
 * ditawarkan lagi di form User, dan nonaktivasi otomatis kontrak habis.
 */
class EmployeeTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function type(string $name, bool $hasEndDate = false, bool $active = true): EmployeeType
    {
        return EmployeeType::create(['name' => $name, 'has_end_date' => $hasEndDate, 'is_active' => $active]);
    }

    private function user(string $role, array $extra = []): User
    {
        $user = User::create(array_merge(['name' => ucfirst($role) . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin', ['name' => 'Admin Utama']);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    // ------------------------------------------------------------- Filament

    public function test_list_renders_with_employee_counts_and_active_filter(): void
    {
        $this->asAdmin();
        $permanent = $this->type('Tetap');
        $contract = $this->type('Kontrak (PKWT)', true);
        $retired = $this->type('Lama', false, false);
        $this->user('kasir', ['employee_type_id' => $permanent->id]);
        $this->user('kasir', ['employee_type_id' => $permanent->id]);

        Livewire::test(ListEmployeeTypes::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$permanent, $contract, $retired])
            ->assertTableColumnStateSet('users_count', 2, $permanent->loadCount('users'))
            ->assertTableColumnStateSet('users_count', 0, $contract->loadCount('users'))
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$retired])->assertCanNotSeeTableRecords([$permanent, $contract]);
    }

    public function test_admin_creates_a_type_with_defaults(): void
    {
        $this->asAdmin();

        Livewire::test(CreateEmployeeType::class)
            ->fillForm(['name' => 'Freelance Musiman'])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = EmployeeType::where('name', 'Freelance Musiman')->firstOrFail();
        $this->assertFalse($type->has_end_date);
        $this->assertTrue($type->is_active);
        $this->assertTrue(Activity::where('log_name', 'employee_type')->where('subject_id', $type->id)->where('event', 'created')->exists());
    }

    public function test_name_is_required_unique_and_limited_to_50_characters(): void
    {
        $this->asAdmin();
        $this->type('Tetap');

        Livewire::test(CreateEmployeeType::class)->fillForm(['name' => ''])->call('create')
            ->assertHasFormErrors(['name' => 'required']);
        Livewire::test(CreateEmployeeType::class)->fillForm(['name' => 'Tetap'])->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
        Livewire::test(CreateEmployeeType::class)->fillForm(['name' => str_repeat('a', 51)])->call('create')
            ->assertHasFormErrors(['name' => 'max']);

        $this->assertSame(1, EmployeeType::count());
    }

    public function test_renaming_keeps_its_own_name_valid_and_is_audited(): void
    {
        $this->asAdmin();
        $type = $this->type('Tetap');
        $this->type('Kontrak', true);

        Livewire::test(EditEmployeeType::class, ['record' => $type->getKey()])
            ->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditEmployeeType::class, ['record' => $type->getKey()])
            ->fillForm(['name' => 'Kontrak'])->call('save')->assertHasFormErrors(['name' => 'unique']);
        Livewire::test(EditEmployeeType::class, ['record' => $type->getKey()])
            ->fillForm(['name' => 'Karyawan Tetap'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('Karyawan Tetap', $type->fresh()->name);
        $this->assertFalse($type->fresh()->is_active);
        $this->assertGreaterThanOrEqual(2, Activity::where('log_name', 'employee_type')->where('subject_id', $type->id)->where('event', 'updated')->count());
    }

    public function test_flipping_has_end_date_on_a_used_type_warns_other_admins_only(): void
    {
        $admin = $this->asAdmin();
        $otherAdmin = $this->user('direksi');
        $inactiveAdmin = $this->user('direksi', ['is_active' => false]);
        $type = $this->type('Kontrak', true);
        $this->user('kasir', ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonth()]);
        $this->user('kasir', ['employee_type_id' => $type->id, 'contract_end_date' => today()->addMonth()]);

        Livewire::test(EditEmployeeType::class, ['record' => $type->getKey()])
            ->fillForm(['has_end_date' => false])->call('save')->assertHasNoFormErrors();

        $this->assertSame(1, $otherAdmin->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count(), 'Pelaku sudah dapat peringatan langsung di layar.');
        $this->assertSame(0, $inactiveAdmin->notifications()->count());
        $this->assertStringContainsString('2 karyawan', $otherAdmin->notifications()->first()->data['body']);
    }

    public function test_changing_other_fields_or_an_unused_type_does_not_notify_anyone(): void
    {
        $this->asAdmin();
        $otherAdmin = $this->user('direksi');
        $used = $this->type('Tetap');
        $this->user('kasir', ['employee_type_id' => $used->id]);
        $unused = $this->type('Magang');

        Livewire::test(EditEmployeeType::class, ['record' => $used->getKey()])
            ->fillForm(['name' => 'Tetap Penuh'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditEmployeeType::class, ['record' => $unused->getKey()])
            ->fillForm(['has_end_date' => true])->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, $otherAdmin->notifications()->count());
    }

    public function test_a_type_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $this->asAdmin();
        $used = $this->type('Tetap');
        $this->user('kasir', ['employee_type_id' => $used->id]);
        $unused = $this->type('Magang');

        $this->assertFalse((bool) EmployeeTypeResource::canDelete($used));
        $this->assertTrue((bool) EmployeeTypeResource::canDelete($unused));

        Livewire::test(ListEmployeeTypes::class)
            ->assertTableActionHidden('delete', $used)
            ->callTableAction('delete', $unused);

        $this->assertNull(EmployeeType::find($unused->id));
        $this->assertNotNull(EmployeeType::find($used->id));
    }

    public function test_inactive_types_are_not_offered_in_the_user_form(): void
    {
        $this->asAdmin();
        $active = $this->type('Tetap');
        $inactive = $this->type('Lama', false, false);

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('employee_type_id', function (\Filament\Forms\Components\Select $field) use ($active, $inactive) {
                $options = $field->getOptions();

                return array_key_exists($active->id, $options) && ! array_key_exists($inactive->id, $options);
            });
    }

    public function test_default_staff_cannot_open_or_change_employee_types_and_changes_need_an_explicit_grant(): void
    {
        $type = $this->type('Tetap');
        $default = $this->user('kasir');
        $viewer = $this->user('kasir', ['menu_access' => [EmployeeTypeResource::class]]);
        $maintainer = $this->user('kasir', [
            'menu_access' => [EmployeeTypeResource::class],
            'menu_permissions' => [EmployeeTypeResource::class => ['create', 'update', 'delete']],
        ]);
        $noMenu = $this->user('kasir', ['menu_access' => ['SomeOtherResource']]);

        $this->assertNull($default->menu_access);
        $this->actingAs($default, 'web');
        $this->assertFalse(EmployeeTypeResource::canViewAny(), 'Akun bawaan tidak membuka menu yang mengatur nonaktivasi otomatis.');
        $this->assertFalse(EmployeeTypeResource::canCreate());
        $this->assertFalse(EmployeeTypeResource::canEdit($type));
        $this->assertFalse((bool) EmployeeTypeResource::canDelete($type));
        Livewire::test(CreateEmployeeType::class)->assertForbidden();

        $this->actingAs($viewer, 'web');
        $this->assertTrue(EmployeeTypeResource::canViewAny());
        $this->assertFalse(EmployeeTypeResource::canCreate(), 'Menu dicentang saja belum boleh mengubah.');
        $this->assertFalse(EmployeeTypeResource::canEdit($type));
        $this->assertFalse((bool) EmployeeTypeResource::canDelete($type));

        $this->actingAs($maintainer, 'web');
        $this->assertTrue(EmployeeTypeResource::canCreate());
        $this->assertTrue(EmployeeTypeResource::canEdit($type));
        $this->assertTrue((bool) EmployeeTypeResource::canDelete($type));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(EmployeeTypeResource::canViewAny());
        $this->assertFalse(EmployeeTypeResource::canCreate());
        $this->assertFalse(EmployeeTypeResource::canEdit($type));
        $this->assertFalse((bool) EmployeeTypeResource::canDelete($type));
    }

    // ------------------------------------------------------------- nonaktivasi otomatis

    public function test_expired_fixed_term_employees_are_deactivated_and_admins_notified(): void
    {
        $admin = $this->user('super_admin');
        $contract = $this->type('Kontrak', true);
        $permanent = $this->type('Tetap', false);

        $expired = $this->user('kasir', ['employee_type_id' => $contract->id, 'contract_end_date' => today()->subDay()]);
        $endsToday = $this->user('kasir', ['employee_type_id' => $contract->id, 'contract_end_date' => today()]);
        $future = $this->user('kasir', ['employee_type_id' => $contract->id, 'contract_end_date' => today()->addDays(10)]);
        $noDate = $this->user('kasir', ['employee_type_id' => $contract->id, 'contract_end_date' => null]);
        $permanentStale = $this->user('kasir', ['employee_type_id' => $permanent->id, 'contract_end_date' => today()->subMonth()]);
        $alreadyOff = $this->user('kasir', ['employee_type_id' => $contract->id, 'contract_end_date' => today()->subMonth(), 'is_active' => false]);

        $this->artisan('contracts:deactivate-expired')->assertSuccessful();

        $this->assertFalse($expired->fresh()->is_active);
        $this->assertTrue($endsToday->fresh()->is_active, 'Hari terakhir kontrak masih berlaku.');
        $this->assertTrue($future->fresh()->is_active);
        $this->assertTrue($noDate->fresh()->is_active);
        $this->assertTrue($permanentStale->fresh()->is_active, 'Karyawan tetap dengan tanggal nyasar tidak ikut dinonaktifkan.');
        $this->assertFalse($alreadyOff->fresh()->is_active);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString($expired->name, $admin->notifications()->first()->data['body']);
    }

    public function test_deactivation_command_is_idempotent_and_silent_when_nothing_expired(): void
    {
        $admin = $this->user('super_admin');
        $contract = $this->type('Kontrak', true);
        $this->user('kasir', ['employee_type_id' => $contract->id, 'contract_end_date' => today()->subDay()]);

        $this->artisan('contracts:deactivate-expired')->assertSuccessful();
        $this->artisan('contracts:deactivate-expired')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count(), 'Run kedua tidak mengirim notifikasi lagi.');
    }

    public function test_turning_off_has_end_date_exempts_a_type_from_auto_deactivation(): void
    {
        $this->asAdmin();
        $type = $this->type('Kontrak', true);
        $employee = $this->user('kasir', ['employee_type_id' => $type->id, 'contract_end_date' => today()->subWeek()]);

        Livewire::test(EditEmployeeType::class, ['record' => $type->getKey()])
            ->fillForm(['has_end_date' => false])->call('save')->assertHasNoFormErrors();

        $this->artisan('contracts:deactivate-expired')->assertSuccessful();

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_the_form_and_list_explain_the_automatic_deactivation_and_why_a_type_cannot_be_deleted(): void
    {
        $this->asAdmin();
        $used = $this->type('Kontrak (PKWT)', true);
        $unused = $this->type('Magang', true);
        $this->user('kasir', ['employee_type_id' => $used->id]);

        Livewire::test(CreateEmployeeType::class)->assertSee('DINONAKTIFKAN OTOMATIS')->assertSee('diperpanjang di Riwayat Kontrak');

        Livewire::test(ListEmployeeTypes::class)->assertSuccessful()
            ->assertSee('Tidak bisa dihapus selama masih dipakai')
            ->assertTableColumnStateSet('users_count', 1, $used->loadCount('users'))
            ->assertTableColumnStateSet('users_count', 0, $unused->loadCount('users'));
    }

    public function test_the_edit_page_previews_who_is_affected_for_the_selected_toggle_position(): void
    {
        $this->asAdmin();
        $type = $this->type('Tetap', false);
        $this->user('kasir', ['employee_type_id' => $type->id, 'contract_end_date' => today()->subDays(10)->toDateString()]);   // lewat tanggal
        $this->user('kasir', ['employee_type_id' => $type->id, 'contract_end_date' => today()->addDays(10)->toDateString()]);   // segera berakhir
        $this->user('kasir', ['employee_type_id' => $type->id]);                                                                // tanpa tanggal
        $this->user('kasir', ['employee_type_id' => $type->id, 'is_active' => false, 'contract_end_date' => today()->subYear()->toDateString()]);   // nonaktif: tidak dihitung

        $off = EmployeeTypeResource::impactSummary($type, false);
        $this->assertStringContainsString('3 karyawan aktif', $off);
        $this->assertStringContainsString('tidak pernah dinonaktifkan otomatis', $off);
        $this->assertStringContainsString('1 di antaranya punya tanggal kontrak yang sudah lewat; tetap aktif', $off);

        $on = EmployeeTypeResource::impactSummary($type, true);
        $this->assertStringContainsString('Kontrak berakhir dalam 30 hari: 1', $on);
        $this->assertStringContainsString('Tanggal kontrak sudah lewat: 1 (akan dinonaktifkan otomatis', $on);
        $this->assertStringContainsString('Belum ada tanggal berakhir: 1 (perlu diisi', $on);

        $this->assertSame('Belum ada karyawan aktif bertipe ini.', EmployeeTypeResource::impactSummary($this->type('Kosong'), true));

        Livewire::test(EditEmployeeType::class, ['record' => $type->getKey()])
            ->assertSee('Dampak pada karyawan saat ini')
            ->assertSee('3 karyawan aktif bertipe ini')
            ->fillForm(['has_end_date' => true])
            ->assertSee('Tanggal kontrak sudah lewat: 1');

        Livewire::test(CreateEmployeeType::class)->assertDontSee('Dampak pada karyawan saat ini');
    }
}
