<?php

namespace Tests\Feature;

use App\Filament\Resources\FeaturedProductResource;
use App\Filament\Resources\FeaturedProductResource\Pages\CreateFeaturedProduct;
use App\Filament\Resources\FeaturedProductResource\Pages\EditFeaturedProduct;
use App\Filament\Resources\FeaturedProductResource\Pages\ListFeaturedProducts;
use App\Models\FeaturedProduct;
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
 * Seri Produk (Beranda): API publik (4 teratas untuk beranda, semua untuk
 * "Lihat Semua", urutan, aktif, fallback gambar isi) dan pengelolaan di
 * Filament (unggah gambar, validasi link, aktif/nonaktif, urutan, hapus,
 * akses menu, audit).
 */
class FeaturedProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function series(array $overrides = []): FeaturedProduct
    {
        return FeaturedProduct::create(array_merge([
            'title' => 'Seri ' . uniqid(), 'image' => 'featured/' . uniqid() . '.jpg',
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

    public function test_home_shows_only_top_four_active_in_order(): void
    {
        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[$n] = $this->series(['title' => "Seri {$n}", 'sort_order' => $n])->id;
        }
        $this->series(['title' => 'Mati', 'is_active' => false, 'sort_order' => 0]);

        $data = $this->getJson('/api/featured-products')->assertSuccessful()->json('data');

        $this->assertSame([$ids[1], $ids[2], $ids[3], $ids[4]], collect($data)->pluck('id')->all());
    }

    public function test_see_all_returns_every_active_card_in_order(): void
    {
        $a = $this->series(['sort_order' => 2]);
        $b = $this->series(['sort_order' => 1]);
        $c = $this->series(['sort_order' => 3]);
        $d = $this->series(['sort_order' => 4]);
        $e = $this->series(['sort_order' => 5]);
        $this->series(['is_active' => false]);

        $ids = collect($this->getJson('/api/featured-products/all')->assertSuccessful()->json('data'))->pluck('id')->all();

        $this->assertSame([$b->id, $a->id, $c->id, $d->id, $e->id], $ids);
    }

    public function test_response_shape_and_image_url_fallbacks(): void
    {
        $both = $this->series(['image' => 'featured/thumb.jpg', 'content_image' => 'featured/full.jpg', 'sort_order' => 1]);
        $thumbOnly = $this->series(['image' => 'featured/only.jpg', 'content_image' => null, 'sort_order' => 2]);
        $noImage = $this->series(['image' => '', 'content_image' => null, 'sort_order' => 3]);

        $data = collect($this->getJson('/api/featured-products/all')->json('data'));

        $this->assertSame(['id', 'title', 'subtitle', 'image', 'content_image', 'link_url'], array_keys($data[0]));

        $row = $data->firstWhere('id', $both->id);
        $this->assertStringEndsWith('/storage/featured/thumb.jpg', $row['image']);
        $this->assertStringEndsWith('/storage/featured/full.jpg', $row['content_image']);

        $fallback = $data->firstWhere('id', $thumbOnly->id);
        $this->assertStringEndsWith('/storage/featured/only.jpg', $fallback['content_image'], 'Tanpa gambar isi, pakai thumbnail.');

        $empty = $data->firstWhere('id', $noImage->id);
        $this->assertNull($empty['image']);
        $this->assertNull($empty['content_image']);
    }

    public function test_api_is_empty_when_nothing_is_active(): void
    {
        $this->series(['is_active' => false]);

        $this->getJson('/api/featured-products')->assertSuccessful()->assertExactJson(['success' => true, 'data' => []]);
        $this->getJson('/api/featured-products/all')->assertSuccessful()->assertExactJson(['success' => true, 'data' => []]);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_in_order(): void
    {
        $this->asAdmin();
        $b = $this->series(['sort_order' => 2]);
        $a = $this->series(['sort_order' => 1]);

        Livewire::test(ListFeaturedProducts::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$a, $b], inOrder: true);
    }

    public function test_admin_creates_a_series_with_thumbnail_and_content_image(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateFeaturedProduct::class)
            ->fillForm([
                'image' => UploadedFile::fake()->image('thumb.jpg', 800, 600),
                'content_image' => UploadedFile::fake()->image('isi.jpg', 1200, 1600),
                'title' => 'Ginnva PPF Shield', 'subtitle' => 'Proteksi cat premium',
                'link_url' => 'https://ginnva.id/ppf', 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $series = FeaturedProduct::where('title', 'Ginnva PPF Shield')->firstOrFail();
        Storage::disk('public')->assertExists($series->image);
        Storage::disk('public')->assertExists($series->content_image);
        $this->assertNotSame($series->image, $series->content_image);
    }

    public function test_thumbnail_is_required_content_image_optional_and_link_must_be_http(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateFeaturedProduct::class)
            ->fillForm(['title' => 'Tanpa Gambar'])
            ->call('create')
            ->assertHasFormErrors(['image' => 'required']);

        Livewire::test(CreateFeaturedProduct::class)
            ->fillForm(['image' => UploadedFile::fake()->image('t.jpg', 800, 600), 'title' => 'Tanpa Gambar Isi'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertNull(FeaturedProduct::where('title', 'Tanpa Gambar Isi')->value('content_image'));

        foreach (['ftp://example.com/x', 'javascript:alert(1)', 'bukan url'] as $bad) {
            Livewire::test(CreateFeaturedProduct::class)
                ->fillForm(['image' => UploadedFile::fake()->image('t.jpg', 800, 600), 'title' => 'Link Buruk', 'link_url' => $bad])
                ->call('create')
                ->assertHasFormErrors(['link_url']);
        }
        $this->assertSame(0, FeaturedProduct::where('title', 'Link Buruk')->count());
    }

    public function test_deactivating_removes_it_from_the_api_and_is_audited(): void
    {
        Storage::fake('public');
        $realPath = UploadedFile::fake()->image('asli.jpg', 800, 600)->store('featured', 'public');
        $this->asAdmin();
        $series = $this->series(['image' => $realPath]);
        $this->assertCount(1, $this->getJson('/api/featured-products')->json('data'));

        Livewire::test(EditFeaturedProduct::class, ['record' => $series->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $this->getJson('/api/featured-products')->json('data'));
        $this->assertTrue(Activity::where('log_name', 'featured_product')->where('subject_id', $series->id)->exists());
    }

    public function test_reordering_decides_which_four_appear_on_home(): void
    {
        $this->asAdmin();
        $cards = collect(range(1, 5))->map(fn ($n) => $this->series(['title' => "Seri {$n}", 'sort_order' => $n]));

        // Kartu ke-5 dipindah ke urutan pertama: ia masuk beranda, kartu ke-4 tergeser keluar.
        $newOrder = [$cards[4]->id, $cards[0]->id, $cards[1]->id, $cards[2]->id, $cards[3]->id];
        Livewire::test(ListFeaturedProducts::class)->call('reorderTable', $newOrder);

        $home = collect($this->getJson('/api/featured-products')->json('data'))->pluck('id')->all();
        $this->assertSame(array_slice($newOrder, 0, 4), $home);
        $this->assertNotContains($cards[3]->id, $home);
    }

    public function test_admin_deletes_a_series(): void
    {
        $this->asAdmin();
        $series = $this->series();

        Livewire::test(ListFeaturedProducts::class)->callTableAction('delete', $series);

        $this->assertNull(FeaturedProduct::find($series->id));
    }

    public function test_menu_access_controls_who_can_manage_series(): void
    {
        $withMenu = User::create(['name' => 'Kasir A', 'email' => 'a@test.local', 'password' => 'x']);
        $withMenu->assignRole('kasir');
        $noMenu = User::create(['name' => 'Kasir B', 'email' => 'b@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(FeaturedProductResource::canViewAny());
        $this->assertTrue(FeaturedProductResource::canCreate());

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(FeaturedProductResource::canViewAny());
        $this->assertFalse(FeaturedProductResource::canCreate());
    }
}
