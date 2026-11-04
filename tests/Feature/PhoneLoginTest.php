<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\User;
use App\Support\PhoneNumber;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan masuk dengan No. HP (selain email) dan boleh tanpa email: nomor ditulis dalam bentuk apa pun dianggap sama, nomor unik,
 * email kosong disimpan NULL, API staf menerima "login" (aplikasi lama masih mengirim "email"), dan panel admin menerima keduanya.
 */
class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'PasswordLama123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $overrides = [], string $role = 'kasir'): User
    {
        return tap(User::create(array_merge([
            'name' => 'Rina Kasir', 'email' => null, 'phone' => '081234567890', 'password' => self::PASSWORD, 'is_active' => true,
        ], $overrides)), fn (User $u) => $u->assignRole($role));
    }

    // ------------------------------------------------------------- bentuk baku & unik

    public function test_phone_numbers_in_any_notation_normalise_to_the_same_form(): void
    {
        foreach (['081234567890', '+62 812-3456-7890', '6281234567890', '81234567890'] as $written) {
            $this->assertSame('6281234567890', PhoneNumber::digits($written), $written);
        }

        $this->assertNull(PhoneNumber::digits('12345'));
        $this->assertNull(PhoneNumber::digits(null));
        $this->assertNull(PhoneNumber::digits('abc'));
    }

    public function test_the_standard_form_follows_the_phone_and_two_staff_cannot_share_a_number(): void
    {
        $first = $this->staff(['email' => 'a@ginnva.test']);
        $this->assertSame('6281234567890', $first->phone_digits);

        $first->update(['phone' => '0899 1111 2222']);
        $this->assertSame('6289911112222', $first->fresh()->phone_digits);

        $this->expectException(QueryException::class);
        $this->staff(['email' => 'b@ginnva.test', 'phone' => '+62 899 1111 2222']);
    }

    public function test_many_staff_can_have_no_email_and_a_blank_email_is_stored_as_null(): void
    {
        $this->staff(['phone' => '081111111111', 'email' => '']);
        $this->staff(['phone' => '082222222222', 'email' => '   ']);
        $this->staff(['phone' => '083333333333']);

        $this->assertSame(3, User::whereNull('email')->count());
        $this->assertStringContainsString('081111111111', User::where('phone', '081111111111')->first()->loginLabel());
    }

    public function test_find_by_login_matches_email_or_any_phone_notation(): void
    {
        $byPhone = $this->staff(['phone' => '081234567890']);
        $byEmail = $this->staff(['phone' => null, 'email' => 'budi@ginnva.test', 'name' => 'Budi']);

        $this->assertSame($byPhone->id, User::findByLogin('+6281234567890')->id);
        $this->assertSame($byPhone->id, User::findByLogin('0812 3456 7890')->id);
        $this->assertSame($byEmail->id, User::findByLogin('budi@ginnva.test')->id);
        $this->assertNull(User::findByLogin('08999999999'));
        $this->assertNull(User::findByLogin('   '));
        $this->assertNull(User::findByLogin('nobody@ginnva.test'));
    }

    // ------------------------------------------------------------- API staf

    public function test_a_staff_member_without_email_logs_in_to_the_app_with_the_phone_number(): void
    {
        $user = $this->staff();

        foreach (['081234567890', '+6281234567890', '6281234567890'] as $written) {
            $this->postJson('/api/staff/auth/login', ['login' => $written, 'password' => self::PASSWORD])
                ->assertSuccessful()
                ->assertJsonPath('user.id', $user->id)
                ->assertJsonPath('user.email', null)
                ->assertJsonPath('user.phone', '081234567890');
        }
    }

    public function test_email_login_and_the_old_email_key_still_work(): void
    {
        $user = $this->staff(['email' => 'rina@ginnva.test']);

        $this->postJson('/api/staff/auth/login', ['login' => 'rina@ginnva.test', 'password' => self::PASSWORD])->assertSuccessful();
        $this->postJson('/api/staff/auth/login', ['email' => 'rina@ginnva.test', 'password' => self::PASSWORD])->assertSuccessful()->assertJsonPath('user.id', $user->id);
    }

    public function test_wrong_password_unknown_number_and_inactive_account_are_refused(): void
    {
        $user = $this->staff();

        $this->postJson('/api/staff/auth/login', ['login' => '081234567890', 'password' => 'SalahBanget1'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Email/No. HP atau password salah.');
        $this->postJson('/api/staff/auth/login', ['login' => '085555555555', 'password' => self::PASSWORD])->assertStatus(401);
        $this->postJson('/api/staff/auth/login', ['password' => self::PASSWORD])->assertStatus(422);

        $user->update(['is_active' => false]);
        $this->postJson('/api/staff/auth/login', ['login' => '081234567890', 'password' => self::PASSWORD])->assertStatus(403);
    }

    public function test_detect_role_tells_staff_customer_and_unknown_numbers_apart(): void
    {
        $this->staff(['phone' => '081234567890']);
        $this->staff(['phone' => null, 'email' => 'budi@ginnva.test', 'name' => 'Budi']);

        $this->postJson('/api/auth/detect-role', ['login' => '+62 812 3456 7890'])->assertJson(['role' => 'staff']);
        $this->postJson('/api/auth/detect-role', ['login' => 'budi@ginnva.test'])->assertJson(['role' => 'staff']);
        $this->postJson('/api/auth/detect-role', ['email' => 'budi@ginnva.test'])->assertJson(['role' => 'staff']);
        $this->postJson('/api/auth/detect-role', ['login' => 'pelanggan@gmail.com'])->assertJson(['role' => 'customer']);
        $this->postJson('/api/auth/detect-role', ['login' => '085555555555'])->assertJson(['role' => 'unknown']);
    }

    public function test_the_failed_attempt_log_keeps_the_phone_the_person_typed(): void
    {
        $this->postJson('/api/staff/auth/login', ['login' => '085555555555', 'password' => self::PASSWORD])->assertStatus(401);

        $this->assertTrue(\Spatie\Activitylog\Models\Activity::where('log_name', 'auth')->where('description', 'like', '%085555555555%')->exists());
    }

    // ------------------------------------------------------------- panel admin

    public function test_the_admin_panel_accepts_the_phone_number_and_the_email(): void
    {
        $user = $this->staff(['email' => 'admin@ginnva.test', 'phone' => '081234567890'], 'super_admin');

        Livewire::test(Login::class)->fillForm(['email' => '+62 812-3456-7890', 'password' => self::PASSWORD])->call('authenticate')->assertHasNoFormErrors();
        // Guard bawaan aplikasi adalah 'api' (JWT); panel admin memakai 'web'.
        $this->assertAuthenticatedAs($user, 'web');

        auth('web')->logout();

        Livewire::test(Login::class)->fillForm(['email' => 'admin@ginnva.test', 'password' => self::PASSWORD])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_the_admin_panel_refuses_a_wrong_password_and_an_unknown_number(): void
    {
        $this->staff([], 'super_admin');

        Livewire::test(Login::class)->fillForm(['email' => '081234567890', 'password' => 'SalahBanget1'])->call('authenticate')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email' => '12', 'password' => self::PASSWORD])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest('web');
    }
}
