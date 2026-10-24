<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\InvoiceResource;
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
            'menu_access' => [BookingResource::class],
            'menu_permissions' => [BookingResource::class => ['delete']],
        ]);

        $fields = UserResource::accessFormFieldsFor($source);

        $this->assertTrue($fields[UserResource::moduleAccessFieldKey(BookingResource::class)]);
        $this->assertFalse($fields[UserResource::moduleAccessFieldKey(InvoiceResource::class)]);
        $this->assertSame(['delete'], $fields[UserResource::menuPermissionFieldKey(BookingResource::class)]);
        $this->assertSame([], $fields[UserResource::menuPermissionFieldKey(InvoiceResource::class)]);
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
            'menu_access' => [BookingResource::class],
            'menu_permissions' => [BookingResource::class => ['delete']],
        ]);
        $target = $this->user('kasir');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->set('data.copy_access_from', $source->getKey())
            ->assertSet('data.' . UserResource::moduleAccessFieldKey(BookingResource::class), true)
            ->assertSet('data.' . UserResource::moduleAccessFieldKey(InvoiceResource::class), false)
            ->assertSet('data.copy_access_from', null)
            ->call('save');

        $target->refresh();
        $this->assertSame([BookingResource::class], $target->menu_access);
        $this->assertSame(['delete'], $target->menu_permissions[BookingResource::class]);
        $this->assertTrue($target->hasRole('kasir'), 'Role tidak ikut disalin.');
    }

    public function test_nothing_is_saved_until_the_form_is_submitted(): void
    {
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');
        $source = $this->user('kasir', ['menu_access' => [BookingResource::class]]);
        $target = $this->user('kasir');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->set('data.copy_access_from', $source->getKey());

        $this->assertNull($target->fresh()->menu_access);
    }
}
