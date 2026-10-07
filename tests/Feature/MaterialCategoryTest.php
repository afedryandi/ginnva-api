<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialCategoryResource;
use App\Filament\Resources\MaterialCategoryResource\Pages\CreateMaterialCategory;
use App\Filament\Resources\MaterialCategoryResource\Pages\EditMaterialCategory;
use App\Filament\Resources\MaterialCategoryResource\Pages\ListMaterialCategories;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kategori Materi: API publik /api/materials (kategori tanpa materi aktif
 * tidak dikirim, urutan, bentuk respons) dan pengelolaan di Filament (buat,
 * nama unik, urutan otomatis, ubah nama, hapus hanya kategori kosong agar
 * materi tidak ikut terhapus, izin per aksi, audit).
 */
class MaterialCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function category(string $name, int $sort = 0): MaterialCategory
    {
        return MaterialCategory::create(['name' => $name, 'sort_order' => $sort]);
    }

    private function material(MaterialCategory $category, array $overrides = []): Material
    {
        return Material::create(array_merge([
            'material_category_id' => $category->id, 'name' => 'Materi ' . uniqid(),
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

    // ------------------------------------------------------------- API publik

    public function test_api_lists_categories_in_order_with_active_materials_only(): void
    {
        $second = $this->category('Katalog', 2);
        $first = $this->category('Panduan', 1);
        $this->material($second, ['name' => 'Katalog 2026']);
        $this->material($first, ['name' => 'Panduan B', 'sort_order' => 2]);
        $this->material($first, ['name' => 'Panduan A', 'sort_order' => 1]);
        $this->material($first, ['name' => 'Panduan Nonaktif', 'is_active' => false]);

        $data = $this->getJson('/api/materials')->assertSuccessful()->json('data');

        $this->assertSame(['Panduan', 'Katalog'], collect($data)->pluck('name')->all());
        $this->assertSame(['Panduan A', 'Panduan B'], collect($data[0]['materials'])->pluck('name')->all());
    }

    public function test_categories_without_active_materials_are_not_sent(): void
    {
        $empty = $this->category('Kosong', 1);
        $allOff = $this->category('Semua Nonaktif', 2);
        $this->material($allOff, ['is_active' => false]);
        $filled = $this->category('Terisi', 3);
        $this->material($filled);

        $names = collect($this->getJson('/api/materials')->json('data'))->pluck('name')->all();

        $this->assertSame(['Terisi'], $names);
        $this->assertNotContains($empty->name, $names);
    }

    public function test_material_shape_file_size_and_absolute_url(): void
    {
        $cat = $this->category('Panduan');
        $this->material($cat, ['file' => 'materials/panduan.pdf', 'file_size' => 2048, 'file_type' => 'pdf']);
        $this->material($cat, ['file_size' => null, 'sort_order' => 1]);

        $materials = $this->getJson('/api/materials')->json('data.0.materials');

        $this->assertSame(['id', 'name', 'file_type', 'file_size', 'url'], array_keys($materials[0]));
        $this->assertSame('2 KB', $materials[0]['file_size']);
        $this->assertStringStartsWith('http', $materials[0]['url']);
        $this->assertStringEndsWith('/storage/materials/panduan.pdf', $materials[0]['url']);
        $this->assertSame('—', $materials[1]['file_size']);
    }

    public function test_api_is_public_and_empty_without_data(): void
    {
        $this->getJson('/api/materials')->assertSuccessful()->assertExactJson(['success' => true, 'data' => []]);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_in_order_with_material_counts(): void
    {
        $this->asAdmin();
        $b = $this->category('B', 2);
        $a = $this->category('A', 1);
        $this->material($a);
        $this->material($a);

        Livewire::test(ListMaterialCategories::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$a, $b], inOrder: true)
            ->assertTableColumnStateSet('materials_count', 2, $a->loadCount('materials'))
            ->assertTableColumnStateSet('materials_count', 0, $b->loadCount('materials'));
    }

    public function test_admin_creates_a_category_and_sort_order_defaults_to_next(): void
    {
        $this->asAdmin();
        $this->category('Lama', 5);

        Livewire::test(CreateMaterialCategory::class)
            ->fillForm(['name' => 'Baru'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(6, MaterialCategory::where('name', 'Baru')->firstOrFail()->sort_order);
    }

    public function test_name_is_required_and_must_be_unique(): void
    {
        $this->asAdmin();
        $this->category('Panduan');

        Livewire::test(CreateMaterialCategory::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        Livewire::test(CreateMaterialCategory::class)
            ->fillForm(['name' => 'Panduan'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        $this->assertSame(1, MaterialCategory::count());
    }

    public function test_renaming_keeps_its_own_name_valid_blocks_a_taken_one_and_is_audited(): void
    {
        $this->asAdmin();
        $cat = $this->category('Panduan', 1);
        $this->category('Katalog', 2);

        // Simpan tanpa ganti nama: nama sendiri tidak dianggap duplikat.
        Livewire::test(EditMaterialCategory::class, ['record' => $cat->getKey()])
            ->fillForm(['sort_order' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditMaterialCategory::class, ['record' => $cat->getKey()])
            ->fillForm(['name' => 'Katalog'])
            ->call('save')
            ->assertHasFormErrors(['name' => 'unique']);

        Livewire::test(EditMaterialCategory::class, ['record' => $cat->getKey()])
            ->fillForm(['name' => 'Panduan Teknis'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Panduan Teknis', $cat->fresh()->name);
        $this->assertTrue(Activity::where('log_name', 'material_category')->where('subject_id', $cat->id)->where('event', 'updated')->exists());
    }

    public function test_rename_shows_up_in_the_public_api(): void
    {
        $this->asAdmin();
        $cat = $this->category('Typo');
        $this->material($cat);

        Livewire::test(EditMaterialCategory::class, ['record' => $cat->getKey()])
            ->fillForm(['name' => 'Benar'])->call('save');

        $this->assertSame('Benar', $this->getJson('/api/materials')->json('data.0.name'));
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $this->asAdmin();
        $cat = $this->category('Kosong');

        Livewire::test(ListMaterialCategories::class)
            ->assertTableActionVisible('delete', $cat)
            ->callTableAction('delete', $cat);

        $this->assertNull(MaterialCategory::find($cat->id));
    }

    public function test_category_with_materials_cannot_be_deleted_so_materials_survive(): void
    {
        $this->asAdmin();
        $cat = $this->category('Terisi');
        $material = $this->material($cat);

        Livewire::test(ListMaterialCategories::class)
            ->assertTableActionHidden('delete', $cat);

        $this->assertNotNull(MaterialCategory::find($cat->id));
        $this->assertNotNull(Material::find($material->id), 'Materi tidak boleh ikut terhapus diam-diam.');
    }

    public function test_menu_access_controls_who_can_manage_categories(): void
    {
        $cat = $this->category('Panduan');
        $withMenu = User::create(['name' => 'Kasir A', 'email' => 'a@test.local', 'password' => 'x']);
        $withMenu->assignRole('kasir');
        $noMenu = User::create(['name' => 'Kasir B', 'email' => 'b@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(MaterialCategoryResource::canViewAny());
        $this->assertTrue(MaterialCategoryResource::canCreate());
        $this->assertTrue(MaterialCategoryResource::canEdit($cat));
        $this->assertTrue(MaterialCategoryResource::canDelete($cat));

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(MaterialCategoryResource::canViewAny());
        $this->assertFalse(MaterialCategoryResource::canCreate());
        $this->assertFalse(MaterialCategoryResource::canDelete($cat));
    }
}
