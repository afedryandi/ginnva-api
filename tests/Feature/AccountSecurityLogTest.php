<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\AccountSecurityLog;
use App\Support\ActivityContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Histori Aktivitas Fase 2 (2026-10-21): peristiwa keamanan akun (masuk, gagal masuk, keluar, sandi) dan konteks tiap catatan
 * (asal aksi; IP dan perangkat hanya untuk staf, tidak untuk pelanggan).
 */
class AccountSecurityLogTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Rahasia123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(array $extra = [], string $role = 'kasir'): User
    {
        $user = User::create(array_merge(['name' => 'Rina Kasir', 'email' => 'rina@ginnva.test', 'password' => Hash::make(self::PASSWORD), 'is_active' => true], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function authLogs()
    {
        return Activity::where('log_name', 'auth')->orderBy('id')->get();
    }

    private function asApp(): self
    {
        return $this->withHeaders(['User-Agent' => 'GinnvaStaff/1.2 (Android 14)']);
    }

    // ------------------------------------------------------------- staf lewat aplikasi

    public function test_a_staff_login_is_recorded_with_origin_ip_and_device(): void
    {
        $user = $this->staff();

        $this->asApp()->postJson('/api/staff/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSuccessful();

        $log = $this->authLogs()->sole();
        $this->assertSame('login', $log->event);
        $this->assertSame([User::class, $user->id], [$log->causer_type, $log->causer_id]);
        $this->assertSame([User::class, $user->id], [$log->subject_type, $log->subject_id]);
        $this->assertSame('Staf "Rina Kasir" masuk lewat aplikasi', $log->description);
        $this->assertSame('mobile_staf', $log->properties['context']['source']);
        $this->assertSame('127.0.0.1', $log->properties['context']['ip']);
        $this->assertStringContainsString('GinnvaStaff/1.2', $log->properties['context']['device']);
        $this->assertArrayNotHasKey('audience', $log->properties->toArray(), 'Petunjuk internal tidak ikut disimpan.');
    }

    public function test_failed_staff_logins_are_recorded_with_the_reason_and_no_password(): void
    {
        $user = $this->staff();

        $this->asApp()->postJson('/api/staff/auth/login', ['email' => $user->email, 'password' => 'SalahBanget1'])->assertStatus(401);
        $this->asApp()->postJson('/api/staff/auth/login', ['email' => 'tidak.ada@ginnva.test', 'password' => 'SalahBanget1'])->assertStatus(401);
        $user->update(['is_active' => false]);
        $this->asApp()->postJson('/api/staff/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(403);

        [$wrong, $unknown, $inactive] = $this->authLogs()->all();

        $this->assertSame('login_failed', $wrong->event);
        $this->assertNull($wrong->causer_id);
        $this->assertSame($user->id, $wrong->subject_id, 'Tampil di tab Riwayat akun yang dituju.');
        $this->assertSame('sandi salah', $wrong->properties['reason']);
        $this->assertSame('127.0.0.1', $wrong->properties['context']['ip'], 'IP dicatat untuk percobaan gagal ke akun staf.');

        $this->assertNull($unknown->subject_id);
        $this->assertSame('akun tidak ditemukan', $unknown->properties['reason']);
        $this->assertStringContainsString('"tidak.ada@ginnva.test"', $unknown->description);

        $this->assertSame('akun nonaktif', $inactive->properties['reason']);

        $json = $this->authLogs()->map(fn ($l) => json_encode($l->properties) . $l->description)->implode('|');
        $this->assertStringNotContainsString('SalahBanget1', $json);
        $this->assertStringNotContainsString(self::PASSWORD, $json);
    }

    public function test_logout_and_password_changes_are_recorded_without_the_password(): void
    {
        $user = $this->staff();
        $token = $this->postJson('/api/staff/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/staff/auth/change-password', [
            'current_password' => self::PASSWORD, 'password' => 'PasswordBaru456', 'password_confirmation' => 'PasswordBaru456',
        ])->assertSuccessful();

        OtpCode::generateFor($user->email, 'staff_reset_password');
        $code = OtpCode::where('identifier', $user->email)->where('channel', 'staff_reset_password')->latest('id')->value('code');
        $this->postJson('/api/staff/auth/reset-password', [
            'email' => $user->email, 'code' => $code, 'password' => 'PasswordLagi789', 'password_confirmation' => 'PasswordLagi789',
        ])->assertSuccessful();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/staff/auth/logout')->assertSuccessful();

        $events = $this->authLogs()->pluck('event')->all();
        $this->assertSame(['login', 'password_changed', 'password_reset', 'logout'], $events);
        $this->assertSame('Kata sandi staf "Rina Kasir" diganti sendiri (aplikasi)', $this->authLogs()[1]->description);

        $json = $this->authLogs()->map(fn ($l) => json_encode($l->properties) . $l->description)->implode('|');
        foreach (['PasswordBaru456', 'PasswordLagi789', self::PASSWORD, $token] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    // ------------------------------------------------------------- pelanggan: tanpa IP

    public function test_customer_login_failure_logout_and_no_ip_for_customers(): void
    {
        $email = 'budi@test.local';

        OtpCode::generateFor($email, 'email');
        $this->postJson('/api/customer/auth/verify-otp', ['email' => $email, 'code' => '000000'])->assertStatus(422);

        $code = OtpCode::where('identifier', $email)->where('channel', 'email')->latest('id')->value('code');
        $token = $this->postJson('/api/customer/auth/verify-otp', ['email' => $email, 'code' => $code])->assertSuccessful()->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/customer/auth/logout')->assertSuccessful();

        [$failed, $login, $logout] = $this->authLogs()->all();
        $customer = Customer::where('email', $email)->firstOrFail();

        $this->assertSame(['login_failed', 'login', 'logout'], [$failed->event, $login->event, $logout->event]);
        $this->assertStringContainsString('b***@test.local', $failed->description, 'Email disamarkan di catatan gagal masuk.');
        $this->assertStringNotContainsString($email, $failed->description);
        $this->assertSame("Pelanggan #{$customer->id} masuk lewat aplikasi", $login->description);
        $this->assertSame([Customer::class, $customer->id], [$login->causer_type, $login->causer_id]);

        foreach ([$failed, $login, $logout] as $log) {
            $this->assertSame('mobile_pelanggan', $log->properties['context']['source']);
            $this->assertArrayNotHasKey('ip', $log->properties['context'], 'Pelanggan: tanpa IP.');
            $this->assertArrayNotHasKey('device', $log->properties['context']);
        }
    }

    // ------------------------------------------------------------- panel admin

    public function test_panel_login_events_are_recorded(): void
    {
        $user = $this->staff(['email' => 'admin@ginnva.test'], 'super_admin');

        event(new Login('web', $user, false));
        event(new Failed('web', null, ['email' => 'salah@ginnva.test', 'password' => 'RahasiaBanget1']));
        event(new Failed('web', $user, ['email' => $user->email, 'password' => 'RahasiaBanget1']));
        event(new Logout('web', $user));
        event(new Login('api', $user, false)); // guard lain tidak dicatat dua kali

        $logs = $this->authLogs();
        $this->assertSame(['login', 'login_failed', 'login_failed', 'logout'], $logs->pluck('event')->all());
        $this->assertSame('Staf "Rina Kasir" masuk di panel admin', $logs[0]->description);
        $this->assertSame('akun tidak ditemukan', $logs[1]->properties['reason']);
        $this->assertSame('sandi salah', $logs[2]->properties['reason']);
        $this->assertSame($user->id, $logs[2]->subject_id);
        $this->assertStringNotContainsString('RahasiaBanget1', $logs->map(fn ($l) => json_encode($l->properties) . $l->description)->implode('|'));
    }

    public function test_an_admin_changing_another_staff_password_is_recorded_with_who_did_it(): void
    {
        $admin = $this->staff(['email' => 'admin@ginnva.test', 'name' => 'Admin'], 'super_admin');
        $target = $this->staff();

        AccountSecurityLog::passwordChanged($target, 'diubah oleh admin (panel)', $admin);

        $log = $this->authLogs()->sole();
        $this->assertSame('password_changed', $log->event);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame('Kata sandi staf "Rina Kasir" diubah oleh admin (panel)', $log->description);
    }

    // ------------------------------------------------------------- konteks umum

    public function test_ordinary_activity_in_the_console_gets_a_source_but_no_ip(): void
    {
        $user = $this->staff();

        $log = activity('store')->causedBy($user)->log('Perubahan dari perintah terjadwal');

        $this->assertSame('konsol', $log->fresh()->properties['context']['source']);
        $this->assertArrayNotHasKey('ip', $log->fresh()->properties['context']);
    }

    public function test_an_existing_context_is_not_overwritten(): void
    {
        $log = activity('store')->withProperties(['context' => ['source' => 'panel']])->log('Sudah punya konteks');

        $this->assertSame(['source' => 'panel'], $log->fresh()->properties['context']);
    }

    public function test_failing_to_stamp_never_breaks_the_action(): void
    {
        $activity = new Activity();

        ActivityContext::stamp($activity); // properties null: tidak boleh melempar

        $this->assertSame('konsol', $activity->properties['context']['source']);
    }

    public function test_the_email_mask_keeps_only_the_first_letter_and_the_domain(): void
    {
        $this->assertSame('b***@test.local', AccountSecurityLog::maskEmail('budi@test.local'));
        $this->assertSame('a*@x.id', AccountSecurityLog::maskEmail('ab@x.id'));
        $this->assertSame('t***', AccountSecurityLog::maskEmail('teks'));
    }

    // ------------------------------------------------------------- tampilan

    public function test_the_activity_menu_shows_security_events_origin_and_ip(): void
    {
        $admin = $this->staff(['email' => 'admin@ginnva.test', 'name' => 'Admin'], 'super_admin');
        $user = $this->staff();
        $this->actingAs($admin, 'web');

        $this->asApp()->postJson('/api/staff/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSuccessful();
        $this->actingAs($admin, 'web');
        $log = $this->authLogs()->sole();
        $record = ActivityResource::getEloquentQuery()->findOrFail($log->id);

        Livewire::test(ListActivities::class)
            ->assertCanSeeTableRecords([$record])
            ->assertTableColumnFormattedStateSet('log_name', 'Keamanan Akun', record: $record)
            ->assertTableColumnFormattedStateSet('event', 'Masuk', record: $record)
            ->assertTableColumnStateSet('source', 'Aplikasi Staf', record: $record);

        Livewire::test(ViewActivity::class, ['record' => $log->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Aplikasi Staf')
            ->assertSee('127.0.0.1')
            ->assertSee('GinnvaStaff/1.2');
    }

    public function test_filters_by_origin_and_by_customer_actor(): void
    {
        $admin = $this->staff(['email' => 'admin@ginnva.test', 'name' => 'Admin'], 'super_admin');
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);
        $this->actingAs($admin, 'web');

        $panel = activity('store')->causedBy($admin)->withProperties(['context' => ['source' => 'panel']])->log('Dari panel');
        $app = activity('store')->causedBy($customer)->withProperties(['context' => ['source' => 'mobile_pelanggan']])->log('Dari aplikasi pelanggan');

        Livewire::test(ListActivities::class)
            ->filterTable('source', 'panel')
            ->assertCanSeeTableRecords([$panel])
            ->assertCanNotSeeTableRecords([$app]);

        Livewire::test(ListActivities::class)
            ->filterTable('customer_causer_id', $customer->id)
            ->assertCanSeeTableRecords([$app])
            ->assertCanNotSeeTableRecords([$panel]);
    }
}
