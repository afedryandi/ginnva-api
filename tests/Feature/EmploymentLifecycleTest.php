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

    // ------------------------------------------------------------- audit Peraturan & Kebijakan A: akses eksplisit

    private function policyHolder(array $permissions = [], ?Store $store = null, string $role = 'store_manager'): User
    {
        Role::findOrCreate($role, 'web');

        return $this->makeUser('Holder' . uniqid(), $role, [
            'store_id'         => ($store ?? $this->store)->id,
            'menu_access'      => [CompanyPolicyResource::class],
            'menu_permissions' => [CompanyPolicyResource::class => $permissions],
        ]);
    }

    public function test_default_accounts_cannot_open_or_manage_policies_but_a_ticked_menu_alone_is_view_only(): void
    {
        $policy = $this->policy();

        $this->actingAs($this->makeUser('Baru', 'kasir'), 'web');
        $this->assertFalse(CompanyPolicyResource::canViewAny(), 'Akun bawaan (menu_access kosong) tidak boleh membuka menu ini.');
        $this->assertFalse(CompanyPolicyResource::canCreate());
        $this->assertFalse(CompanyPolicyResource::canEdit($policy));
        $this->assertFalse(CompanyPolicyResource::canDelete($policy));

        $this->actingAs($this->policyHolder(), 'web');
        $this->assertTrue(CompanyPolicyResource::canViewAny());
        $this->assertFalse(CompanyPolicyResource::canCreate(), 'Menu dicentang saja hanya boleh melihat.');
        $this->assertFalse(CompanyPolicyResource::canEdit($policy));
        $this->assertFalse(CompanyPolicyResource::canDelete($policy));

        $this->actingAs($this->policyHolder(['create', 'update', 'delete']), 'web');
        $this->assertTrue(CompanyPolicyResource::canCreate());
        $this->assertTrue(CompanyPolicyResource::canEdit($policy));
        $this->assertTrue(CompanyPolicyResource::canDelete($policy));

        $this->actingAs($this->admin, 'web');
        $this->assertTrue(CompanyPolicyResource::canViewAny() && CompanyPolicyResource::canCreate() && CompanyPolicyResource::canEdit($policy));
    }

    public function test_store_scoped_accounts_only_see_their_own_stores_acceptance_counts_and_pending_names(): void
    {
        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $policy = $this->policy();
        $mine = $this->makeUser('Sendiri', 'kasir');
        $theirs = $this->makeUser('Lain', 'kasir', ['store_id' => $otherStore->id]);
        $policy->acknowledge($mine);
        $holder = $this->policyHolder();

        $this->actingAs($holder, 'web');
        $this->assertSame($this->store->id, CompanyPolicyResource::audienceStoreId());
        $pending = $policy->pendingUsers(CompanyPolicyResource::audienceStoreId())->pluck('id');
        $this->assertTrue($pending->contains($holder->id));
        $this->assertFalse($pending->contains($theirs->id), 'Nama karyawan toko lain tidak boleh terlihat.');
        $this->assertFalse($pending->contains($mine->id));

        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\ListCompanyPolicies::class)
            ->assertTableColumnStateSet('acknowledgments_count', '1 dari ' . CompanyPolicy::audienceQuery($this->store->id)->count(), $policy->getKey());

        $this->actingAs($this->admin, 'web');
        $this->assertNull(CompanyPolicyResource::audienceStoreId());
        $this->assertTrue($policy->pendingUsers()->pluck('id')->contains($theirs->id));
    }

    // ------------------------------------------------------------- audit Peraturan & Kebijakan B: integritas bukti penerimaan

    public function test_an_accepted_policy_cannot_be_changed_or_deleted_by_any_path_but_can_be_unpublished(): void
    {
        $policy = $this->policy(['description' => 'Ringkasan']);
        $policy->acknowledge($this->staff);

        foreach (['title' => 'Diganti', 'version' => '2.0', 'description' => 'Baru', 'file_path' => 'company-policies/lain.pdf', 'requires_acknowledgment' => false, 'category' => 'sop', 'effective_from' => '2027-01-01'] as $column => $value) {
            try {
                $policy->fresh()->update([$column => $value]);
                $this->fail("Kolom {$column} seharusnya terkunci.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('sudah diterima', $e->getMessage());
            }
        }

        try {
            $policy->fresh()->delete();
            $this->fail('Dokumen yang sudah diterima tidak boleh dihapus.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $policy->acknowledgments()->count(), 'Bukti penerimaan tetap utuh.');

        $policy->fresh()->update(['is_published' => false]);
        $this->assertFalse($policy->fresh()->is_published);
        $this->assertSame('Peraturan Perusahaan', $policy->fresh()->title);
    }

    public function test_a_policy_without_acknowledgments_stays_editable_and_deletable(): void
    {
        $policy = $this->policy(['description' => null]);
        $policy->update(['title' => 'Revisi', 'requires_acknowledgment' => false, 'description' => 'Ringkasan']);
        $this->assertSame('Revisi', $policy->fresh()->title);

        $policy->delete();
        $this->assertNull(CompanyPolicy::find($policy->id));
    }

    public function test_a_policy_cannot_be_published_without_a_pdf(): void
    {
        $draft = $this->policy(['file_path' => null, 'is_published' => false]);

        try {
            $draft->update(['is_published' => true]);
            $this->fail('Dokumen tanpa berkas tidak boleh diterbitkan.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('berkas PDF', $e->getMessage());
        }
        $this->assertFalse($draft->fresh()->is_published);

        $this->actingAs($this->admin, 'web');
        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\CreateCompanyPolicy::class)
            ->fillForm(['title' => 'SOP Baru', 'category' => 'sop', 'requires_acknowledgment' => true, 'is_published' => true])
            ->call('create')
            ->assertHasFormErrors(['file_path' => 'required']);
        $this->assertNull(CompanyPolicy::where('title', 'SOP Baru')->first());

        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\CreateCompanyPolicy::class)
            ->fillForm(['title' => 'SOP Draf', 'category' => 'sop', 'requires_acknowledgment' => true, 'is_published' => false])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertNotNull(CompanyPolicy::where('title', 'SOP Draf')->first());
    }

    public function test_the_form_locks_the_acknowledgment_flag_and_summary_once_someone_has_accepted(): void
    {
        $policy = $this->policy(['description' => 'Ringkasan']);
        $policy->acknowledge($this->staff);
        $this->actingAs($this->admin, 'web');

        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\EditCompanyPolicy::class, ['record' => $policy->getKey()])
            ->assertFormFieldIsDisabled('requires_acknowledgment')
            ->assertFormFieldIsDisabled('description')
            ->assertFormFieldIsDisabled('title')
            ->fillForm(['is_published' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($policy->fresh()->is_published);
        $this->assertTrue($policy->fresh()->requires_acknowledgment);
    }

    // ------------------------------------------------------------- audit Peraturan & Kebijakan C: pemberitahuan dan penggantian versi

    private function capturePushes(): \ArrayObject
    {
        $pushes = new \ArrayObject();
        $this->mock(\App\Services\PushNotificationService::class, function ($mock) use ($pushes) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function (array $ids, string $title, string $body, array $data = []) use ($pushes) {
                $pushes[] = [$ids, $title, $body, $data];
            });
        });

        return $pushes;
    }

    public function test_publishing_a_required_policy_notifies_active_employees_and_unpublishing_a_replaced_version(): void
    {
        $partner = $this->makeUser('Mitra', 'partner');
        $inactive = $this->makeUser('Keluar', 'kasir', ['is_active' => false]);
        $old = $this->policy(['title' => 'PP Lama', 'version' => '1.0']);
        $old->acknowledge($this->staff);
        $pushes = $this->capturePushes();
        Storage::disk('local')->put('company-policies/pp.pdf', 'x');
        $this->actingAs($this->admin, 'web');

        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\CreateCompanyPolicy::class)
            ->fillForm(['title' => 'PP Baru', 'category' => 'peraturan_perusahaan', 'version' => '2.0', 'requires_acknowledgment' => true, 'is_published' => true, 'replaces' => $old->id])
            ->call('create');

        // tanpa berkas formnya menolak; unggah lewat model lalu terbitkan lewat halaman Ubah
        $this->assertNull(CompanyPolicy::where('title', 'PP Baru')->first());
        $draft = $this->policy(['title' => 'PP Baru', 'version' => '2.0', 'is_published' => false]);

        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\EditCompanyPolicy::class, ['record' => $draft->getKey()])
            ->fillForm(['is_published' => true, 'replaces' => $old->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($old->fresh()->is_published, 'Versi lama otomatis dicabut dari terbit.');
        $this->assertSame(1, $old->acknowledgments()->count(), 'Bukti penerimaan versi lama tetap utuh.');
        $this->assertTrue($draft->fresh()->is_published);

        $this->assertCount(1, $pushes);
        [$ids, $title, $body, $data] = $pushes[0];
        $this->assertSame('Dokumen Baru Perlu Anda Terima', $title);
        $this->assertStringContainsString('PP Baru (2.0)', $body);
        $this->assertSame('/staff/policies', $data['route']);
        $this->assertTrue(in_array($this->staff->id, $ids, true));
        $this->assertFalse(in_array($partner->id, $ids, true));
        $this->assertFalse(in_array($inactive->id, $ids, true));

        // koreksi dokumen yang sudah terbit tidak mengumumkan ulang
        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\EditCompanyPolicy::class, ['record' => $draft->getKey()])
            ->fillForm(['description' => 'Koreksi'])
            ->call('save');
        $this->assertCount(1, $pushes);
    }

    public function test_a_draft_or_optional_policy_does_not_announce(): void
    {
        $pushes = $this->capturePushes();

        $this->policy(['is_published' => false])->announce();
        $this->policy(['requires_acknowledgment' => false])->announce();

        $this->assertCount(0, $pushes);
    }

    public function test_the_weekly_reminder_goes_once_per_employee_with_pending_required_policies(): void
    {
        $a = $this->policy(['title' => 'A']);
        $b = $this->policy(['title' => 'B']);
        $this->policy(['title' => 'Draf', 'is_published' => false]);
        $this->policy(['title' => 'Info', 'requires_acknowledgment' => false]);
        $a->acknowledge($this->staff);
        $b->acknowledge($this->staff);
        $b->acknowledge($this->admin);
        $pushes = $this->capturePushes();

        $this->artisan('policies:remind-pending')->assertSuccessful();

        $this->assertCount(1, $pushes, 'Hanya admin yang masih punya dokumen tertunda; staff sudah menerima semuanya.');
        $this->assertSame([$this->admin->id], $pushes[0][0]);
        $this->assertSame('Dokumen Peraturan Belum Diterima', $pushes[0][1]);
        $this->assertStringContainsString('1 dokumen', $pushes[0][2]);

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'policies:remind-pending'));
        $this->assertNotNull($event, 'Terdaftar di jadwal.');
        $this->assertSame('45 8 * * 1', $event->expression);
    }

    // ------------------------------------------------------------- audit Peraturan & Kebijakan D: angka penerimaan dan tombol Ingatkan

    public function test_acceptance_count_ignores_inactive_and_partner_acceptances_and_never_exceeds_the_total(): void
    {
        $policy = $this->policy();
        $partner = $this->makeUser('Mitra', 'partner');
        $left = $this->makeUser('Keluar', 'kasir');
        $policy->acknowledge($this->staff);
        $policy->acknowledge($partner);
        $policy->acknowledge($left);
        $left->update(['is_active' => false]);
        $this->actingAs($this->admin, 'web');

        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\ListCompanyPolicies::class)
            ->assertTableColumnStateSet('acknowledgments_count', '1 dari 2', $policy->getKey());
    }

    public function test_the_policy_list_runs_a_fixed_number_of_queries_regardless_of_row_count(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->policy();

        $count = function () {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\ListCompanyPolicies::class);

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        };

        $count();
        $few = $count();
        foreach (range(1, 6) as $i) {
            $this->policy(['title' => "Dokumen {$i}"]);
        }

        $this->assertSame($few, $count(), 'Jumlah query tidak boleh bertambah per baris.');
    }

    public function test_the_remind_action_notifies_only_pending_active_employees_and_respects_store_scope(): void
    {
        $otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $policy = $this->policy();
        $theirs = $this->makeUser('Lain', 'kasir', ['store_id' => $otherStore->id]);
        $policy->acknowledge($this->staff);
        $pushes = $this->capturePushes();

        $this->actingAs($this->admin, 'web');
        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\ListCompanyPolicies::class)
            ->callTableAction('remind', $policy)
            ->assertNotified();
        $this->assertCount(1, $pushes);
        $this->assertSame('Pengingat Menerima Dokumen', $pushes[0][1]);
        $this->assertTrue(in_array($theirs->id, $pushes[0][0], true));
        $this->assertFalse(in_array($this->staff->id, $pushes[0][0], true), 'Yang sudah menerima tidak diingatkan.');

        $holder = $this->policyHolder(['update']);
        $this->actingAs($holder, 'web');
        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\ListCompanyPolicies::class)
            ->callTableAction('remind', $policy);
        $this->assertCount(2, $pushes);
        $this->assertFalse(in_array($theirs->id, $pushes[1][0], true), 'Akun satu toko hanya mengingatkan tokonya sendiri.');

        $viewOnly = $this->policyHolder([]);
        $this->actingAs($viewOnly, 'web');
        Livewire::test(\App\Filament\Resources\CompanyPolicyResource\Pages\ListCompanyPolicies::class)
            ->assertTableActionHidden('remind', $policy);
    }

    // ------------------------------------------------------------- audit Peraturan & Kebijakan E: mobile

    public function test_an_unpublished_policy_stays_listed_only_for_employees_who_accepted_it(): void
    {
        $old = $this->policy(['title' => 'PP Lama']);
        $old->acknowledge($this->staff);
        $old->update(['is_published' => false]);
        $new = $this->policy(['title' => 'PP Baru']);

        $mine = collect($this->actingAs($this->staff, 'api')->getJson('/api/staff/policies')->assertSuccessful()->json('policies'))->keyBy('id');
        $this->assertTrue($mine->has($old->id), 'Yang sudah menerima tetap bisa membuka versi lama.');
        $this->assertFalse($mine[$old->id]['is_published']);
        $this->assertTrue($mine[$new->id]['is_published']);

        $others = collect($this->actingAs($this->admin, 'api')->getJson('/api/staff/policies')->json('policies'))->pluck('id');
        $this->assertFalse($others->contains($old->id), 'Yang belum pernah menerima tidak melihat versi yang dicabut.');
        $this->actingAs($this->admin, 'api')->postJson("/api/staff/policies/{$old->id}/acknowledge")->assertStatus(404);
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
