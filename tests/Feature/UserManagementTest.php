<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\ContractExtension;
use App\Models\EmployeeCareerHistory;
use App\Models\EmployeeType;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * User (akun karyawan): pembuatan & validasi, role + wajib toko, pengaman
 * "admin terakhir", nonaktifkan/hapus (riwayat HR melindungi dari hapus),
 * perpindahan toko tercatat di Riwayat Karir, akses menu per modul, audit
 * tanpa password, serta login & ganti password di aplikasi staf.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'installer', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
    }

    private function user(string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'Rahasia123',
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function asAdmin(): User
    {
        $admin = $this->user('super_admin', ['name' => 'Admin Utama']);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function roleId(string $name): int
    {
        return Role::findByName($name, 'web')->id;
    }

    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Karyawan Baru', 'email' => 'baru@ginnva.test', 'password' => 'Rahasia123', 'passwordConfirmation' => 'Rahasia123',
            'roles' => [$this->roleId('kasir')], 'is_active' => true,
        ], $overrides);
    }

    // ------------------------------------------------------------- daftar

    public function test_list_hides_partner_accounts_and_filters_work(): void
    {
        $this->asAdmin();
        $kasir = $this->user('kasir', ['store_id' => $this->store->id, 'employee_number' => 'EMP-001']);
        $manager = $this->user('store_manager', ['store_id' => $this->otherStore->id]);
        $inactive = $this->user('kasir', ['is_active' => false]);
        $partner = $this->user('partner');
        $expiring = $this->user('kasir', ['contract_end_date' => today()->addDays(10)]);
        $expired = $this->user('kasir', ['contract_end_date' => today()->subDays(5)]);

        Livewire::test(ListUsers::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$kasir, $manager, $inactive])
            ->assertCanNotSeeTableRecords([$partner]);

        Livewire::test(ListUsers::class)->filterTable('store_id', $this->store->id)
            ->assertCanSeeTableRecords([$kasir])->assertCanNotSeeTableRecords([$manager]);

        Livewire::test(ListUsers::class)->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$kasir, $manager]);

        Livewire::test(ListUsers::class)->filterTable('tanpa_no_karyawan')
            ->assertCanSeeTableRecords([$manager, $inactive])->assertCanNotSeeTableRecords([$kasir]);

        Livewire::test(ListUsers::class)->filterTable('contract_status', 'expiring')
            ->assertCanSeeTableRecords([$expiring])->assertCanNotSeeTableRecords([$expired, $kasir]);

        Livewire::test(ListUsers::class)->filterTable('contract_status', 'expired')
            ->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$expiring, $kasir]);
    }

    // ------------------------------------------------------------- buat

    public function test_admin_creates_a_staff_account_with_hashed_password_and_role(): void
    {
        $this->asAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['employee_number' => 'EMP-010', 'phone' => '081234567890', 'base_salary' => 4500000]))
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'baru@ginnva.test')->firstOrFail();
        $this->assertTrue($user->hasRole('kasir'));
        $this->assertTrue(Hash::check('Rahasia123', $user->password));
        $this->assertNotSame('Rahasia123', $user->password);
        $this->assertSame('EMP-010', $user->employee_number);
        $this->assertEquals(4500000, $user->base_salary);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->menu_access, 'Tidak ada modul dicentang = akses penuh (null).');
    }

    public function test_required_fields_and_uniqueness(): void
    {
        $this->asAdmin();
        $this->user('kasir', ['email' => 'dipakai@ginnva.test', 'employee_number' => 'EMP-777']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => '', 'email' => '', 'password' => '', 'passwordConfirmation' => '', 'roles' => []])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'email' => 'required', 'password' => 'required', 'passwordConfirmation' => 'required', 'roles' => 'required']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'dipakai@ginnva.test']))
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['employee_number' => 'EMP-777']))
            ->call('create')
            ->assertHasFormErrors(['employee_number' => 'unique']);

        $this->assertSame(2, User::count());
    }

    public function test_password_must_be_strong_and_match_its_confirmation(): void
    {
        $this->asAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['password' => 'pendek1A', 'passwordConfirmation' => 'pendek1A', 'email' => 'a@ginnva.test']))
            ->call('create')->assertHasNoFormErrors();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['password' => 'Aa1', 'passwordConfirmation' => 'Aa1', 'email' => 'b@ginnva.test']))
            ->call('create')->assertHasFormErrors(['password' => 'min']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['password' => 'semuahurufkecil1', 'passwordConfirmation' => 'semuahurufkecil1', 'email' => 'c@ginnva.test']))
            ->call('create')->assertHasFormErrors(['password' => 'regex']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['password' => 'Rahasia123', 'passwordConfirmation' => 'Beda123456', 'email' => 'd@ginnva.test']))
            ->call('create')->assertHasFormErrors(['password' => 'same']);
    }

    public function test_installer_and_store_manager_must_have_a_store(): void
    {
        $this->asAdmin();

        foreach (['installer', 'store_manager'] as $i => $role) {
            Livewire::test(CreateUser::class)
                ->fillForm($this->validForm(['email' => "tanpa{$i}@ginnva.test", 'roles' => [$this->roleId($role)], 'store_id' => null]))
                ->call('create')
                ->assertHasFormErrors(['store_id' => 'required']);
        }

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'ada@ginnva.test', 'roles' => [$this->roleId('installer')], 'store_id' => $this->store->id]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->store->id, User::where('email', 'ada@ginnva.test')->firstOrFail()->store_id);
    }

    public function test_company_wide_roles_do_not_need_a_store(): void
    {
        $this->asAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'direksi@ginnva.test', 'roles' => [$this->roleId('direksi')]]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(User::where('email', 'direksi@ginnva.test')->firstOrFail()->isFullAccess());
    }

    public function test_partner_role_is_not_offered_in_the_internal_user_form(): void
    {
        $this->asAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'mitra@ginnva.test', 'roles' => [$this->roleId('partner')]]))
            ->call('create')
            ->assertHasFormErrors();

        $this->assertNull(User::where('email', 'mitra@ginnva.test')->first());
    }

    public function test_join_date_cannot_be_in_the_future(): void
    {
        $this->asAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['join_date' => today()->addDays(3)->toDateString()]))
            ->call('create')
            ->assertHasFormErrors(['join_date']);
    }

    public function test_contract_end_date_is_required_only_for_fixed_term_employee_types(): void
    {
        $this->asAdmin();
        $permanent = EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $contract = EmployeeType::create(['name' => 'Kontrak', 'has_end_date' => true, 'is_active' => true]);

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'tetap@ginnva.test', 'employee_type_id' => $permanent->id]))
            ->call('create')->assertHasNoFormErrors();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'kontrak@ginnva.test', 'employee_type_id' => $contract->id]))
            ->call('create')->assertHasFormErrors(['contract_end_date' => 'required']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'kontrak@ginnva.test', 'employee_type_id' => $contract->id, 'contract_end_date' => today()->addMonths(6)->toDateString()]))
            ->call('create')->assertHasNoFormErrors();
    }

    public function test_hris_personal_data_is_saved_for_full_access_admins(): void
    {
        $this->asAdmin();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm([
                'nik' => '3174010101900001', 'npwp' => '12.345.678.9-012.000', 'bank_name' => 'BCA',
                'bank_account_number' => '1234567890', 'bank_account_holder_name' => 'Karyawan Baru',
                'emergency_contact_name' => 'Ibu', 'emergency_contact_phone' => '0811', 'emergency_contact_relationship' => 'Orang Tua',
            ]))
            ->call('create')->assertHasNoFormErrors();

        $user = User::where('email', 'baru@ginnva.test')->firstOrFail();
        $this->assertSame('3174010101900001', $user->nik);
        $this->assertSame('BCA', $user->bank_name);
        $this->assertSame('Orang Tua', $user->emergency_contact_relationship);
    }

    // ------------------------------------------------------------- akses menu

    public function test_restricting_menu_access_to_one_module_limits_the_user_to_it(): void
    {
        $this->asAdmin();
        $moduleFields = collect(array_keys(UserResource::splitMenuAccessIntoFields(['menu_access' => []])))
            ->filter(fn ($key) => str_starts_with($key, 'menu_access_'))->values();
        $this->assertGreaterThan(5, $moduleFields->count(), 'Daftar modul akses menu terisi.');
        $field = $moduleFields->first();

        Livewire::test(CreateUser::class)
            ->fillForm($this->validForm(['email' => 'terbatas@ginnva.test', $field => true]))
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'terbatas@ginnva.test')->firstOrFail();
        $this->assertIsArray($user->menu_access);
        $this->assertCount(1, $user->menu_access);
        $this->assertTrue($user->hasMenuAccess($user->menu_access[0]));
        $this->assertFalse($user->hasMenuAccess('ModulLainYangTidakDicentang'));
    }

    public function test_menu_access_helpers_round_trip_and_null_means_full_access(): void
    {
        $unset = $this->user('kasir');
        $this->assertTrue($unset->hasMenuAccess('ApaSajaResource'), 'menu_access null = akses semua menu.');

        $restricted = $this->user('kasir', ['menu_access' => ['BookingResource']]);
        $this->assertTrue($restricted->hasMenuAccess(\App\Filament\Resources\BookingResource::class));
        $this->assertFalse($restricted->hasMenuAccess(\App\Filament\Resources\InvoiceResource::class));

        $this->assertTrue($this->user('direksi', ['menu_access' => []])->hasMenuAccess('ApaSajaResource'), 'Full-access tidak pernah dibatasi.');

        $merged = UserResource::mergeMenuAccessFields(['name' => 'X']);
        $this->assertNull($merged['menu_access']);
        $this->assertNull(UserResource::mergeMenuPermissionFields(['name' => 'X'])['menu_permissions']);
    }

    public function test_module_action_permissions_are_grant_only(): void
    {
        $user = $this->user('kasir', [
            'menu_access' => ['BookingResource'],
            'menu_permissions' => ['BookingResource' => ['delete']],
        ]);
        $class = \App\Filament\Resources\BookingResource::class;

        $this->assertTrue($user->hasModuleAction($class, 'delete', false), 'Dicentang eksplisit = boleh.');
        $this->assertTrue($user->hasModuleAction($class, 'update', true), 'Tidak dicentang tidak mencabut default yang sudah boleh.');
        $this->assertFalse($user->hasModuleAction($class, 'void', false), 'Aksi ketat tetap tertutup sampai dicentang.');
        $this->assertFalse($user->hasModuleAction(\App\Filament\Resources\InvoiceResource::class, 'update', true), 'Modul di luar menu tertutup semua.');
    }

    // ------------------------------------------------------------- edit

    public function test_edit_keeps_password_when_blank_and_changes_it_when_given(): void
    {
        $this->asAdmin();
        $user = $this->user('kasir', ['password' => 'Rahasia123']);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Nama Diganti', 'password' => '', 'passwordConfirmation' => ''])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('Nama Diganti', $user->fresh()->name);
        $this->assertTrue(Hash::check('Rahasia123', $user->fresh()->password));

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['password' => 'BaruAman456', 'passwordConfirmation' => 'BaruAman456'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('BaruAman456', $user->fresh()->password));
    }

    public function test_changing_roles_is_audited_and_activity_log_never_stores_the_password(): void
    {
        $admin = $this->asAdmin();
        $user = $this->user('kasir');

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['roles' => [$this->roleId('store_manager')], 'store_id' => $this->store->id])
            ->call('save')->assertHasNoFormErrors();

        $this->assertTrue($user->fresh()->hasRole('store_manager'));
        $roleLog = Activity::where('log_name', 'user')->where('subject_id', $user->id)->where('description', 'like', 'Role user%')->first();
        $this->assertNotNull($roleLog, 'Perubahan role dicatat manual.');
        $this->assertSame($admin->id, $roleLog->causer_id);
        $this->assertSame(['kasir'], $roleLog->properties['old']['roles']);
        $this->assertSame(['store_manager'], $roleLog->properties['attributes']['roles']);

        foreach (Activity::where('subject_id', $user->id)->get() as $log) {
            $this->assertStringNotContainsString('password', json_encode($log->properties));
        }
    }

    public function test_the_last_active_full_access_account_cannot_lose_its_role(): void
    {
        $admin = $this->asAdmin();

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['roles' => [$this->roleId('kasir')]])
            ->call('save');

        $this->assertTrue($admin->fresh()->hasRole('super_admin'), 'Satu-satunya admin aktif tidak boleh mencabut role-nya sendiri.');
        $this->assertFalse($admin->fresh()->hasRole('kasir'));
    }

    public function test_a_full_access_account_can_change_role_when_another_active_admin_exists(): void
    {
        $admin = $this->asAdmin();
        $this->user('direksi');

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['roles' => [$this->roleId('kasir')]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertTrue($admin->fresh()->hasRole('kasir'));
        $this->assertFalse($admin->fresh()->hasRole('super_admin'));
    }

    public function test_an_inactive_second_admin_does_not_count_as_a_safety_net(): void
    {
        $admin = $this->asAdmin();
        $this->user('direksi', ['is_active' => false]);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['roles' => [$this->roleId('kasir')]])
            ->call('save');

        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }

    public function test_moving_a_user_to_another_store_writes_a_career_history_with_reason(): void
    {
        $admin = $this->asAdmin();
        $user = $this->user('store_manager', ['store_id' => $this->store->id]);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['store_id' => $this->otherStore->id, 'transfer_reason' => 'Mutasi ke cabang Bandung'])
            ->call('save')->assertHasNoFormErrors();

        $history = EmployeeCareerHistory::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($this->store->id, $history->previous_store_id);
        $this->assertSame($this->otherStore->id, $history->new_store_id);
        $this->assertSame('Mutasi ke cabang Bandung', $history->reason);
        $this->assertSame($admin->id, $history->changed_by);
    }

    public function test_saving_without_changing_the_store_writes_no_career_history(): void
    {
        $this->asAdmin();
        $user = $this->user('store_manager', ['store_id' => $this->store->id]);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Nama Lain'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, EmployeeCareerHistory::where('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------- nonaktif & hapus

    public function test_deactivating_blocks_panel_and_mobile_login_and_is_audited(): void
    {
        $this->asAdmin();
        $user = $this->user('kasir', ['email' => 'staf@ginnva.test', 'password' => 'Rahasia123']);
        $this->postJson('/api/staff/auth/login', ['email' => 'staf@ginnva.test', 'password' => 'Rahasia123'])->assertSuccessful();

        Livewire::test(ListUsers::class)->callTableAction('toggleActive', $user);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertFalse($fresh->canAccessPanel(Filament::getPanel('admin')));
        $this->postJson('/api/staff/auth/login', ['email' => 'staf@ginnva.test', 'password' => 'Rahasia123'])->assertStatus(403);
        $this->assertTrue(Activity::where('log_name', 'user')->where('subject_id', $user->id)->where('event', 'updated')->exists());

        Livewire::test(ListUsers::class)->callTableAction('toggleActive', $fresh);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_you_cannot_deactivate_or_delete_your_own_account(): void
    {
        $admin = $this->asAdmin();
        $other = $this->user('kasir');

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('toggleActive', $admin)
            ->assertTableActionVisible('toggleActive', $other);

        $this->assertFalse((bool) UserResource::canDelete($admin));
        $this->assertTrue((bool) UserResource::canDelete($other));
    }

    public function test_accounts_with_hr_history_cannot_be_hard_deleted(): void
    {
        $this->asAdmin();
        $clean = $this->user('kasir');
        $withHistory = $this->user('kasir');
        ContractExtension::create([
            'user_id' => $withHistory->id, 'previous_end_date' => today()->subMonth(), 'new_end_date' => today()->addMonths(5), 'extended_by' => auth()->id(),
        ]);

        $this->assertFalse($clean->hasHrHistory());
        $this->assertTrue($withHistory->hasHrHistory());
        $this->assertFalse((bool) UserResource::canDelete($withHistory));

        Livewire::test(ListUsers::class)->callTableAction('delete', $clean);
        $this->assertNull(User::find($clean->id));
        $this->assertNotNull(User::find($withHistory->id));
    }

    public function test_bulk_delete_skips_history_holders_and_yourself(): void
    {
        $admin = $this->asAdmin();
        $clean = $this->user('kasir');
        $withHistory = $this->user('kasir');
        ContractExtension::create([
            'user_id' => $withHistory->id, 'previous_end_date' => today()->subMonth(), 'new_end_date' => today()->addMonths(5), 'extended_by' => $admin->id,
        ]);

        Livewire::test(ListUsers::class)->callTableBulkAction('delete', [$clean, $withHistory, $admin]);

        $this->assertNull(User::find($clean->id));
        $this->assertNotNull(User::find($withHistory->id));
        $this->assertNotNull(User::find($admin->id));
    }

    // ------------------------------------------------------------- izin

    public function test_only_full_access_accounts_can_manage_users(): void
    {
        $record = $this->user('kasir');

        foreach (['kasir', 'store_manager'] as $role) {
            $this->actingAs($this->user($role, ['store_id' => $this->store->id]), 'web');
            $this->assertFalse(UserResource::canViewAny());
            $this->assertFalse(UserResource::canCreate());
            $this->assertFalse(UserResource::canEdit($record));
            $this->assertFalse((bool) UserResource::canDelete($record));
        }

        foreach (['super_admin', 'direksi'] as $role) {
            $this->actingAs($this->user($role), 'web');
            $this->assertTrue(UserResource::canViewAny());
            $this->assertTrue(UserResource::canCreate());
            $this->assertTrue(UserResource::canEdit($record));
        }
    }

    // ------------------------------------------------------------- aplikasi staf

    public function test_staff_login_success_wrong_password_and_response_shape(): void
    {
        $this->user('store_manager', ['email' => 'sm@ginnva.test', 'password' => 'Rahasia123', 'store_id' => $this->store->id]);

        $ok = $this->postJson('/api/staff/auth/login', ['email' => 'sm@ginnva.test', 'password' => 'Rahasia123'])->assertSuccessful();
        $this->assertNotEmpty($ok->json('token'));
        $this->assertSame('store_manager', $ok->json('user.role'));
        $this->assertSame($this->store->id, $ok->json('user.store_id'));
        $this->assertTrue($ok->json('user.can_decide_booking_requests'));

        $this->postJson('/api/staff/auth/login', ['email' => 'sm@ginnva.test', 'password' => 'salah'])->assertStatus(401);
        $this->postJson('/api/staff/auth/login', ['email' => 'tidak@ada.test', 'password' => 'Rahasia123'])->assertStatus(401);
        $this->postJson('/api/staff/auth/login', ['email' => 'bukan-email', 'password' => 'x'])->assertStatus(422);
    }

    public function test_detect_role_tells_staff_from_customers(): void
    {
        $this->user('kasir', ['email' => 'staf@ginnva.test']);

        $this->postJson('/api/auth/detect-role', ['email' => 'staf@ginnva.test'])->assertJson(['role' => 'staff']);
        $this->postJson('/api/auth/detect-role', ['email' => 'pelanggan@example.com'])->assertJson(['role' => 'customer']);
    }

    public function test_staff_can_change_password_only_with_the_current_one_and_a_strong_new_one(): void
    {
        $user = $this->user('kasir', ['password' => 'Rahasia123']);
        $change = fn (array $data) => $this->actingAs($user, 'api')->postJson('/api/staff/auth/change-password', $data);

        $change(['current_password' => 'salah', 'password' => 'BaruAman456', 'password_confirmation' => 'BaruAman456'])
            ->assertStatus(422)->assertJsonPath('message', 'Password lama yang Anda masukkan salah.');
        $change(['current_password' => 'Rahasia123', 'password' => 'semuakecil123', 'password_confirmation' => 'semuakecil123'])->assertStatus(422);
        $change(['current_password' => 'Rahasia123', 'password' => 'BaruAman456', 'password_confirmation' => 'beda'])->assertStatus(422);
        $this->assertTrue(Hash::check('Rahasia123', $user->fresh()->password));

        $change(['current_password' => 'Rahasia123', 'password' => 'BaruAman456', 'password_confirmation' => 'BaruAman456'])->assertSuccessful();
        $this->assertTrue(Hash::check('BaruAman456', $user->fresh()->password));
    }
}
