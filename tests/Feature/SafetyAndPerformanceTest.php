<?php

namespace Tests\Feature;

use App\Filament\Pages\HrDashboard;
use App\Filament\Resources\PerformanceReviewResource;
use App\Filament\Resources\PpeIssuanceResource\Pages\ListPpeIssuances;
use App\Filament\Resources\PpeTypeResource;
use App\Filament\Resources\PpeTypeResource\Pages\ListPpeTypes;
use App\Filament\Resources\SafetyIncidentResource;
use App\Filament\Resources\SafetyIncidentResource\Pages\CreateSafetyIncident;
use App\Models\CompanyPolicy;
use App\Models\EmployeeTraining;
use App\Models\PerformanceReview;
use App\Models\PpeIssuance;
use App\Models\PpeType;
use App\Models\SafetyIncident;
use App\Models\Store;
use App\Models\User;
use App\Services\HrDashboardService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 5 (2026-11-02): APD, pelatihan karyawan, kejadian K3, penilaian kinerja, API milik sendiri, dan Dasbor HR.
 * Waktu dibekukan: Rabu 7 Okt 2026.
 */
class SafetyAndPerformanceTest extends TestCase
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
        foreach (['kasir', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->staff = $this->makeUser('Rina', 'kasir');
        $this->admin = $this->makeUser('Admin', 'super_admin');
        PpeType::ensureDefaults();
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

    private function review(User $user, array $over = []): PerformanceReview
    {
        return PerformanceReview::create(array_merge([
            'user_id' => $user->id, 'period_label' => '2026 S1', 'reviewed_on' => '2026-07-01', 'reviewer_id' => $this->admin->id,
            'score_quality' => 4, 'score_discipline' => 5, 'score_teamwork' => 3, 'score_initiative' => 4,
        ], $over));
    }

    // ------------------------------------------------------------- APD

    public function test_ppe_due_date_and_store_are_filled_automatically(): void
    {
        $gloves = PpeType::where('name', 'Sarung tangan')->first(); // 1 bulan
        $issuance = PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => $gloves->id, 'issued_on' => '2026-08-01']);

        $this->assertSame('2026-09-01', $issuance->replace_due_on->toDateString());
        $this->assertSame($this->store->id, $issuance->store_id);
        $this->assertTrue($issuance->isOverdue());

        $issuance->update(['returned_on' => '2026-09-02']);
        $this->assertFalse($issuance->fresh()->isOverdue(), 'Sudah dikembalikan: tidak lagi jatuh tempo.');

        $explicit = PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => $gloves->id, 'issued_on' => '2026-10-01', 'replace_due_on' => '2026-12-31']);
        $this->assertSame('2026-12-31', $explicit->replace_due_on->toDateString());
    }

    public function test_ppe_type_in_use_cannot_be_deleted_and_defaults_are_idempotent(): void
    {
        $this->actingAs($this->admin, 'web');
        $used = PpeType::where('name', 'Masker/respirator')->first();
        PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => $used->id, 'issued_on' => '2026-10-01']);

        $this->assertFalse(PpeTypeResource::canDelete($used));
        $this->assertTrue(PpeTypeResource::canDelete(PpeType::where('name', 'Seragam kerja')->first()));

        $count = PpeType::count();
        PpeType::where('name', 'Sarung tangan')->update(['replace_after_months' => 2]);
        Livewire::test(ListPpeTypes::class)->assertSuccessful();
        $this->assertSame($count, PpeType::count());
        $this->assertSame(2, PpeType::where('name', 'Sarung tangan')->value('replace_after_months'));
    }

    public function test_ppe_list_and_overdue_filter(): void
    {
        $this->actingAs($this->admin, 'web');
        $gloves = PpeType::where('name', 'Sarung tangan')->first();
        $overdue = PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => $gloves->id, 'issued_on' => '2026-08-01']);
        $fresh = PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => $gloves->id, 'issued_on' => '2026-10-05']);

        Livewire::test(ListPpeIssuances::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$overdue, $fresh])
            ->filterTable('overdue')
            ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$fresh]);
    }

    // ------------------------------------------------------------- kejadian K3

    public function test_incident_needs_a_follow_up_before_closing_and_is_never_deletable(): void
    {
        $this->actingAs($this->admin, 'web');
        $incident = SafetyIncident::create([
            'store_id' => $this->store->id, 'occurred_at' => '2026-10-06 14:00', 'type' => 'injury', 'severity' => 'ringan',
            'user_id' => $this->staff->id, 'description' => 'Tangan tergores cutter',
        ]);

        try {
            $incident->close();
            $this->fail('Seharusnya ditolak tanpa tindak lanjut.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tindak lanjut', $e->getMessage());
        }
        $this->assertTrue($incident->fresh()->isOpen());

        $incident->update(['follow_up' => 'Sarung tangan anti-sayat wajib']);
        $incident->close();

        $this->assertSame('closed', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->closed_at);
        $this->assertFalse(SafetyIncidentResource::canDelete($incident));
    }

    public function test_creating_an_incident_in_the_panel_records_the_reporter_and_opens_it(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(CreateSafetyIncident::class)
            ->fillForm([
                'store_id' => $this->store->id, 'occurred_at' => '2026-10-06 14:00', 'type' => 'near_miss', 'severity' => 'ringan',
                'description' => 'Tangga hampir tergelincir',
            ])
            ->call('create')->assertHasNoFormErrors();

        $incident = SafetyIncident::firstOrFail();
        $this->assertSame('open', $incident->status);
        $this->assertSame($this->admin->id, $incident->reported_by);
    }

    // ------------------------------------------------------------- penilaian

    public function test_review_average_unique_period_and_delete_lock(): void
    {
        $this->actingAs($this->admin, 'web');
        $review = $this->review($this->staff);

        $this->assertSame(4.0, $review->average());
        $this->assertTrue(PerformanceReviewResource::canDelete($review));

        $review->update(['acknowledged_at' => now()]);
        $this->assertFalse(PerformanceReviewResource::canDelete($review->fresh()));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->review($this->staff); // periode sama untuk karyawan yang sama
    }

    public function test_employee_reads_only_own_data_and_acknowledges_a_review_once(): void
    {
        $mine = $this->review($this->staff);
        $other = $this->review($this->admin, ['period_label' => '2026 S1']);
        EmployeeTraining::create(['user_id' => $this->staff->id, 'category' => 'k3', 'title' => 'K3 Dasar', 'held_on' => '2026-01-10', 'valid_until' => '2026-10-20']);
        EmployeeTraining::create(['user_id' => $this->admin->id, 'category' => 'k3', 'title' => 'Bukan milik Rina', 'held_on' => '2026-01-10']);
        PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => PpeType::first()->id, 'issued_on' => '2026-10-01']);

        $response = $this->actingAs($this->staff, 'api')->getJson('/api/staff/safety')->assertSuccessful();

        $this->assertCount(1, $response->json('reviews'));
        $this->assertSame($mine->id, $response->json('reviews.0.id'));
        $this->assertSame(4.0, (float) $response->json('reviews.0.average'));
        $this->assertCount(1, $response->json('trainings'));
        $this->assertTrue($response->json('trainings.0.expires_soon'));
        $this->assertCount(1, $response->json('ppe'));

        $first = $this->actingAs($this->staff, 'api')->postJson("/api/staff/performance-reviews/{$mine->id}/acknowledge")->assertSuccessful()->json('acknowledged_at');
        Carbon::setTestNow('2026-10-08 09:00:00');
        $second = $this->actingAs($this->staff, 'api')->postJson("/api/staff/performance-reviews/{$mine->id}/acknowledge")->assertSuccessful()->json('acknowledged_at');
        $this->assertSame($first, $second);

        $this->actingAs($this->staff, 'api')->postJson("/api/staff/performance-reviews/{$other->id}/acknowledge")->assertStatus(404);
    }

    public function test_safety_endpoint_requires_login(): void
    {
        $this->getJson('/api/staff/safety')->assertStatus(401);
    }

    // ------------------------------------------------------------- Dasbor HR

    public function test_hr_dashboard_counts_what_needs_attention(): void
    {
        CompanyPolicy::create(['title' => 'Peraturan Perusahaan', 'category' => 'peraturan_perusahaan', 'is_published' => true, 'requires_acknowledgment' => true]);
        $contractType = \App\Models\EmployeeType::create(['name' => 'Kontrak', 'has_end_date' => true, 'is_active' => true]);
        $this->staff->update(['employee_type_id' => $contractType->id, 'contract_end_date' => '2026-10-20', 'probation_end_date' => '2026-10-12']);
        PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => PpeType::where('name', 'Sarung tangan')->value('id'), 'issued_on' => '2026-08-01']);
        SafetyIncident::create(['store_id' => $this->store->id, 'occurred_at' => '2026-10-06 10:00', 'type' => 'injury', 'severity' => 'sedang', 'description' => 'Cedera']);
        $this->review($this->staff);

        $summary = (new HrDashboardService())->summary();

        $this->assertSame(2, $summary['headcount']);
        $this->assertSame(['Toko A' => 2], $summary['by_store']);
        $this->assertSame([['id' => $this->staff->id, 'name' => 'Rina', 'date' => '20 Oct 2026']], $summary['contracts_expiring']);
        $this->assertSame([['id' => $this->staff->id, 'name' => 'Rina', 'date' => '12 Oct 2026']], $summary['probation_ending']);
        $this->assertSame([['title' => 'Peraturan Perusahaan', 'pending' => 2]], $summary['policies_pending']);
        $this->assertSame(1, $summary['ppe_overdue']);
        $this->assertSame(1, $summary['open_incidents']);
        $this->assertSame(1, $summary['open_injuries']);
        $this->assertSame(1, $summary['not_reviewed'], 'Admin belum pernah dinilai; Rina sudah.');
    }

    public function test_hr_dashboard_page_renders(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(HrDashboard::class)->assertSuccessful()
            ->assertSee('Perlu tindakan')
            ->assertSee('Karyawan aktif: 2')
            ->assertSeeHtml('tableFilters%5Bcontract_status%5D%5Bvalue%5D=expired')
            ->assertSeeHtml('tableFilters%5Bstatus%5D%5Bvalue%5D=pending');
    }

    public function test_hr_dashboard_counts_pending_attendance_corrections(): void
    {
        $make = fn (string $status) => \App\Models\AttendanceCorrectionRequest::create([
            'user_id' => $this->staff->id, 'store_id' => $this->store->id, 'date' => today()->subDay()->toDateString(), 'entry_type' => 'manual',
            'reason' => 'Lupa absen', 'status' => $status, 'requested_by' => $this->staff->id,
        ]);
        $make('pending');
        $make('pending');
        $make('approved');

        $this->assertSame(2, (new HrDashboardService())->summary()['pending_corrections']);

        $this->actingAs($this->admin, 'web');
        Livewire::test(HrDashboard::class)->assertSee('Koreksi absensi menunggu keputusan');
    }

    public function test_hr_dashboard_names_link_to_the_employee_account(): void
    {
        $this->staff->update(['contract_end_date' => today()->addDays(5)->toDateString()]);
        $this->actingAs($this->admin, 'web');

        Livewire::test(HrDashboard::class)
            ->assertSeeHtml(\App\Filament\Resources\UserResource::getUrl('edit', ['record' => $this->staff->id]));
    }
}
