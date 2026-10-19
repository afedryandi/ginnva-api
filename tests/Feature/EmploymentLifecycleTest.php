<?php

namespace Tests\Feature;

use App\Filament\Resources\CompanyPolicyResource;
use App\Filament\Resources\EmployeeChecklistItemResource\Pages\ListEmployeeChecklistItems;
use App\Filament\Resources\SalaryGradeResource;
use App\Models\CompanyPolicy;
use App\Models\ContractExtension;
use App\Models\EmployeeChecklistItem;
use App\Models\EmployeeType;
use App\Models\SalaryGrade;
use App\Models\Store;
use App\Models\User;
use App\Models\WarningLetter;
use App\Services\EmployeeDataCompletenessService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 3 (2026-10-31): Peraturan Perusahaan dan bukti penerimaan, checklist onboarding/offboarding, masa berlaku SP otomatis,
 * batas PKWT 5 tahun, dan struktur & skala upah. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class EmploymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $staff;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('local');
        foreach (['kasir', 'super_admin', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->staff = $this->makeUser('Rina', 'kasir');
        $this->admin = $this->makeUser('Admin', 'super_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $name, string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => $name, 'email' => strtolower($name) . uniqid() . '@test.local', 'password' => 'x',
            'store_id' => $this->store->id, 'join_date' => '2024-01-01',
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function policy(array $over = []): CompanyPolicy
    {
        return CompanyPolicy::create(array_merge([
            'title' => 'Peraturan Perusahaan', 'category' => 'peraturan_perusahaan', 'version' => '1.0',
            'file_path' => 'company-policies/pp.pdf', 'requires_acknowledgment' => true, 'is_published' => true,
        ], $over));
    }

    // ------------------------------------------------------------- kebijakan

    public function test_api_lists_only_published_policies_and_acknowledgment_is_idempotent(): void
    {
        $published = $this->policy();
        $draft = $this->policy(['title' => 'Draf', 'is_published' => false]);

        $response = $this->actingAs($this->staff, 'api')->getJson('/api/staff/policies')->assertSuccessful();
        $ids = collect($response->json('policies'))->pluck('id');
        $this->assertTrue($ids->contains($published->id));
        $this->assertFalse($ids->contains($draft->id));
        $this->assertNull($response->json('policies.0.acknowledged_at'));

        $first = $this->actingAs($this->staff, 'api')->postJson("/api/staff/policies/{$published->id}/acknowledge")->assertSuccessful()->json('acknowledged_at');
        Carbon::setTestNow('2026-10-08 09:00:00');
        $second = $this->actingAs($this->staff, 'api')->postJson("/api/staff/policies/{$published->id}/acknowledge")->assertSuccessful()->json('acknowledged_at');

        $this->assertSame($first, $second, 'Penerimaan pertama yang berlaku.');
        $this->assertSame(1, $published->acknowledgments()->count());
        $this->assertNotNull($this->actingAs($this->staff, 'api')->getJson('/api/staff/policies')->json('policies.0.acknowledged_at'));

        $this->actingAs($this->staff, 'api')->postJson("/api/staff/policies/{$draft->id}/acknowledge")->assertStatus(404);
    }

    public function test_pending_users_exclude_partners_inactive_and_those_who_accepted(): void
    {
        $policy = $this->policy();
        $partner = $this->makeUser('Mitra', 'partner');
        $inactive = $this->makeUser('Keluar', 'kasir', ['is_active' => false]);
        $policy->acknowledge($this->staff);

        $pending = $policy->pendingUsers()->pluck('id');

        $this->assertTrue($pending->contains($this->admin->id));
        $this->assertFalse($pending->contains($this->staff->id));
        $this->assertFalse($pending->contains($partner->id));
        $this->assertFalse($pending->contains($inactive->id));
    }

    public function test_a_policy_with_acknowledgments_cannot_be_deleted_and_requires_the_flag_to_be_acknowledged(): void
    {
        $this->actingAs($this->admin, 'web');
        $policy = $this->policy();
        $this->assertTrue(CompanyPolicyResource::canDelete($policy));

        $policy->acknowledge($this->staff);
        $this->assertFalse(CompanyPolicyResource::canDelete($policy));

        $info = $this->policy(['title' => 'Info', 'requires_acknowledgment' => false]);
        $this->actingAs($this->staff, 'api')->postJson("/api/staff/policies/{$info->id}/acknowledge")->assertStatus(404);
    }

    public function test_completeness_flags_employees_who_have_not_accepted_a_required_policy(): void
    {
        $policy = $this->policy(['title' => 'Peraturan Perusahaan 2026']);
        $policy->acknowledge($this->staff);

        $rows = (new EmployeeDataCompletenessService())->findings()->keyBy(fn ($r) => $r['user']->id);

        $this->assertNotContains('Belum menerima dokumen "Peraturan Perusahaan 2026"', $rows[$this->staff->id]['recommended'] ?? []);
        $this->assertContains('Belum menerima dokumen "Peraturan Perusahaan 2026"', $rows[$this->admin->id]['recommended']);
    }

    public function test_profile_reports_pending_policies_and_probation(): void
    {
        $this->policy();
        $this->staff->update(['probation_end_date' => '2026-12-01']);

        $profile = $this->actingAs($this->staff, 'api')->getJson('/api/staff/profile')->json('profile');

        $this->assertSame(1, $profile['pending_policies']);
        $this->assertSame('2026-12-01', $profile['employment']['probation_end_date']);
    }

    // ------------------------------------------------------------- SP & PKWT

    public function test_warning_letters_expire_after_six_months_unless_a_date_is_given(): void
    {
        $base = ['user_id' => $this->staff->id, 'store_id' => $this->store->id, 'level' => 'sp1', 'reason' => 'Telat', 'issued_date' => '2026-10-07'];

        $auto = WarningLetter::create($base);
        $explicit = WarningLetter::create($base + ['valid_until' => '2027-01-01']);

        $this->assertSame('2027-04-07', $auto->valid_until->toDateString());
        $this->assertSame('2027-01-01', $explicit->valid_until->toDateString());
    }

    public function test_contract_extension_cannot_pass_the_five_year_pkwt_limit(): void
    {
        $type = EmployeeType::create(['name' => 'Kontrak', 'has_end_date' => true, 'is_active' => true]);
        $worker = $this->makeUser('Kontrak', 'kasir', ['join_date' => '2022-01-10', 'employee_type_id' => $type->id, 'contract_end_date' => '2026-12-31']);

        $ok = ContractExtension::recordExtension($worker, '2027-01-09', $this->admin->id);
        $this->assertSame('2027-01-09', $worker->fresh()->contract_end_date->toDateString());
        $this->assertSame($ok->new_end_date->toDateString(), '2027-01-09');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/paling lama 5 tahun/');
        ContractExtension::recordExtension($worker->fresh(), '2027-06-01', $this->admin->id);
    }

    // ------------------------------------------------------------- checklist

    public function test_starting_a_checklist_copies_templates_once_and_items_can_be_checked(): void
    {
        $this->assertSame(7, EmployeeChecklistItem::startFor($this->staff, 'onboarding'));
        $this->assertSame(0, EmployeeChecklistItem::startFor($this->staff, 'onboarding'), 'Tidak ada butir ganda.');
        $this->assertSame(6, EmployeeChecklistItem::startFor($this->staff, 'offboarding'));

        $item = $this->staff->checklistItems()->where('kind', 'onboarding')->first();
        $item->markDone(true, $this->admin->id, 'Sudah');
        $this->assertTrue($item->fresh()->is_done);
        $this->assertSame($this->admin->id, $item->fresh()->done_by);

        $item->markDone(false, $this->admin->id);
        $this->assertNull($item->fresh()->done_at);
    }

    public function test_start_checklist_action_in_the_panel(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(ListEmployeeChecklistItems::class)->assertSuccessful()
            ->callAction('start', ['user_id' => $this->staff->id, 'kind' => 'offboarding'])
            ->assertHasNoActionErrors();

        $this->assertSame(6, $this->staff->checklistItems()->where('kind', 'offboarding')->count());
    }

    // ------------------------------------------------------------- skala upah

    public function test_salary_outside_the_grade_range_is_flagged_and_used_grades_are_not_deletable(): void
    {
        $this->actingAs($this->admin, 'web');
        $grade = SalaryGrade::create(['name' => 'Installer Junior', 'min_salary' => 4000000, 'max_salary' => 6000000]);
        $inRange = $this->makeUser('Dalam', 'kasir', ['salary_grade_id' => $grade->id, 'base_salary' => 5000000]);
        $outRange = $this->makeUser('Luar', 'kasir', ['salary_grade_id' => $grade->id, 'base_salary' => 3000000]);

        $rows = (new EmployeeDataCompletenessService())->findings()->keyBy(fn ($r) => $r['user']->id);

        $this->assertNotContains('Gaji pokok di luar rentang golongan "Installer Junior"', $rows[$inRange->id]['recommended'] ?? []);
        $this->assertContains('Gaji pokok di luar rentang golongan "Installer Junior"', $rows[$outRange->id]['recommended']);
        $this->assertFalse(SalaryGradeResource::canDelete($grade));
        $this->assertTrue($grade->contains(5000000));
    }

    public function test_probation_ending_soon_is_flagged(): void
    {
        $soon = $this->makeUser('Percobaan', 'kasir', ['probation_end_date' => '2026-10-15']);
        $later = $this->makeUser('Lama', 'kasir', ['probation_end_date' => '2027-01-15']);

        $rows = (new EmployeeDataCompletenessService())->findings()->keyBy(fn ($r) => $r['user']->id);

        $this->assertContains('Masa percobaan berakhir 15 Oct 2026 (putuskan kelanjutannya)', $rows[$soon->id]['recommended']);
        $this->assertNotContains('Masa percobaan berakhir 15 Jan 2027 (putuskan kelanjutannya)', $rows[$later->id]['recommended'] ?? []);
    }
}
