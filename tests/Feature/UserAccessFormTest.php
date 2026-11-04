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
 * Form Akses Menu & Hak Akses Detail di menu User: pilihan Akses penuh / Dibatasi, halaman laporan yang kini punya kotak, dan kotak Void
 * hanya di modul yang memakainya.
 */
class UserAccessFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user('super_admin'), 'web');
    }

    private function user(string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'Rahasia123', 'is_active' => true,
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function toggle(string $moduleKey): string
    {
        return 'data.' . UserResource::moduleAccessFieldKey($moduleKey);
    }

    // ------------------------------------------------------------- mode akses

    public function test_the_form_opens_in_the_mode_that_matches_the_saved_access(): void
    {
        $full = $this->user('kasir');
        $limited = $this->user('kasir', ['menu_access' => ['BookingResource']]);

        Livewire::test(EditUser::class, ['record' => $full->getKey()])->assertSet('data.access_mode', 'full');
        Livewire::test(EditUser::class, ['record' => $limited->getKey()])->assertSet('data.access_mode', 'limited');
    }

    public function test_saving_a_limited_user_without_touching_the_boxes_keeps_the_access(): void
    {
        $limited = $this->user('kasir', ['menu_access' => ['BookingResource', 'VoidReport'], 'menu_permissions' => ['BookingResource' => ['delete', 'void']]]);

        Livewire::test(EditUser::class, ['record' => $limited->getKey()])->call('save')->assertHasNoFormErrors();

        $limited->refresh();
        $this->assertEqualsCanonicalizing(['BookingResource', 'VoidReport'], $limited->menu_access);
        $this->assertSame(['delete', 'void'], $limited->menu_permissions['BookingResource']);
    }

    public function test_switching_to_full_access_clears_the_boxes_and_saves_null(): void
    {
        $limited = $this->user('kasir', ['menu_access' => ['BookingResource'], 'menu_permissions' => ['BookingResource' => ['delete']]]);

        Livewire::test(EditUser::class, ['record' => $limited->getKey()])
            ->set('data.access_mode', 'full')
            ->assertSet($this->toggle('BookingResource'), false)
            ->call('save')
            ->assertHasNoFormErrors();

        $limited->refresh();
        $this->assertNull($limited->menu_access);
        $this->assertNull($limited->menu_permissions);
    }

    public function test_limited_without_any_module_is_refused_instead_of_silently_becoming_full_access(): void
    {
        $full = $this->user('kasir');

        Livewire::test(EditUser::class, ['record' => $full->getKey()])
            ->set('data.access_mode', 'limited')
            ->call('save')
            ->assertHasFormErrors(['access_mode']);

        $this->assertNull($full->fresh()->menu_access);
    }

    public function test_choosing_modules_in_limited_mode_saves_them(): void
    {
        $user = $this->user('kasir');

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.access_mode', 'limited')
            ->set($this->toggle('InvoiceResource'), true)
            ->set($this->toggle('RefundReport'), true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['InvoiceResource', 'RefundReport'], $user->fresh()->menu_access);
    }

    // ------------------------------------------------------------- laporan yang kini bisa diberi

    public function test_report_pages_can_now_be_granted_and_the_explicit_omzet_reports_follow(): void
    {
        $user = $this->user('kasir', ['menu_access' => ['BookingResource']]);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set($this->toggle('SalesSummaryReport'), true)
            ->set($this->toggle('VoidReport'), true)
            ->set($this->toggle('AttendanceReport'), true)
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertTrue($user->hasExplicitMenuAccess(\App\Filament\Pages\SalesSummaryReport::class));
        $this->assertTrue($user->hasExplicitMenuAccess(\App\Filament\Pages\VoidReport::class));
        $this->assertTrue($user->hasMenuAccess(\App\Filament\Pages\AttendanceReport::class));
        $this->assertFalse($user->hasExplicitMenuAccess(\App\Filament\Pages\RefundReport::class));
    }

    public function test_every_page_guarded_by_menu_access_has_a_box(): void
    {
        $options = collect((fn () => static::menuAccessOptions())->bindTo(null, UserResource::class)())->flatMap(fn ($group) => array_keys($group))->all();
        $missing = [];

        foreach (array_merge(glob(app_path('Filament/Pages/*.php')), glob(app_path('Filament/Resources/*Resource.php'))) as $file) {
            $name = basename($file, '.php');

            if (preg_match('/hasMenuAccess\(|hasExplicitMenuAccess\(/', file_get_contents($file)) && ! in_array($name, $options, true)) {
                $missing[] = $name;
            }
        }

        // Tidak pernah bisa diberikan ke staf biasa (khusus akses penuh), halaman beranda, atau resource yang disembunyikan.
        $this->assertEqualsCanonicalizing(['DashboardHome', 'PriceRuleResource', 'UserResource'], $missing);
    }

    // ------------------------------------------------------------- Void

    public function test_void_is_offered_only_for_modules_that_use_it_or_already_hold_it(): void
    {
        $options = fn (string $moduleKey, ?User $record = null) => (fn () => static::moduleActionOptions($moduleKey, $record))->bindTo(null, UserResource::class)();

        $this->assertArrayHasKey('void', $options('BookingResource'));
        $this->assertArrayHasKey('void', $options('WarningLetterResource'));
        $this->assertArrayNotHasKey('void', $options('InvoiceResource'));
        $this->assertArrayHasKey('delete', $options('InvoiceResource'));

        $holder = $this->user('kasir', ['menu_access' => ['InvoiceResource'], 'menu_permissions' => ['InvoiceResource' => ['void']]]);
        $this->assertArrayHasKey('void', $options('InvoiceResource', $holder));
    }
}
