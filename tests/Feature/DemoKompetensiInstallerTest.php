<?php

namespace Tests\Feature;

use App\Filament\Pages\InstallerCompetencyReport;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianSkill;
use App\Models\TechnicianTraining;
use App\Models\User;
use App\Services\InstallerQualityService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perintah data demo kompetensi:demo mengisi menu Kompetensi Installer: lima installer dengan matriks keterampilan, sertifikat (ada yang
 * kedaluwarsa dan hampir kedaluwarsa), dan mutu pekerjaan 90 hari. Aman dijalankan ulang, bisa dibersihkan, dan tidak mengotori Histori Aktivitas.
 */
class DemoKompetensiInstallerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function demoTechnicians()
    {
        return Technician::withoutGlobalScopes()->where('name', 'like', 'Demo %');
    }

    public function test_it_creates_the_five_installers_with_skills_and_certificates(): void
    {
        $this->artisan('kompetensi:demo')->assertSuccessful();

        $this->assertSame(5, $this->demoTechnicians()->count());
        $this->assertSame(12, TechnicianSkill::count());
        $this->assertSame(6, TechnicianTraining::count());

        $this->assertSame(1, TechnicianTraining::all()->filter->isExpired()->count(), 'Satu sertifikat kedaluwarsa.');
        $this->assertSame(1, TechnicianTraining::all()->filter->expiresSoon()->count(), 'Satu sertifikat hampir kedaluwarsa.');
        $this->assertSame(0, Technician::withoutGlobalScopes()->where('name', 'Demo Fajar Baru')->first()->skills()->count(), 'Satu installer belum pernah dinilai.');
    }

    public function test_the_quality_figures_match_each_profile(): void
    {
        $this->artisan('kompetensi:demo')->assertSuccessful();

        $rows = app(InstallerQualityService::class)->forPeriod(now()->subDays(89), now())->keyBy('name');

        // Rina: 12 sendiri + 3 pekerjaan tim; Eko ikut 3 pekerjaan tim yang sama.
        $this->assertSame([15, 14, 13, 1, 1], [$rows['Demo Rina Ahli']['jobs'], $rows['Demo Rina Ahli']['checked'], $rows['Demo Rina Ahli']['first_pass'], $rows['Demo Rina Ahli']['reworked'], $rows['Demo Rina Ahli']['rework_recorded']]);
        $this->assertSame([9, 9, 6, 3, 2], [$rows['Demo Eko Pemula']['jobs'], $rows['Demo Eko Pemula']['checked'], $rows['Demo Eko Pemula']['first_pass'], $rows['Demo Eko Pemula']['reworked'], $rows['Demo Eko Pemula']['rework_recorded']]);
        $this->assertSame([8, 7, 5, 2, 1], [$rows['Demo Dani Mandiri']['jobs'], $rows['Demo Dani Mandiri']['checked'], $rows['Demo Dani Mandiri']['first_pass'], $rows['Demo Dani Mandiri']['reworked'], $rows['Demo Dani Mandiri']['rework_recorded']]);
        $this->assertSame([7, 7, 7, 0], [$rows['Demo Gita Detailing']['jobs'], $rows['Demo Gita Detailing']['checked'], $rows['Demo Gita Detailing']['first_pass'], $rows['Demo Gita Detailing']['reworked']]);
        $this->assertSame(2, $rows['Demo Fajar Baru']['jobs']);
        $this->assertNull($rows['Demo Fajar Baru']['first_pass_rate'], 'Belum ada yang diperiksa QC.');
    }

    public function test_the_competency_page_shows_the_demo_installers(): void
    {
        $this->artisan('kompetensi:demo')->assertSuccessful();

        $admin = tap(User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
        $this->actingAs($admin, 'web');

        Livewire::test(InstallerCompetencyReport::class)
            ->assertSee('Demo Rina Ahli')
            ->assertSee('Demo Fajar Baru')
            ->assertSee('Belum dinilai')
            ->assertSee('Keselamatan Kerja Bengkel');
    }

    public function test_running_it_again_does_not_duplicate_and_cleanup_removes_everything(): void
    {
        $this->artisan('kompetensi:demo')->assertSuccessful();
        $this->artisan('kompetensi:demo')->assertSuccessful();

        $this->assertSame(5, $this->demoTechnicians()->count());
        $this->assertSame(5, User::where('email', 'like', '%@kompetensi.ginnva.test')->count());

        $this->artisan('kompetensi:demo', ['--cleanup' => true])->assertSuccessful();

        $this->assertSame(0, $this->demoTechnicians()->count());
        $this->assertSame(0, User::where('email', 'like', '%@kompetensi.ginnva.test')->count());
        $this->assertSame(0, Booking::withoutGlobalScopes()->where('booking_number', 'like', 'DEMO-KMP-%')->count());
        $this->assertSame(0, Customer::where('name', 'Demo Pelanggan Kompetensi')->count());
        $this->assertSame(0, TechnicianSkill::count());
        $this->assertSame(0, TechnicianTraining::count());
    }

    public function test_the_activity_history_is_not_filled_with_demo_data(): void
    {
        $before = Activity::count();

        $this->artisan('kompetensi:demo')->assertSuccessful();

        $this->assertSame($before, Activity::count());
    }
}
