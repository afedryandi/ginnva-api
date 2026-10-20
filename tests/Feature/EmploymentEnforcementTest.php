<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingReworkResource\Pages\ListBookingReworks;
use App\Models\Booking;
use App\Models\BookingRework;
use App\Models\CompanyPolicy;
use App\Models\Customer;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianSkill;
use App\Models\User;
use App\Models\WarningLetter;
use App\Services\InstallerAvailabilityService;
use App\Services\InstallerQualityService;
use App\Services\InstallerSkillService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lanjutan Karyawan (2026-11-03): saran tingkat SP berjenjang, peringatan payroll tanpa Peraturan Perusahaan, pekerjaan ulang pada booking,
 * dan saran installer. Semuanya SARAN/PERINGATAN, tidak memblokir. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class EmploymentEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Customer $customer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['installer', 'super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Budi', 'phone_number' => '08131234567']);
        $this->admin = $this->user('Admin', 'super_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name) . uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'join_date' => '2024-01-01']);
        $user->assignRole($role);

        return $user;
    }

    private function installer(string $name, array $skills = []): User
    {
        $user = $this->user($name, 'installer');
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
            'preferred_date' => '2026-10-12', 'preferred_time' => '09:00', 'status' => 'pending',
        ], $over));

        $booking->installers()->sync(collect($installers)->pluck('id')->all());

        return $booking;
    }

    private function sp(User $user, string $level, string $issued, ?string $validUntil = null): WarningLetter
    {
        return WarningLetter::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'level' => $level, 'reason' => 'x', 'issued_date' => $issued] + ($validUntil ? ['valid_until' => $validUntil] : []));
    }

    // ------------------------------------------------------------- SP berjenjang

    public function test_suggested_level_climbs_with_active_letters_and_resets_after_expiry(): void
    {
        $worker = $this->user('Rina', 'kasir');

        $this->assertSame('sp1', WarningLetter::suggestedLevelFor($worker->id));

        $this->sp($worker, 'sp1', '2026-08-01'); // otomatis berlaku sampai 2027-02-01
        $this->assertSame('sp2', WarningLetter::suggestedLevelFor($worker->id));

        $this->sp($worker, 'sp2', '2026-09-01');
        $this->assertSame('sp3', WarningLetter::suggestedLevelFor($worker->id));

        $this->sp($worker, 'sp3', '2026-10-01');
        $this->assertSame('sp3', WarningLetter::suggestedLevelFor($worker->id), 'Maksimal SP3.');

        // Setelah semua masa berlaku habis, pelanggaran baru kembali ke SP1.
        $this->assertSame('sp1', WarningLetter::suggestedLevelFor($worker->id, Carbon::parse('2027-12-01')));
    }

    public function test_an_expired_letter_does_not_count_and_level_number_parses(): void
    {
        $worker = $this->user('Rina', 'kasir');
        $this->sp($worker, 'sp2', '2026-01-01', '2026-06-30');

        $this->assertCount(0, WarningLetter::activeFor($worker->id));
        $this->assertSame('sp1', WarningLetter::suggestedLevelFor($worker->id));
        $this->assertSame(3, WarningLetter::levelNumber('sp3'));
        $this->assertSame(0, WarningLetter::levelNumber(null));
    }

    // ------------------------------------------------------------- payroll tanpa PP

    public function test_payroll_warning_when_no_company_regulation_is_published_or_not_accepted(): void
    {
        $a = $this->user('Rina', 'kasir');
        $b = $this->user('Budi', 'kasir');
        $users = collect([$a, $b]);

        $this->assertStringContainsString('Belum ada Peraturan Perusahaan yang terbit', CompanyPolicy::payrollDeductionWarning($users));

        // Dokumen selain Peraturan Perusahaan tidak dihitung.
        CompanyPolicy::create(['title' => 'SOP', 'category' => 'sop', 'is_published' => true, 'requires_acknowledgment' => true]);
        $this->assertStringContainsString('Belum ada Peraturan Perusahaan', CompanyPolicy::payrollDeductionWarning($users));

        $policy = CompanyPolicy::create(['title' => 'PP 2026', 'category' => 'peraturan_perusahaan', 'is_published' => true, 'requires_acknowledgment' => true]);
        $policy->acknowledge($a);

        $warning = CompanyPolicy::payrollDeductionWarning($users);
        $this->assertStringContainsString('Budi', $warning);
        $this->assertStringNotContainsString('Rina', $warning);

        $policy->acknowledge($b);
        $this->assertNull(CompanyPolicy::payrollDeductionWarning($users));
    }

    // ------------------------------------------------------------- pekerjaan ulang

    public function test_rework_takes_the_store_from_the_booking_and_counts_in_quality(): void
    {
        $rina = $this->installer('Rina');
        $clean = $this->booking(['status' => 'completed', 'preferred_date' => '2026-09-20'], [$rina]);
        $problem = $this->booking(['status' => 'completed', 'preferred_date' => '2026-09-21'], [$rina]);

        $rework = BookingRework::create(['booking_id' => $problem->id, 'source' => 'keluhan_pelanggan', 'reason' => 'Gelembung di kaca belakang', 'reported_on' => '2026-09-25']);

        $this->assertSame($this->store->id, $rework->store_id);
        $this->assertTrue($rework->isOpen());
        $rework->update(['resolved_on' => '2026-09-27']);
        $this->assertFalse($rework->fresh()->isOpen());

        $row = (new InstallerQualityService())->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-07'))->first();

        $this->assertSame(2, $row['jobs']);
        $this->assertSame(1, $row['rework_recorded']);
        $this->assertSame(0, $row['reworked'], 'QC tidak pernah gagal; hanya pekerjaan ulang dicatat.');
        $this->assertNotNull($clean);
    }

    public function test_rework_list_renders_for_admin(): void
    {
        $this->actingAs($this->admin, 'web');
        $problem = $this->booking(['status' => 'completed'], [$this->installer('Rina')]);
        $rework = BookingRework::create(['booking_id' => $problem->id, 'source' => 'qc_gagal', 'reason' => 'Tepi film terangkat', 'reported_on' => '2026-10-01']);

        Livewire::test(ListBookingReworks::class)->assertSuccessful()->assertCanSeeTableRecords([$rework]);
    }

    // ------------------------------------------------------------- saran installer

    public function test_installer_suggestion_ranks_by_skill_availability_and_load(): void
    {
        $full = ['ppf' => TechnicianSkill::INDEPENDENT, 'kaca_film' => TechnicianSkill::EXPERT];

        $freeFull = $this->installer('Andi', $full);
        $busyFull = $this->installer('Beni', $full);
        $partial = $this->installer('Cici', ['ppf' => TechnicianSkill::INDEPENDENT]);
        $unassessed = $this->installer('Dedi');
        $onLeave = $this->installer('Eko', $full);

        LeaveRequest::create([
            'user_id' => $onLeave->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12',
            'reason' => 'x', 'status' => 'approved',
        ]);

        // Beni sudah memegang dua booking terkonfirmasi di tanggal itu.
        $this->booking(['status' => 'confirmed'], [$busyFull]);
        $this->booking(['status' => 'confirmed'], [$busyFull]);

        $target = $this->booking();

        $rows = (new InstallerSkillService())->suggestFor($target);

        $this->assertSame(['Andi', 'Beni', 'Cici', 'Dedi'], $rows->map(fn ($r) => $r['user']->name)->all());
        $this->assertTrue($rows[0]['full']);
        $this->assertSame(2, $rows[1]['jobs']);
        $this->assertSame(['kaca_film'], $rows[2]['missing']);
        $this->assertFalse($rows[3]['assessed']);
        $this->assertNotNull($freeFull);
        $this->assertNotNull($partial);
        $this->assertNotNull($unassessed);

        $this->assertSame([$onLeave->id => 'Cuti/izin'], (new InstallerAvailabilityService())->unavailableReasons($this->store->id, Carbon::parse('2026-10-12')));
    }

    public function test_availability_shape_for_existing_callers_is_unchanged(): void
    {
        $onLeave = $this->installer('Eko');
        $this->installer('Andi');
        LeaveRequest::create([
            'user_id' => $onLeave->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12',
            'reason' => 'x', 'status' => 'approved',
        ]);

        $this->assertSame(
            ['total' => 2, 'available' => 1, 'unavailable' => [['name' => 'Eko', 'reason' => 'Cuti/izin']]],
            (new InstallerAvailabilityService())->forDate($this->store->id, Carbon::parse('2026-10-12')),
        );
    }
}
