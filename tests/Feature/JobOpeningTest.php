<?php

namespace Tests\Feature;

use App\Filament\Resources\JobOpeningResource\Pages\CreateJobOpening;
use App\Filament\Resources\JobOpeningResource\Pages\EditJobOpening;
use App\Filament\Resources\JobOpeningResource\Pages\ListJobOpenings;
use App\Models\JobOpening;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lowongan Kerja: API publik /api/job-openings (hanya yang tayang, urutan,
 * bentuk respons, kualifikasi kosong) dan pengelolaan di Filament (buat
 * dengan kualifikasi, validasi, tayang/sembunyi, urutan, hapus, izin per
 * aksi, audit).
 */
class JobOpeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function job(array $overrides = []): JobOpening
    {
        return JobOpening::create(array_merge([
            'title' => 'Posisi ' . uniqid(), 'department' => 'Operasional', 'location' => 'PIK 2, Tangerang',
            'type' => 'Full-time', 'description' => 'Deskripsi pekerjaan', 'requirements' => ['Pengalaman 1 tahun'],
            'is_published' => true, 'sort_order' => 0,
        ], $overrides));
    }

    private function asAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    // ------------------------------------------------------------- API publik

    public function test_api_returns_only_published_jobs_in_sort_order(): void
    {
        $second = $this->job(['sort_order' => 2]);
        $first = $this->job(['sort_order' => 1]);
        $this->job(['is_published' => false, 'sort_order' => 0]);

        $ids = collect($this->getJson('/api/job-openings')->assertSuccessful()->json('data'))->pluck('id')->all();

        $this->assertSame([$first->id, $second->id], $ids);
    }

    public function test_api_response_shape_and_requirements(): void
    {
        $job = $this->job(['title' => 'Installer PPF', 'requirements' => ['Teliti', 'Bisa bekerja dalam tim']]);
        $noReq = $this->job(['requirements' => null, 'sort_order' => 1]);

        $data = collect($this->getJson('/api/job-openings')->json('data'));
        $row = $data->firstWhere('id', $job->id);

        $this->assertSame(['id', 'title', 'department', 'location', 'type', 'description', 'requirements'], array_keys($row));
        $this->assertSame('Installer PPF', $row['title']);
        $this->assertSame(['Teliti', 'Bisa bekerja dalam tim'], $row['requirements']);
        $this->assertSame([], $data->firstWhere('id', $noReq->id)['requirements'], 'Tanpa kualifikasi tetap array kosong, bukan null.');
    }

    public function test_api_is_public_and_empty_when_nothing_is_published(): void
    {
        $this->job(['is_published' => false]);

        $this->getJson('/api/job-openings')->assertSuccessful()->assertExactJson(['success' => true, 'data' => []]);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_in_order_and_filters_by_status(): void
    {
        $this->asAdmin();
        $b = $this->job(['sort_order' => 2]);
        $a = $this->job(['sort_order' => 1]);
        $hidden = $this->job(['is_published' => false, 'sort_order' => 3]);

        Livewire::test(ListJobOpenings::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$a, $b, $hidden], inOrder: true)
            ->filterTable('is_published', false)
            ->assertCanSeeTableRecords([$hidden])->assertCanNotSeeTableRecords([$a, $b]);
    }

    public function test_admin_creates_a_job_with_requirements_and_it_goes_live(): void
    {
        $this->asAdmin();

        Livewire::test(CreateJobOpening::class)
            ->fillForm([
                'title' => 'Sales Executive', 'department' => 'Penjualan', 'location' => 'PIK 2, Tangerang', 'type' => 'Kontrak',
                'description' => 'Menjual produk PPF dan kaca film.',
                'requirements' => ['Komunikatif', 'Punya kendaraan pribadi'],
                'is_published' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $job = JobOpening::where('title', 'Sales Executive')->firstOrFail();
        $this->assertSame(['Komunikatif', 'Punya kendaraan pribadi'], array_values($job->requirements));

        $row = $this->getJson('/api/job-openings')->json('data.0');
        $this->assertSame('Sales Executive', $row['title']);
        $this->assertSame('Kontrak', $row['type']);
        $this->assertSame(['Komunikatif', 'Punya kendaraan pribadi'], $row['requirements']);
    }

    public function test_required_fields_and_type_options_are_validated(): void
    {
        $this->asAdmin();

        Livewire::test(CreateJobOpening::class)
            ->fillForm(['title' => '', 'department' => '', 'location' => '', 'description' => ''])
            ->call('create')
            ->assertHasFormErrors(['title' => 'required', 'department' => 'required', 'location' => 'required', 'description' => 'required']);

        Livewire::test(CreateJobOpening::class)
            ->fillForm(['title' => 'X', 'department' => 'Y', 'description' => 'Z', 'type' => 'Freelance'])
            ->call('create')
            ->assertHasFormErrors(['type']);

        $this->assertSame(0, JobOpening::count());
    }

    public function test_new_job_defaults_to_full_time_published_at_pik2(): void
    {
        $this->asAdmin();

        Livewire::test(CreateJobOpening::class)
            ->fillForm(['title' => 'Admin Gudang', 'department' => 'Operasional', 'description' => 'Mengelola stok.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $job = JobOpening::where('title', 'Admin Gudang')->firstOrFail();
        $this->assertSame('Full-time', $job->type);
        $this->assertSame('PIK 2, Tangerang', $job->location);
        $this->assertTrue($job->is_published);
    }

    public function test_unpublishing_removes_it_from_the_api_and_is_audited(): void
    {
        $this->asAdmin();
        $job = $this->job();
        $this->assertCount(1, $this->getJson('/api/job-openings')->json('data'));

        Livewire::test(EditJobOpening::class, ['record' => $job->getKey()])
            ->fillForm(['is_published' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $this->getJson('/api/job-openings')->json('data'));
        $this->assertTrue(Activity::where('log_name', 'job_opening')->where('subject_id', $job->id)->where('event', 'updated')->exists());
    }

    public function test_reordering_changes_the_api_order(): void
    {
        $this->asAdmin();
        $a = $this->job(['title' => 'A', 'sort_order' => 1]);
        $b = $this->job(['title' => 'B', 'sort_order' => 2]);

        Livewire::test(ListJobOpenings::class)->call('reorderTable', [$b->id, $a->id]);

        $this->assertSame(['B', 'A'], collect($this->getJson('/api/job-openings')->json('data'))->pluck('title')->all());
    }

    public function test_admin_deletes_a_job(): void
    {
        $this->asAdmin();
        $job = $this->job();

        Livewire::test(ListJobOpenings::class)->callTableAction('delete', $job);

        $this->assertNull(JobOpening::find($job->id));
        $this->assertCount(0, $this->getJson('/api/job-openings')->json('data'));
    }

    public function test_permissions_per_action(): void
    {
        $job = $this->job();
        $editor = User::create(['name' => 'Kasir A', 'email' => 'a@test.local', 'password' => 'x']);
        $editor->assignRole('kasir');
        $noMenu = User::create(['name' => 'Kasir B', 'email' => 'b@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');
        $admin = $this->asAdmin();

        // Staf dengan menu: boleh lihat/buat/ubah, TIDAK boleh menghapus (default ketat).
        $this->assertTrue($editor->can('viewAny', JobOpening::class));
        $this->assertTrue($editor->can('create', JobOpening::class));
        $this->assertTrue($editor->can('update', $job));
        $this->assertFalse($editor->can('delete', $job));

        $this->assertFalse($noMenu->can('viewAny', JobOpening::class));
        $this->assertFalse($noMenu->can('create', JobOpening::class));
        $this->assertFalse($noMenu->can('delete', $job));

        $this->assertTrue($admin->can('delete', $job));
    }
}
