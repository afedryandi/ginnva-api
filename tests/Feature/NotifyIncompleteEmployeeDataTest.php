<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeDataCompletenessReport;
use App\Filament\Resources\PayrollResource\Pages\ListPayrolls;
use App\Models\AttendanceDeductionRule;
use App\Models\EmployeeType;
use App\Models\Store;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Audit Kelengkapan Data Karyawan, opsi C (2026-10-31): peringatan sebelum Generate Payroll dan pengingat bulanan ke pemegang menu.
 */
class NotifyIncompleteEmployeeDataTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private EmployeeType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-10-25 08:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'open' => '08:30', 'close' => '17:00']],
        ]);
        $this->type = EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, array $extra = []): User
    {
        $user = User::create($extra + [
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id,
            'is_active' => true, 'base_salary' => 5000000, 'join_date' => '2025-01-01', 'employee_type_id' => $this->type->id,
            'nik' => '3175010101010001', 'npwp' => '123456789012345', 'bpjs_kesehatan_number' => '1', 'bpjs_ketenagakerjaan_number' => '2',
            'bank_name' => 'BCA', 'bank_account_number' => '1234567890', 'emergency_contact_name' => 'Ibu', 'emergency_contact_phone' => '0812',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function rule(): void
    {
        AttendanceDeductionRule::create(['effective_from' => '2026-01-01', 'late_block_minutes' => 30, 'late_deduction_per_block' => 10000, 'alpha_fine_per_day' => 50000]);
    }

    /** @return array<int, array{0: array, 1: string, 2: string}> */
    private function runAndCapturePushes(): array
    {
        $pushes = [];
        $this->mock(PushNotificationService::class, function ($mock) use (&$pushes) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function (array $ids, string $title, string $body) use (&$pushes) {
                $pushes[] = [$ids, $title, $body];
            });
        });

        $this->artisan('hr:notify-incomplete-data')->assertSuccessful();

        return $pushes;
    }

    public function test_full_access_and_menu_holders_are_reminded_with_the_names_and_nobody_else(): void
    {
        $this->rule();
        $admin = $this->user('super_admin');
        $holder = $this->user('kasir', ['menu_access' => [EmployeeDataCompletenessReport::class]]);
        $plain = $this->user('kasir');
        $this->user('kasir', ['name' => 'Budi Tanpa Gaji', 'base_salary' => null]);

        $pushes = $this->runAndCapturePushes();

        $this->assertCount(1, $pushes, 'Satu pesan untuk semua penerima.');
        $this->assertEqualsCanonicalizing([$admin->id, $holder->id], $pushes[0][0]);
        $this->assertSame('Data Karyawan Belum Lengkap', $pushes[0][1]);
        $this->assertStringContainsString('1 karyawan', $pushes[0][2]);
        $this->assertStringContainsString('Budi Tanpa Gaji', $pushes[0][2]);
        $this->assertStringNotContainsString('Aturan Potongan', $pushes[0][2]);
        $this->assertSame(0, $plain->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_nothing_is_sent_when_data_is_complete_and_a_deduction_rule_applies(): void
    {
        $this->rule();
        $this->user('super_admin');
        $this->user('kasir', ['nik' => null]);   // hanya butir disarankan, bukan wajib

        $this->assertSame([], $this->runAndCapturePushes());
    }

    public function test_a_missing_deduction_rule_is_reported_even_when_all_data_is_complete(): void
    {
        $admin = $this->user('super_admin');

        $pushes = $this->runAndCapturePushes();

        $this->assertSame([[$admin->id]], array_column($pushes, 0));
        $this->assertStringContainsString('Belum ada Aturan Potongan Absensi', $pushes[0][2]);
        $this->assertStringNotContainsString('karyawan masih kurang', $pushes[0][2]);
    }

    public function test_the_command_is_scheduled_on_the_25th_in_the_morning(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'hr:notify-incomplete-data'));

        $this->assertNotNull($event, 'Terdaftar di jadwal.');
        $this->assertSame('0 8 25 * *', $event->expression);
    }

    public function test_the_generate_payroll_dialog_warns_about_incomplete_employees_before_generating(): void
    {
        $this->rule();
        $admin = $this->user('super_admin');
        $this->actingAs($admin, 'web');

        $description = fn () => (string) Livewire::test(ListPayrolls::class)->mountTableAction('generate')->instance()->getMountedTableAction()->getModalDescription();

        $this->assertStringNotContainsString('masih kurang data wajib', $description());

        $this->user('kasir', ['base_salary' => null]);

        $this->assertStringContainsString('1 karyawan aktif masih kurang data wajib', $description());
        $this->assertStringContainsString('Kelengkapan Data Karyawan', $description());
    }
}
