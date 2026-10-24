<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Salin akses dari karyawan lain" di form User: mengisi kotak modul dan hak detail dari karyawan lain,
 * tanpa menyentuh role/toko, dan baru tersimpan saat form disimpan.
 */
class CopyUserAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Kunci modul di menu_access dan form User memakai nama pendek, bukan nama kelas lengkap. */
    private const BOOKING = 'BookingResource';
    private const INVOICE = 'InvoiceResource';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'kasir', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'Rahasia123', 'is_active' => true,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    public function test_fields_mirror_the_source_modules_and_rights(): void
    {
        $source = $this->user('kasir', [
            'menu_access' => [self::BOOKING],
            'menu_permissions' => [self::BOOKING => ['delete']],
        ]);

        $fields = UserResource::accessFormFieldsFor($source);

        $this->assertTrue($fields[UserResource::moduleAccessFieldKey(self::BOOKING)]);
        $this->assertFalse($fields[UserResource::moduleAccessFieldKey(self::INVOICE)]);
        $this->assertSame(['delete'], $fields[UserResource::menuPermissionFieldKey(self::BOOKING)]);
        $this->assertSame([], $fields[UserResource::menuPermissionFieldKey(self::INVOICE)]);
        $this->assertArrayNotHasKey('menu_access', $fields);
        $this->assertArrayNotHasKey('menu_permissions', $fields);
    }

    public function test_a_full_access_source_clears_every_box(): void
    {
        $source = $this->user('kasir');

        $fields = UserResource::accessFormFieldsFor($source);

        $this->assertNotEmpty($fields);
        $this->assertNotContains(true, array_filter($fields, fn ($v, $k) => str_starts_with($k, 'menu_access_'), ARRAY_FILTER_USE_BOTH));
    }

    public function test_choosing_a_colleague_fills_the_form_and_saving_keeps_role(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $source = $this->user('kasir', [
            'menu_access' => [self::BOOKING],
            'menu_permissions' => [self::BOOKING => ['delete']],
        ]);
        $target = $this->user('kasir');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->set('data.copy_access_from', $source->getKey())
            ->assertSet('data.' . UserResource::moduleAccessFieldKey(self::BOOKING), true)
            ->assertSet('data.' . UserResource::moduleAccessFieldKey(self::INVOICE), false)
            ->assertSet('data.copy_access_from', null)
            ->call('save');

        $target->refresh();
        $this->assertSame([self::BOOKING], $target->menu_access);
        $this->assertSame(['delete'], $target->menu_permissions[self::BOOKING]);
        $this->assertTrue($target->hasRole('kasir'), 'Role tidak ikut disalin.');
    }

    public function test_nothing_is_saved_until_the_form_is_submitted(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $source = $this->user('kasir', ['menu_access' => [self::BOOKING]]);
        $target = $this->user('kasir');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->set('data.copy_access_from', $source->getKey());

        $this->assertNull($target->fresh()->menu_access);
    }
}
