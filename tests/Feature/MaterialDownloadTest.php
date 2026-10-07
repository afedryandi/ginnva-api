<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialResource;
use App\Filament\Resources\MaterialResource\Pages\CreateMaterial;
use App\Filament\Resources\MaterialResource\Pages\EditMaterial;
use App\Filament\Resources\MaterialResource\Pages\ListMaterials;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\User;
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
 * Materi Download: pengelolaan file di Filament (unggah PDF/ZIP/gambar/PPTX,
 * tipe & ukuran file tercatat, tipe file lain ditolak, urutan otomatis per
 * kategori, tampil/sembunyikan, filter, urutan, hapus, izin, audit) dan
 * dampaknya ke API publik /api/materials.
 */
class MaterialDownloadTest extends TestCase
{
    use RefreshDatabase;

    private MaterialCategory $guide;
    private MaterialCategory $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->guide = MaterialCategory::create(['name' => 'Panduan', 'sort_order' => 1]);
        $this->catalog = MaterialCategory::create(['name' => 'Katalog', 'sort_order' => 2]);
    }

    private function material(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'material_category_id' => $this->guide->id, 'name' => 'Materi ' . uniqid(),
            'file' => 'materials/' . uniqid() . '.pdf', 'file_type' => 'pdf', 'file_size' => 2048,
            'is_active' => true, 'sort_order' => 0,
        ], $overrides));
    }

    private function asAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        return $admin;
    }

    public function test_filament_list_renders_and_filters_by_category_and_status(): void
    {
        $this->asAdmin();
        $a = $this->material();
        $b = $this->material(['material_category_id' => $this->catalog->id]);
        $off = $this->material(['is_active' => false]);

        Livewire::test(ListMaterials::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$a, $b, $off])
            ->filterTable('material_category_id', $this->catalog->id)
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $off]);

        Livewire::test(ListMaterials::class)
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$a, $b]);
    }

    public function test_admin_uploads_a_pdf_and_it_appears_in_the_public_api(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateMaterial::class)
            ->fillForm([
                'material_category_id' => $this->guide->id, 'name' => 'Panduan Perawatan PPF',
                'file' => UploadedFile::fake()->create('panduan.pdf', 300, 'application/pdf'), 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $material = Material::where('name', 'Panduan Perawatan PPF')->firstOrFail();
        $this->assertStringStartsWith('materials/', $material->file);
        Storage::disk('public')->assertExists($material->file);
        $this->assertSame('pdf', strtolower((string) $material->file_type), 'Tipe file tercatat.');
        $this->assertNotEmpty($material->file_size, 'Ukuran file tercatat supaya tampil di aplikasi.');

        $row = $this->getJson('/api/materials')->json('data.0.materials.0');
        $this->assertSame('Panduan Perawatan PPF', $row['name']);
        $this->assertNotSame('—', $row['file_size']);
    }

    public function test_allowed_file_types_are_accepted(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        $files = [
            UploadedFile::fake()->create('a.zip', 50, 'application/zip'),
            UploadedFile::fake()->image('b.png', 400, 300),
            UploadedFile::fake()->create('c.pptx', 50, 'application/vnd.openxmlformats-officedocument.presentationml.presentation'),
        ];
        foreach ($files as $i => $file) {
            Livewire::test(CreateMaterial::class)
                ->fillForm(['material_category_id' => $this->guide->id, 'name' => "Berkas {$i}", 'file' => $file])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(3, Material::count());
    }

    public function test_disallowed_file_type_and_oversize_are_rejected(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateMaterial::class)
            ->fillForm([
                'material_category_id' => $this->guide->id, 'name' => 'Berbahaya',
                'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            ])
            ->call('create')
            ->assertHasFormErrors(['file']);

        Livewire::test(CreateMaterial::class)
            ->fillForm([
                'material_category_id' => $this->guide->id, 'name' => 'Terlalu Besar',
                'file' => UploadedFile::fake()->create('besar.pdf', 51201, 'application/pdf'),
            ])
            ->call('create')
            ->assertHasFormErrors(['file']);

        $this->assertSame(0, Material::count());
    }

    public function test_category_name_and_file_are_required(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateMaterial::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['material_category_id' => 'required', 'name' => 'required', 'file' => 'required']);

        $this->assertSame(0, Material::count());
    }

    public function test_new_material_goes_last_within_its_own_category(): void
    {
        Storage::fake('public');
        $this->asAdmin();
        $this->material(['sort_order' => 4]);
        $this->material(['material_category_id' => $this->catalog->id, 'sort_order' => 9]);

        Livewire::test(CreateMaterial::class)
            ->fillForm([
                'material_category_id' => $this->guide->id, 'name' => 'Berikutnya',
                'file' => UploadedFile::fake()->create('n.pdf', 20, 'application/pdf'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(5, Material::where('name', 'Berikutnya')->firstOrFail()->sort_order);
    }

    public function test_hiding_a_material_removes_it_from_the_api_and_is_audited(): void
    {
        Storage::fake('public');
        $realPath = UploadedFile::fake()->create('asli.pdf', 20, 'application/pdf')->store('materials', 'public');
        $this->asAdmin();
        $material = $this->material(['file' => $realPath]);
        $this->assertCount(1, $this->getJson('/api/materials')->json('data'));

        Livewire::test(EditMaterial::class, ['record' => $material->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $this->getJson('/api/materials')->json('data'));
        $this->assertTrue(Activity::where('log_name', 'material')->where('subject_id', $material->id)->where('event', 'updated')->exists());
    }

    public function test_moving_a_material_to_another_category_is_reflected_in_the_api(): void
    {
        Storage::fake('public');
        $realPath = UploadedFile::fake()->create('asli.pdf', 20, 'application/pdf')->store('materials', 'public');
        $this->asAdmin();
        $material = $this->material(['file' => $realPath]);

        Livewire::test(EditMaterial::class, ['record' => $material->getKey()])
            ->fillForm(['material_category_id' => $this->catalog->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Katalog', $this->getJson('/api/materials')->json('data.0.name'));
    }

    public function test_reordering_changes_the_api_order(): void
    {
        $this->asAdmin();
        $a = $this->material(['name' => 'A', 'sort_order' => 1]);
        $b = $this->material(['name' => 'B', 'sort_order' => 2]);

        Livewire::test(ListMaterials::class)->call('reorderTable', [$b->id, $a->id]);

        $this->assertSame(['B', 'A'], collect($this->getJson('/api/materials')->json('data.0.materials'))->pluck('name')->all());
    }

    public function test_download_action_points_to_the_stored_file(): void
    {
        $this->asAdmin();
        $material = $this->material(['file' => 'materials/panduan.pdf']);

        Livewire::test(ListMaterials::class)
            ->assertTableActionHasUrl('download', Storage::disk('public')->url('materials/panduan.pdf'), $material);
    }

    public function test_admin_deletes_a_material(): void
    {
        $this->asAdmin();
        $material = $this->material();

        Livewire::test(ListMaterials::class)->callTableAction('delete', $material);

        $this->assertNull(Material::find($material->id));
        $this->assertCount(0, $this->getJson('/api/materials')->json('data'));
    }

    public function test_menu_access_controls_who_can_manage_materials(): void
    {
        $material = $this->material();
        $withMenu = User::create(['name' => 'Kasir A', 'email' => 'a@test.local', 'password' => 'x']);
        $withMenu->assignRole('kasir');
        $noMenu = User::create(['name' => 'Kasir B', 'email' => 'b@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(MaterialResource::canViewAny());
        $this->assertTrue(MaterialResource::canCreate());
        $this->assertTrue(MaterialResource::canEdit($material));
        $this->assertTrue(MaterialResource::canDelete($material));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(MaterialResource::canViewAny());
        $this->assertFalse(MaterialResource::canCreate());
        $this->assertFalse(MaterialResource::canDelete($material));
    }
}
