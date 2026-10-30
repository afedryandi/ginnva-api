<?php

namespace Tests\Feature;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Role / Divisi: buat role baru (nama huruf kecil + underscore, unik, tercatat
 * di audit), ubah nama, hapus hanya role kosong yang tidak terkunci, 7 role
 * sistem terkunci (termasuk spv_finance dan installer_leader), role baru langsung bisa dipakai di form User, dan hanya
 * full-access yang boleh mengelola.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['store_manager', 'installer', 'partner', 'kasir', 'spv_finance', 'installer_leader'] as $name) {
            Role::findOrCreate($name, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function asAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function user(string $role, array $extra = []): User
    {
        $user = User::create(array_merge(['name' => ucfirst($role) . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x'], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function role(string $name): Role
    {
        return Role::findByName($name, 'web');
    }

    public function test_list_shows_user_counts_and_marks_system_roles_as_locked(): void
    {
        $this->asAdmin();
        $this->user('kasir');
        $this->user('kasir');
        $custom = Role::create(['name' => 'hrd', 'guard_name' => 'web']);

        Livewire::test(ListRoles::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$this->role('kasir'), $custom, $this->role('super_admin')])
            ->assertTableColumnStateSet('users_count', 2, $this->role('kasir')->loadCount('users'))
            ->assertTableColumnStateSet('protected', true, $this->role('super_admin'))
            ->assertTableColumnStateSet('protected', true, $this->role('store_manager'))
            ->assertTableColumnStateSet('protected', false, $custom);
    }

    public function test_admin_creates_a_role_which_is_audited_and_usable_in_the_user_form(): void
    {
        $admin = $this->asAdmin();

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'warehouse_staff'])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = $this->role('warehouse_staff');
        $this->assertSame('web', $role->guard_name);

        $log = Activity::where('log_name', 'role')->where('subject_id', $role->id)->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('Role "warehouse_staff" dibuat', $log->description);

        // Role baru otomatis dianggap staf yang boleh masuk panel, tanpa ubah kode.
        $staff = $this->user('warehouse_staff');
        $this->assertTrue($staff->canAccessStaffArea());
        $this->assertFalse($staff->isFullAccess());
    }

    public function test_name_must_be_lowercase_underscore_and_unique(): void
    {
        $this->asAdmin();
        Role::create(['name' => 'hrd', 'guard_name' => 'web']);

        Livewire::test(CreateRole::class)->fillForm(['name' => ''])->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        foreach (['Warehouse Staff', 'warehouse staff', 'WAREHOUSE', 'gudang-1', 'gudang2'] as $bad) {
            Livewire::test(CreateRole::class)->fillForm(['name' => $bad])->call('create')
                ->assertHasFormErrors(['name' => 'regex']);
        }

        Livewire::test(CreateRole::class)->fillForm(['name' => 'hrd'])->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        $this->assertNull(Role::where('name', 'like', '%gudang%')->first());
    }

    public function test_renaming_a_custom_role_keeps_its_users_and_is_audited(): void
    {
        $admin = $this->asAdmin();
        $role = Role::create(['name' => 'hrd', 'guard_name' => 'web']);
        $member = $this->user('hrd');

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['name' => 'people_ops'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('people_ops', $role->fresh()->name);
        $this->assertTrue($member->fresh()->hasRole('people_ops'), 'Anggota ikut ke nama baru.');

        $log = Activity::where('log_name', 'role')->where('subject_id', $role->id)->where('description', 'like', '%diubah menjadi%')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('hrd', $log->properties['old']['name']);
        $this->assertSame('people_ops', $log->properties['attributes']['name']);
    }

    public function test_saving_without_a_name_change_writes_no_rename_log(): void
    {
        $this->asAdmin();
        $role = Role::create(['name' => 'hrd', 'guard_name' => 'web']);

        Livewire::test(EditRole::class, ['record' => $role->getKey()])->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, Activity::where('log_name', 'role')->where('description', 'like', '%diubah menjadi%')->count());
    }

    public function test_a_rename_cannot_take_an_existing_name(): void
    {
        $this->asAdmin();
        Role::create(['name' => 'finance', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'hrd', 'guard_name' => 'web']);

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['name' => 'finance'])->call('save')
            ->assertHasFormErrors(['name' => 'unique']);

        $this->assertSame('hrd', $role->fresh()->name);
    }

    public function test_system_roles_cannot_be_edited_or_deleted(): void
    {
        $this->asAdmin();

        foreach (['super_admin', 'direksi', 'installer', 'partner', 'store_manager', 'spv_finance', 'installer_leader'] as $name) {
            $role = $this->role($name);
            $this->assertFalse((bool) RoleResource::canEdit($role), "{$name} tidak boleh diubah (namanya di-hardcode di logika lain).");
            $this->assertFalse((bool) RoleResource::canDelete($role), "{$name} tidak boleh dihapus.");
        }

        Livewire::test(EditRole::class, ['record' => $this->role('store_manager')->getKey()])->assertForbidden();
    }

    public function test_an_empty_custom_role_can_be_deleted_and_is_audited(): void
    {
        $admin = $this->asAdmin();
        $role = Role::create(['name' => 'hrd', 'guard_name' => 'web']);

        $this->assertTrue((bool) RoleResource::canDelete($role));

        Livewire::test(EditRole::class, ['record' => $role->getKey()])->callAction(DeleteAction::class);

        $this->assertNull(Role::where('name', 'hrd')->first());
        $log = Activity::where('log_name', 'role')->where('description', 'Role "hrd" dihapus')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_a_role_that_still_has_users_cannot_be_deleted(): void
    {
        $this->asAdmin();
        $role = Role::create(['name' => 'hrd', 'guard_name' => 'web']);
        $this->user('hrd');

        $this->assertFalse((bool) RoleResource::canDelete($role));

        Livewire::test(ListRoles::class)->assertTableActionHidden('delete', $role);
        $this->assertNotNull(Role::where('name', 'hrd')->first());
    }

    public function test_table_delete_works_for_an_empty_custom_role_only(): void
    {
        $this->asAdmin();
        $empty = Role::create(['name' => 'kosong', 'guard_name' => 'web']);

        Livewire::test(ListRoles::class)
            ->assertTableActionVisible('delete', $empty)
            ->assertTableActionHidden('delete', $this->role('direksi'))
            ->callTableAction('delete', $empty);

        $this->assertNull(Role::where('name', 'kosong')->first());
    }

    public function test_only_full_access_accounts_can_manage_roles(): void
    {
        $custom = Role::create(['name' => 'hrd', 'guard_name' => 'web']);

        foreach (['kasir', 'store_manager'] as $role) {
            $this->actingAs($this->user($role), 'web');
            $this->assertFalse(RoleResource::canViewAny());
            $this->assertFalse(RoleResource::canCreate());
            $this->assertFalse((bool) RoleResource::canEdit($custom));
            $this->assertFalse((bool) RoleResource::canDelete($custom));
        }

        foreach (['super_admin', 'direksi'] as $role) {
            $this->actingAs($this->user($role), 'web');
            $this->assertTrue(RoleResource::canViewAny());
            $this->assertTrue(RoleResource::canCreate());
            $this->assertTrue((bool) RoleResource::canEdit($custom));
        }
    }

    public function test_every_role_name_the_code_relies_on_is_locked_against_rename_and_delete(): void
    {
        $this->asAdmin();

        // Nama role yang dibaca langsung oleh kode (hasRole / hasAnyRole) harus ada di daftar terkunci.
        $hardcoded = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/(?:hasRole\('([a-z_]+)'\)|hasAnyRole\(\[([^\]]*)\]\))/", $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $names = ($match[1] ?? '') !== '' ? [$match[1]] : (preg_match_all("/'([a-z_]+)'/", $match[2] ?? '', $inner) ? $inner[1] : []);
                    $hardcoded = array_merge($hardcoded, $names);
                }
            }
        }

        $missing = array_values(array_diff(array_unique($hardcoded), RoleResource::PROTECTED_ROLES));
        $this->assertSame([], $missing, 'Role yang dipakai di kode tetapi belum terkunci di Role & Divisi: ' . implode(', ', $missing));

        foreach (['spv_finance', 'installer_leader'] as $name) {
            $this->assertFalse((bool) RoleResource::canEdit($this->role($name)), $name);
            $this->assertFalse((bool) RoleResource::canDelete($this->role($name)), $name);
        }
    }

    public function test_the_list_explains_what_each_role_means_and_why_a_role_cannot_be_deleted(): void
    {
        $this->asAdmin();
        $this->user('kasir');
        $custom = Role::create(['name' => 'hrd', 'guard_name' => 'web']);
        $kasir = $this->role('kasir')->loadCount('users');

        $this->assertSame(RoleResource::GENERIC_DESCRIPTION, RoleResource::descriptionFor('hrd'));
        $this->assertStringContainsString('izin/cuti', RoleResource::descriptionFor('store_manager'));
        $this->assertStringContainsString('Penggajian', RoleResource::descriptionFor('spv_finance'));

        foreach (RoleResource::PROTECTED_ROLES as $protected) {
            $this->assertArrayHasKey($protected, RoleResource::DESCRIPTIONS, "{$protected} perlu keterangan.");
        }

        Livewire::test(ListRoles::class)->assertSuccessful()
            ->assertSee('Role hanya label jabatan')
            ->assertSee('Pemimpin tim installer')
            ->assertSee(RoleResource::GENERIC_DESCRIPTION)
            ->assertSee('Tidak bisa dihapus selama masih dipakai')
            ->assertTableColumnStateSet('description', RoleResource::GENERIC_DESCRIPTION, $custom);
    }
}
