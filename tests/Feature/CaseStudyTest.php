<?php

namespace Tests\Feature;

use App\Filament\Resources\CaseStudyResource;
use App\Filament\Resources\CaseStudyResource\Pages\CreateCaseStudy;
use App\Filament\Resources\CaseStudyResource\Pages\EditCaseStudy;
use App\Filament\Resources\CaseStudyResource\Pages\ListCaseStudies;
use App\Models\CaseStudy;
use App\Models\FilmProduct;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Galeri Pemasangan (Studi Kasus publik): API publik (aktif saja, urutan,
 * filter jenis produk, bentuk respons) dan pengelolaan di Filament (unggah,
 * validasi, aktif/nonaktif, urutan, hapus, izin per aksi, audit).
 */
class CaseStudyTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;
    private FilmProduct $windowFilm;
    private FilmProduct $ppf;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->vehicle = Vehicle::create(['brand' => 'Toyota', 'model' => 'Fortuner', 'variant' => 'VRZ', 'size_category' => 'L']);
        $this->windowFilm = FilmProduct::create([
            'sku' => 'WF-A70', 'name' => 'Kaca Film A70', 'product_type' => 'window_film', 'base_price' => 1000000, 'is_active' => true,
        ]);
        $this->ppf = FilmProduct::create([
            'sku' => 'PPF-01', 'name' => 'PPF Shield', 'product_type' => 'ppf', 'position' => 'front', 'base_price' => 5000000, 'is_active' => true,
        ]);
    }

    private function study(array $overrides = []): CaseStudy
    {
        return CaseStudy::create(array_merge([
            'vehicle_id' => $this->vehicle->id, 'film_product_id' => $this->windowFilm->id,
            'title' => 'Toyota Fortuner · Kaca Film · A70', 'short_title' => 'Fortuner · A70',
            'image' => 'case-studies/' . uniqid() . '.jpg', 'sort_order' => 0, 'is_active' => true,
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

    public function test_api_returns_only_active_items_in_sort_order(): void
    {
        $second = $this->study(['sort_order' => 2]);
        $first = $this->study(['sort_order' => 1]);
        $this->study(['is_active' => false, 'sort_order' => 0]);

        $ids = collect($this->getJson('/api/case-studies')->assertSuccessful()->json('data'))->pluck('id')->all();

        $this->assertSame([$first->id, $second->id], $ids);
    }

    public function test_api_response_shape_and_absolute_image_url(): void
    {
        $item = $this->study(['image' => 'case-studies/hasil.jpg']);

        $row = $this->getJson('/api/case-studies')->json('data.0');

        $this->assertSame(['id', 'title', 'short_title', 'image', 'vehicle', 'film_product', 'sort_order'], array_keys($row));
        $this->assertSame($item->id, $row['id']);
        $this->assertStringStartsWith('http', $row['image']);
        $this->assertStringEndsWith('/storage/case-studies/hasil.jpg', $row['image']);
        $this->assertSame(['id' => $this->vehicle->id, 'brand' => 'Toyota', 'model' => 'Fortuner'], $row['vehicle']);
        $this->assertSame(
            ['id' => $this->windowFilm->id, 'name' => 'Kaca Film A70', 'product_type' => 'window_film'],
            $row['film_product'],
        );
    }

    public function test_product_type_filter_narrows_the_gallery(): void
    {
        $wf = $this->study();
        $ppf = $this->study(['film_product_id' => $this->ppf->id, 'title' => 'PPF Fortuner', 'short_title' => 'PPF']);

        $all = collect($this->getJson('/api/case-studies')->json('data'))->pluck('id')->all();
        $onlyWf = collect($this->getJson('/api/case-studies?product_type=window_film')->json('data'))->pluck('id')->all();
        $onlyPpf = collect($this->getJson('/api/case-studies?product_type=ppf')->json('data'))->pluck('id')->all();
        $none = $this->getJson('/api/case-studies?product_type=color_change')->json('data');

        $this->assertEqualsCanonicalizing([$wf->id, $ppf->id], $all);
        $this->assertSame([$wf->id], $onlyWf);
        $this->assertSame([$ppf->id], $onlyPpf);
        $this->assertSame([], $none);
    }

    public function test_api_is_public_and_empty_when_nothing_is_active(): void
    {
        $this->study(['is_active' => false]);

        $this->getJson('/api/case-studies')->assertSuccessful()->assertExactJson(['success' => true, 'data' => []]);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_in_order(): void
    {
        $this->asAdmin();
        $b = $this->study(['sort_order' => 2]);
        $a = $this->study(['sort_order' => 1]);

        Livewire::test(ListCaseStudies::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$a, $b], inOrder: true);
    }

    public function test_admin_creates_an_entry_with_vehicle_product_and_photo(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateCaseStudy::class)
            ->fillForm(['vehicle_brand' => 'Toyota'])
            ->fillForm([
                'vehicle_id' => $this->vehicle->id, 'film_product_id' => $this->windowFilm->id,
                'title' => 'Toyota Fortuner · Kaca Film · A70', 'short_title' => 'Fortuner · A70',
                'image' => UploadedFile::fake()->image('hasil.jpg', 1200, 800), 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $study = CaseStudy::where('short_title', 'Fortuner · A70')->firstOrFail();
        $this->assertSame($this->vehicle->id, $study->vehicle_id);
        Storage::disk('public')->assertExists($study->image);
        $this->assertSame($study->id, $this->getJson('/api/case-studies')->json('data.0.id'));
    }

    public function test_required_fields_and_title_length_are_validated(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateCaseStudy::class)
            ->fillForm(['title' => '', 'short_title' => ''])
            ->call('create')
            ->assertHasFormErrors([
                'vehicle_id' => 'required', 'film_product_id' => 'required',
                'title' => 'required', 'short_title' => 'required', 'image' => 'required',
            ]);

        Livewire::test(CreateCaseStudy::class)
            ->fillForm(['vehicle_brand' => 'Toyota'])
            ->fillForm([
                'vehicle_id' => $this->vehicle->id, 'film_product_id' => $this->windowFilm->id,
                'title' => str_repeat('a', 256), 'short_title' => 'ok',
                'image' => UploadedFile::fake()->image('a.jpg', 800, 600),
            ])
            ->call('create')
            ->assertHasFormErrors(['title' => 'max']);

        $this->assertSame(0, CaseStudy::count());
    }

    public function test_deactivating_removes_it_from_the_api_and_is_audited(): void
    {
        Storage::fake('public');
        $realPath = UploadedFile::fake()->image('asli.jpg', 1200, 800)->store('case-studies', 'public');
        $this->asAdmin();
        $study = $this->study(['image' => $realPath]);
        $this->assertCount(1, $this->getJson('/api/case-studies')->json('data'));

        Livewire::test(EditCaseStudy::class, ['record' => $study->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $this->getJson('/api/case-studies')->json('data'));
        $this->assertTrue(Activity::where('log_name', 'case_study')->where('subject_id', $study->id)->where('event', 'updated')->exists());
    }

    public function test_reordering_changes_the_api_order(): void
    {
        $this->asAdmin();
        $a = $this->study(['sort_order' => 1]);
        $b = $this->study(['sort_order' => 2]);

        Livewire::test(ListCaseStudies::class)->call('reorderTable', [$b->id, $a->id]);

        $this->assertSame([$b->id, $a->id], collect($this->getJson('/api/case-studies')->json('data'))->pluck('id')->all());
    }

    public function test_admin_deletes_an_entry(): void
    {
        $this->asAdmin();
        $study = $this->study();

        Livewire::test(ListCaseStudies::class)->callTableAction('delete', $study);

        $this->assertNull(CaseStudy::find($study->id));
        $this->assertCount(0, $this->getJson('/api/case-studies')->json('data'));
    }

    public function test_menu_access_controls_who_can_manage_entries(): void
    {
        $study = $this->study();
        $withMenu = User::create(['name' => 'Kasir A', 'email' => 'a@test.local', 'password' => 'x']);
        $withMenu->assignRole('kasir');
        $noMenu = User::create(['name' => 'Kasir B', 'email' => 'b@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(CaseStudyResource::canViewAny());
        $this->assertTrue(CaseStudyResource::canCreate());
        $this->assertTrue(CaseStudyResource::canEdit($study));
        $this->assertTrue(CaseStudyResource::canDelete($study));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(CaseStudyResource::canViewAny());
        $this->assertFalse(CaseStudyResource::canCreate());
        $this->assertFalse(CaseStudyResource::canDelete($study));
    }
}
