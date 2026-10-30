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
        foreach (['installer', 'super_admin', 'store_manager', 'kasir'] as $role) {
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

    public function test_the_report_is_for_full_access_store_managers_and_explicitly_granted_staff(): void
    {
        $make = function (string $role, array $extra = []) {
            $user = User::create($extra + ['name' => ucfirst($role) . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
            $user->assignRole($role);

            return $user;
        };

        $this->actingAs($make('kasir'), 'web');
        $this->assertFalse(InstallerCompetencyReport::canAccess(), 'Akun bawaan tidak melihat mutu per installer.');

        $this->actingAs($make('kasir', ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(InstallerCompetencyReport::canAccess());

        $this->actingAs($make('kasir', ['menu_access' => [InstallerCompetencyReport::class]]), 'web');
        $this->assertTrue(InstallerCompetencyReport::canAccess(), 'Menu dicentang eksplisit.');

        $this->actingAs($make('store_manager'), 'web');
        $this->assertTrue(InstallerCompetencyReport::canAccess());

        $this->actingAs($make('store_manager', ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(InstallerCompetencyReport::canAccess(), 'Daftar menu yang dibatasi tetap dihormati.');

        $this->actingAs($make('super_admin'), 'web');
        $this->assertTrue(InstallerCompetencyReport::canAccess());
    }

    public function test_a_renewed_certificate_no_longer_counts_as_expired_in_the_report_or_the_hr_dashboard(): void
    {
        $rina = $this->installer('Rina');
        $budi = $this->installer('Budi');
        $make = fn ($user, string $title, string $until, ?string $service = null) => TechnicianTraining::create([
            'technician_id' => $user->technician->id, 'title' => $title, 'service_type' => $service, 'held_on' => '2025-01-01', 'valid_until' => $until,
        ]);

        // Rina memperbarui sertifikatnya: catatan lama (kedaluwarsa Agustus) digantikan catatan baru (berlaku sampai 2027).
        $make($rina, 'Sertifikasi Produsen', '2026-08-01');
        $make($rina, 'sertifikasi produsen ', '2027-08-01');
        // Budi belum memperbarui: tetap kedaluwarsa. Sertifikat lain Rina (judul beda) yang kedaluwarsa tetap dihitung.
        $make($budi, 'Sertifikasi Produsen', '2026-08-01');
        $make($rina, 'Pelatihan K3', '2026-09-01');
        // Judul sama tetapi layanan beda bukan pengganti.
        $make($budi, 'Sertifikasi Layanan', '2026-09-10', 'ppf');
        $make($budi, 'Sertifikasi Layanan', '2027-09-10', 'wf');

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        $page = Livewire::test(InstallerCompetencyReport::class)->instance();
        $rows = $page->getExpiringTrainings($page->getTechnicians());
        $labels = $rows->map(fn (array $row) => $row['technician']->name . ' - ' . $row['training']->title)->sort()->values()->all();

        $this->assertSame(['Budi - Sertifikasi Layanan', 'Budi - Sertifikasi Produsen', 'Rina - Pelatihan K3'], $labels);

        // Dasbor HR: periode 12 bulan ke belakang, jadi tiga catatan di atas yang dihitung (bukan catatan Rina yang sudah diganti).
        $this->assertSame(3, app(\App\Services\HrDashboardService::class)->summary()['trainings_expiring']);
    }

    // ------------------------------------------------------------- mutu: sampel kecil, urut, rincian

    private function adminPage()
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-' . uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return Livewire::test(InstallerCompetencyReport::class);
    }

    public function test_a_percentage_from_fewer_than_five_checked_jobs_is_flagged_as_a_small_sample(): void
    {
        $few = $this->installer('Few');
        $many = $this->installer('Many');
        $none = $this->installer('None');
        foreach (range(1, 2) as $i) {
            $this->qc($this->booking([], [$few]), true);
        }
        foreach (range(1, 5) as $i) {
            $this->qc($this->booking([], [$many]), true);
        }
        $this->booking([], [$none]);   // tidak pernah diperiksa QC

        $rows = (new InstallerQualityService())->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-07'))->keyBy('name');

        $this->assertTrue($rows['Few']['small_sample']);
        $this->assertFalse($rows['Many']['small_sample']);
        $this->assertFalse($rows['None']['small_sample'], 'Tanpa persentase tidak perlu label sampel kecil.');
    }

    public function test_the_quality_table_can_be_sorted_by_lowest_first_pass_rate_or_most_rework(): void
    {
        $good = $this->installer('Alfa');
        $bad = $this->installer('Beta');
        $unchecked = $this->installer('Gamma');
        $this->qc($this->booking([], [$good]), true);
        $this->qc($this->booking([], [$good]), true);
        $this->qc($this->booking([], [$bad]), false);
        $this->qc($this->booking([], [$bad]), true);
        $this->booking([], [$unchecked]);

        $page = $this->adminPage();
        $names = fn () => $page->instance()->getQuality()->pluck('name')->all();

        $this->assertSame(['Alfa', 'Beta', 'Gamma'], $names(), 'Bawaan: urut nama.');

        $page->call('sortQuality', 'rate');
        $this->assertSame(['Beta', 'Alfa', 'Gamma'], $names(), 'Lulus terendah dulu, yang belum diperiksa di akhir.');

        $page->call('sortQuality', 'rework');
        $this->assertSame('Beta', $names()[0], 'Pekerjaan ulang terbanyak dulu.');

        $page->call('sortQuality', 'sembarang');
        $this->assertSame('name', $page->instance()->qualitySort);
    }

    public function test_the_job_breakdown_puts_failed_qc_and_rework_first_with_their_notes(): void
    {
        $rina = $this->installer('Rina');
        $clean = $this->booking(['preferred_date' => '2026-09-25'], [$rina]);
        $this->qc($clean, true);
        $failedThenFixed = $this->booking(['preferred_date' => '2026-09-10'], [$rina]);
        $this->qc($failedThenFixed, false);
        $this->qc($failedThenFixed, true);
        $complaint = $this->booking(['preferred_date' => '2026-09-15'], [$rina]);
        $this->qc($complaint, true);
        \App\Models\BookingRework::create(['booking_id' => $complaint->id, 'source' => 'keluhan_pelanggan', 'reason' => 'Gelembung di kaca depan', 'reported_on' => '2026-09-18']);
        $unchecked = $this->booking(['preferred_date' => '2026-09-28'], [$rina]);
        $this->booking([], [$this->installer('Lain')]);

        $jobs = (new InstallerQualityService())->jobsFor($rina->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-07'));

        $this->assertCount(4, $jobs, 'Hanya booking milik Rina.');
        $this->assertSame([$complaint->id, $failedThenFixed->id], $jobs->take(2)->pluck('booking_id')->all(), 'Yang perlu perhatian di atas, terbaru dulu.');
        $this->assertSame('Lulus (pernah gagal)', $jobs->firstWhere('booking_id', $failedThenFixed->id)['qc']);
        $this->assertSame(['Keluhan pelanggan: Gelembung di kaca depan'], $jobs->firstWhere('booking_id', $complaint->id)['reworks']);
        $this->assertSame('Belum diperiksa', $jobs->firstWhere('booking_id', $unchecked->id)['qc']);
        $this->assertSame('Lulus pertama', $jobs->firstWhere('booking_id', $clean->id)['qc']);
        $this->assertFalse($jobs->firstWhere('booking_id', $clean->id)['attention']);
    }

    public function test_the_page_opens_an_installers_breakdown_and_refuses_one_that_is_not_in_the_table(): void
    {
        $rina = $this->installer('Rina');
        $booking = $this->booking(['preferred_date' => '2026-09-10'], [$rina]);
        $this->qc($booking, false);
        $this->qc($booking, true);
        $outsider = $this->installer('Luar');

        $page = $this->adminPage();
        $page->set('data.from', '2026-09-01')->set('data.to', '2026-10-07');

        $page->assertSee('Sampel kecil')
            ->assertDontSee($booking->booking_number)
            ->call('toggleQualityJobs', $rina->id)
            ->assertSee($booking->booking_number)
            ->assertSee('Lulus (pernah gagal)')
            ->call('toggleQualityJobs', $rina->id)
            ->assertDontSee($booking->booking_number);

        $page->call('toggleQualityJobs', $outsider->id);
        $this->assertNull($page->instance()->expandedUserId, 'Installer tanpa pekerjaan di periode ini tidak ada di tabel.');
    }

    public function test_quality_lists_only_registered_installers_and_marks_inactive_ones_who_worked(): void
    {
        $rina = $this->installer('Rina');
        $leaver = $this->installer('Eko Keluar');
        $leaver->technician->update(['status' => 'inactive']);
        $manager = User::create(['name' => 'Manajer Toko', 'email' => 'mgr@test.local', 'password' => 'x', 'store_id' => $this->store->id]);   // bukan installer terdaftar

        $this->qc($this->booking([], [$rina, $leaver, $manager]), true);
        $this->booking(['preferred_date' => '2026-05-01'], [$leaver]);   // di luar periode

        $rows = (new InstallerQualityService())->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-07'))->keyBy('name');

        $this->assertEqualsCanonicalizing(['Rina', 'Eko Keluar'], $rows->keys()->all(), 'Akun yang bukan installer terdaftar tidak ikut.');
        $this->assertTrue($rows['Rina']['is_active']);
        $this->assertFalse($rows['Eko Keluar']['is_active']);
        $this->assertSame('Toko A', $rows['Rina']['store']);

        $this->assertSame([], (new InstallerQualityService())->forPeriod(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'))->all(), 'Tanpa pekerjaan di periode, installer nonaktif tidak muncul.');

        $page = $this->adminPage();
        $page->set('data.from', '2026-09-01')->set('data.to', '2026-10-07')->assertSee('Eko Keluar')->assertSee('Nonaktif')->assertDontSee('Manajer Toko');
    }
}
