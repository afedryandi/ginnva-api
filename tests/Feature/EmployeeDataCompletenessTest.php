<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeDataCompletenessReport;
use App\Models\EmployeeType;
use App\Models\Store;
use App\Models\User;
use App\Services\EmployeeDataCompletenessService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 0 (2026-10-28): pemeriksaan kelengkapan data karyawan sebelum payroll, dibagi wajib (mempengaruhi gaji/absensi) dan disarankan.
 */
class EmployeeDataCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private EmployeeType $permanent;
    private EmployeeType $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Carbon::setTestNow('2026-10-20 09:00:00');
        foreach (['kasir', 'partner', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'open' => '08:30', 'close' => '17:00'], ['days' => ['sun'], 'closed' => true]],
        ]);
        $this->permanent = EmployeeType::create(['name' => 'Tetap', 'has_end_date' => false, 'is_active' => true]);
        $this->contract = EmployeeType::create(['name' => 'Kontrak', 'has_end_date' => true, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $extra = [], string $role = 'kasir'): User
    {
        $user = User::create($extra + [
            'name' => 'Karyawan ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id,
            'base_salary' => 5000000, 'join_date' => '2025-01-01', 'employee_type_id' => $this->permanent->id, 'is_active' => true,
            'nik' => '3175010101010001', 'npwp' => '123456789012345', 'bpjs_kesehatan_number' => '1', 'bpjs_ketenagakerjaan_number' => '2',
            'bank_name' => 'BCA', 'bank_account_number' => '123', 'emergency_contact_name' => 'Ibu', 'emergency_contact_phone' => '0812',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function issuesFor(User $user): ?array
    {
        return app(EmployeeDataCompletenessService::class)->findings()->first(fn ($row) => $row['user']->is($user));
    }

    public function test_a_complete_employee_has_no_findings(): void
    {
        $user = $this->employee();

        $this->assertNull($this->issuesFor($user));
    }

    public function test_missing_required_data_is_flagged(): void
    {
        $user = $this->employee(['base_salary' => null, 'store_id' => null, 'join_date' => null, 'employee_type_id' => null]);

        $row = $this->issuesFor($user);

        $this->assertCount(4, $row['required']);
        $this->assertStringContainsString('Gaji pokok', $row['required'][0]);
        $this->assertStringContainsString('toko', $row['required'][1]);
        $this->assertStringContainsString('Tanggal bergabung', $row['required'][2]);
        $this->assertStringContainsString('Tipe karyawan', $row['required'][3]);
    }

    public function test_contract_staff_need_a_valid_end_date(): void
    {
        $noEnd = $this->employee(['employee_type_id' => $this->contract->id, 'contract_end_date' => null]);
        $expired = $this->employee(['employee_type_id' => $this->contract->id, 'contract_end_date' => '2026-10-01']);
        $fine = $this->employee(['employee_type_id' => $this->contract->id, 'contract_end_date' => '2027-03-01']);

        $this->assertStringContainsString('tanpa tanggal berakhir', $this->issuesFor($noEnd)['required'][0]);
        $this->assertStringContainsString('sudah berakhir', $this->issuesFor($expired)['required'][0]);
        $this->assertNull($this->issuesFor($fine));
    }

    public function test_a_store_with_incomplete_opening_hours_is_flagged_for_its_employees(): void
    {
        $noHours = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko Tanpa Jam', 'is_active' => true, 'opening_hours' => []]);
        $partial = Store::create(['city' => 'Medan', 'address' => 'Jl. C', 'name' => 'Toko Setengah', 'is_active' => true, 'opening_hours' => [['days' => ['mon'], 'open' => '09:00']]]);

        $a = $this->employee(['store_id' => $noHours->id]);
        $b = $this->employee(['store_id' => $partial->id]);
        $ok = $this->employee();

        $this->assertStringContainsString('Jam operasional', $this->issuesFor($a)['required'][0]);
        $this->assertStringContainsString('Jam operasional', $this->issuesFor($b)['required'][0]);
        $this->assertNull($this->issuesFor($ok));
    }

    public function test_recommended_data_is_reported_separately_and_does_not_count_as_required(): void
    {
        $user = $this->employee(['bank_account_number' => null, 'nik' => null, 'npwp' => null, 'bpjs_kesehatan_number' => null, 'emergency_contact_name' => null]);

        $row = $this->issuesFor($user);

        $this->assertSame([], $row['required']);
        $this->assertCount(5, $row['recommended']);
    }

    public function test_inactive_staff_and_partner_accounts_are_ignored(): void
    {
        $inactive = $this->employee(['base_salary' => null, 'is_active' => false]);
        $partner = $this->employee(['base_salary' => null], 'partner');

        $this->assertNull($this->issuesFor($inactive));
        $this->assertNull($this->issuesFor($partner));
    }

    public function test_the_page_lists_findings_filters_by_store_and_respects_menu_access(): void
    {
        $admin = $this->employee([], 'super_admin');
        $problem = $this->employee(['name' => 'Budi Bermasalah', 'base_salary' => null]);
        $this->actingAs($admin, 'web');

        Livewire::test(EmployeeDataCompletenessReport::class)
            ->assertSuccessful()
            ->assertSee('Budi Bermasalah')
            ->assertSee('Gaji pokok belum diisi')
            ->set('data.store_id', Store::create(['city' => 'X', 'address' => 'Y', 'name' => 'Toko Lain', 'is_active' => true, 'opening_hours' => [['days' => ['mon'], 'open' => '09:00', 'close' => '17:00']]])->id)
            ->assertDontSee('Budi Bermasalah');

        $this->assertTrue(EmployeeDataCompletenessReport::canAccess());

        $restricted = $this->employee(['menu_access' => ['SomeOtherResource']]);
        $this->actingAs($restricted, 'web');
        $this->assertFalse(EmployeeDataCompletenessReport::canAccess());
    }
}
