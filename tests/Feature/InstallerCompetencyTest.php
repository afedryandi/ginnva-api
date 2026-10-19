<?php

namespace Tests\Feature;

use App\Filament\Pages\InstallerCompetencyReport;
use App\Models\Booking;
use App\Models\BookingQcResult;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianSkill;
use App\Models\TechnicianTraining;
use App\Models\User;
use App\Services\InstallerQualityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 4 (2026-11-01): matriks keterampilan installer per layanan, pelatihan/sertifikat, peringatan penugasan di Booking, dan
 * mutu pekerjaan (lulus QC pertama, pernah gagal QC) untuk pembinaan. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class InstallerCompetencyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['installer', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '08131234567']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function installer(string $name, array $skills = []): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name) . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $user->assignRole('installer');

        $technician = Technician::create(['store_id' => $this->store->id, 'user_id' => $user->id, 'name' => $name, 'level' => 'intermediate', 'status' => 'active']);

        foreach ($skills as $service => $level) {
            TechnicianSkill::create(['technician_id' => $technician->id, 'service_type' => $service, 'level' => $level, 'assessed_on' => '2026-09-01']);
        }

        return $user;
    }

    private function booking(array $over = [], array $installers = []): Booking
    {
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $this->customer->id, 'store_id' => $this->store->id,
            'service_type' => 'PPF', 'product_ppf' => true, 'product_kaca_film' => true,
            'preferred_date' => '2026-09-20', 'preferred_time' => '09:00', 'status' => 'completed',
        ], $over));

        $booking->installers()->sync(collect($installers)->pluck('id')->all());

        return $booking;
    }

    private function qc(Booking $booking, bool $passed): void
    {
        BookingQcResult::create(['booking_id' => $booking->id, 'inspector_user_id' => null, 'passed' => $passed]);
    }

    // ------------------------------------------------------------- peringatan penugasan

    public function test_warns_when_no_assigned_installer_is_independent_for_a_service(): void
    {
        $rina = $this->installer('Rina', ['kaca_film' => TechnicianSkill::INDEPENDENT, 'ppf' => TechnicianSkill::BEGINNER]);
        $booking = $this->booking([], [$rina]);

        $warnings = $booking->installerSkillWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('PPF', $warnings[0]);
    }

    public function test_one_independent_teammate_covers_the_service(): void
    {
        $junior = $this->installer('Junior', ['ppf' => TechnicianSkill::BEGINNER, 'kaca_film' => TechnicianSkill::BEGINNER]);
        $senior = $this->installer('Senior', ['ppf' => TechnicianSkill::EXPERT, 'kaca_film' => TechnicianSkill::INDEPENDENT]);

        $this->assertSame([], $this->booking([], [$junior, $senior])->installerSkillWarnings());
    }

    public function test_installers_without_a_matrix_are_not_judged(): void
    {
        $unassessed = $this->installer('Baru');

        $this->assertSame([], $this->booking([], [$unassessed])->installerSkillWarnings());
        $this->assertSame([], $this->booking([], [])->installerSkillWarnings());
    }

    // ------------------------------------------------------------- mutu

    public function test_quality_counts_first_pass_and_rework_per_installer_within_the_period(): void
    {
        $rina = $this->installer('Rina');
        $dani = $this->installer('Dani');

        $clean = $this->booking([], [$rina]);
        $this->qc($clean, true);

        $reworked = $this->booking([], [$rina, $dani]);
        $this->qc($reworked, false);
        $this->qc($reworked, true);

        $unchecked = $this->booking([], [$rina]);

        $outside = $this->booking(['preferred_date' => '2026-05-01'], [$rina]);
        $this->qc($outside, false);

        $cancelled = $this->booking(['status' => 'cancelled'], [$rina]);

        $rows = (new InstallerQualityService())->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-07'))->keyBy('name');

        $this->assertSame(['jobs' => 3, 'checked' => 2, 'first_pass' => 1, 'reworked' => 1, 'first_pass_rate' => 50.0],
            collect($rows['Rina'])->only(['jobs', 'checked', 'first_pass', 'reworked', 'first_pass_rate'])->all());
        $this->assertSame(1, $rows['Dani']['jobs']);
        $this->assertSame(0.0, (float) $rows['Dani']['first_pass']);
        $this->assertSame(1, $rows['Dani']['reworked']);
    }

    public function test_quality_rate_is_null_when_nothing_was_checked(): void
    {
        $rina = $this->installer('Rina');
        $this->booking([], [$rina]);

        $row = (new InstallerQualityService())->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-07'))->first();

        $this->assertNull($row['first_pass_rate']);
        $this->assertSame(1, $row['jobs']);
    }

    // ------------------------------------------------------------- pelatihan

    public function test_training_expiry_states(): void
    {
        $technician = Technician::create(['store_id' => $this->store->id, 'name' => 'T', 'level' => 'intermediate', 'status' => 'active']);
        $base = ['technician_id' => $technician->id, 'title' => 'Sertifikasi', 'held_on' => '2025-01-01'];

        $expired = TechnicianTraining::create($base + ['valid_until' => '2026-09-01']);
        $soon = TechnicianTraining::create($base + ['valid_until' => '2026-10-20']);
        $fine = TechnicianTraining::create($base + ['valid_until' => '2027-12-01']);
        $forever = TechnicianTraining::create($base);

        $this->assertTrue($expired->isExpired());
        $this->assertTrue($soon->expiresSoon());
        $this->assertFalse($soon->isExpired());
        $this->assertFalse($fine->expiresSoon());
        $this->assertFalse($forever->isExpired() || $forever->expiresSoon());
    }

    // ------------------------------------------------------------- laporan

    public function test_report_page_renders_matrix_expiring_certificates_and_quality(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $admin->assignRole('super_admin');
        $rina = $this->installer('Rina', ['ppf' => TechnicianSkill::EXPERT]);
        TechnicianTraining::create(['technician_id' => $rina->technician->id, 'title' => 'Sertifikasi Produsen', 'held_on' => '2025-01-01', 'valid_until' => '2026-09-01']);
        $this->qc($this->booking([], [$rina]), true);

        $this->actingAs($admin, 'web');

        Livewire::test(InstallerCompetencyReport::class)->assertSuccessful()
            ->assertSee('Rina')
            ->assertSee('Mahir (bisa melatih)')
            ->assertSee('Belum dinilai')
            ->assertSee('Sertifikasi Produsen')
            ->assertSee('Lulus QC pertama');
    }
}
