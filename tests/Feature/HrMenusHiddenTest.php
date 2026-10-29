<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingReworkResource;
use App\Filament\Resources\EmployeeTrainingResource;
use App\Filament\Resources\PerformanceReviewResource;
use App\Filament\Resources\PpeIssuanceResource;
use App\Filament\Resources\PpeTypeResource;
use App\Filament\Resources\SafetyIncidentResource;
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
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keputusan pemilik 2026-10-29: Penilaian Kinerja, Pelatihan Karyawan, dan K3 (Jenis APD, Pembagian APD, Kejadian K3) disembunyikan lewat
 * saklar FEATURE_PERFORMANCE_REVIEWS, FEATURE_EMPLOYEE_TRAINING, dan FEATURE_K3 (config/features.php). Tes lain berjalan dengan saklar menyala
 * (phpunit.xml). Kode dan data tetap ada.
 */
class HrMenusHiddenTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->admin = $this->user('Admin', 'super_admin');
        $this->staff = $this->user('Rina', 'kasir');
        PpeType::ensureDefaults();
    }

    private function user(string $name, string $role): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]), fn (User $u) => $u->assignRole($role));
    }

    private function off(): void
    {
        config(['features.k3' => false, 'features.performance_reviews' => false, 'features.employee_training' => false]);
    }

    public function test_the_five_menus_are_closed_even_to_full_access_when_their_switches_are_off(): void
    {
        $this->off();
        $this->actingAs($this->admin, 'web');

        foreach ([PerformanceReviewResource::class, EmployeeTrainingResource::class, PpeTypeResource::class, PpeIssuanceResource::class, SafetyIncidentResource::class] as $resource) {
            $this->assertFalse($resource::canViewAny(), "{$resource} seharusnya tertutup.");
        }

        $this->assertTrue(BookingReworkResource::canViewAny(), 'Pekerjaan Ulang tetap terbuka.');
    }

    public function test_each_switch_opens_only_its_own_menus(): void
    {
        $this->off();
        $this->actingAs($this->admin, 'web');

        config(['features.k3' => true]);
        $this->assertTrue(PpeTypeResource::canViewAny());
        $this->assertTrue(PpeIssuanceResource::canViewAny());
        $this->assertTrue(SafetyIncidentResource::canViewAny());
        $this->assertFalse(PerformanceReviewResource::canViewAny());
        $this->assertFalse(EmployeeTrainingResource::canViewAny());

        $this->off();
        config(['features.performance_reviews' => true]);
        $this->assertTrue(PerformanceReviewResource::canViewAny());
        $this->assertFalse(PpeTypeResource::canViewAny());

        $this->off();
        config(['features.employee_training' => true]);
        $this->assertTrue(EmployeeTrainingResource::canViewAny());
        $this->assertFalse(SafetyIncidentResource::canViewAny());
    }

    public function test_the_staff_app_gets_empty_lists_and_cannot_acknowledge_a_review(): void
    {
        $review = PerformanceReview::create([
            'user_id' => $this->staff->id, 'period_label' => '2026 S1', 'reviewed_on' => '2026-10-01', 'reviewer_id' => $this->admin->id,
            'score_quality' => 4, 'score_discipline' => 4, 'score_teamwork' => 4, 'score_initiative' => 4,
        ]);
        EmployeeTraining::create(['user_id' => $this->staff->id, 'category' => 'k3', 'title' => 'K3 Dasar', 'held_on' => '2026-01-10']);
        PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => PpeType::first()->id, 'issued_on' => '2026-10-01']);
        $this->off();

        $response = $this->actingAs($this->staff, 'api')->getJson('/api/staff/safety')->assertSuccessful();

        $this->assertSame([], $response->json('ppe'));
        $this->assertSame([], $response->json('trainings'));
        $this->assertSame([], $response->json('reviews'));

        $this->actingAs($this->staff, 'api')->postJson("/api/staff/performance-reviews/{$review->id}/acknowledge")->assertStatus(404);
        $this->assertNull($review->fresh()->acknowledged_at);
    }

    public function test_the_app_menu_flag_follows_the_switches(): void
    {
        $this->off();
        $this->actingAs($this->staff, 'api')->getJson('/api/staff/auth/me')->assertSuccessful()->assertJsonPath('user.has_safety_access', false);

        config(['features.employee_training' => true]);
        $this->actingAs($this->staff, 'api')->getJson('/api/staff/auth/me')->assertSuccessful()->assertJsonPath('user.has_safety_access', true);
    }

    public function test_the_hr_dashboard_does_not_count_hidden_items(): void
    {
        PpeIssuance::create(['user_id' => $this->staff->id, 'ppe_type_id' => PpeType::first()->id, 'issued_on' => '2026-01-01', 'replace_due_on' => '2026-02-01']);
        SafetyIncident::create([
            'store_id' => $this->store->id, 'occurred_at' => now()->subDay(), 'type' => 'injury', 'severity' => 'ringan', 'user_id' => $this->staff->id,
            'description' => 'Jari tergores', 'status' => 'open', 'reported_by' => $this->admin->id,
        ]);

        $on = app(HrDashboardService::class)->summary(null);
        $this->assertSame(1, $on['ppe_overdue']);
        $this->assertSame(1, $on['open_incidents']);
        $this->assertSame(1, $on['open_injuries']);

        $this->off();
        $off = app(HrDashboardService::class)->summary(null);
        $this->assertSame(0, $off['ppe_overdue']);
        $this->assertSame(0, $off['open_incidents']);
        $this->assertSame(0, $off['open_injuries']);
        $this->assertSame(0, $off['not_reviewed']);
    }

    public function test_hr_dashboard_and_completeness_report_need_full_access_or_an_explicit_menu_checkbox(): void
    {
        $this->actingAs($this->staff, 'web');
        $this->assertNull($this->staff->menu_access);
        $this->assertFalse(\App\Filament\Pages\HrDashboard::canAccess(), 'Akun dengan akses bawaan tidak boleh melihat data personalia.');
        $this->assertFalse(\App\Filament\Pages\EmployeeDataCompletenessReport::canAccess());

        $this->staff->update(['menu_access' => ['BookingResource']]);
        $this->actingAs($this->staff->fresh(), 'web');
        $this->assertFalse(\App\Filament\Pages\HrDashboard::canAccess(), 'Dibatasi ke modul lain.');

        $this->staff->update(['menu_access' => ['HrDashboard', 'EmployeeDataCompletenessReport']]);
        $this->actingAs($this->staff->fresh(), 'web');
        $this->assertTrue(\App\Filament\Pages\HrDashboard::canAccess());
        $this->assertTrue(\App\Filament\Pages\EmployeeDataCompletenessReport::canAccess());

        $this->actingAs($this->admin, 'web');
        $this->assertTrue(\App\Filament\Pages\HrDashboard::canAccess());
    }
}
