<?php

namespace Tests\Feature;

use App\Filament\Resources\CarouselResource;
use App\Filament\Resources\CarouselResource\Pages\CreateCarousel;
use App\Filament\Resources\CarouselResource\Pages\EditCarousel;
use App\Filament\Resources\CarouselResource\Pages\ListCarousels;
use App\Models\Carousel;
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
 * Banner / Carousel: API publik (customer) & partner (audiens, aktif,
 * urutan, bentuk URL gambar), serta pengelolaan di Filament (unggah gambar,
 * validasi link http/https, aktif/nonaktif, urutan, hapus, akses menu, audit).
 */
class CarouselTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function banner(array $overrides = []): Carousel
    {
        return Carousel::create(array_merge([
            'title' => 'Promo ' . uniqid(), 'image' => 'carousel/' . uniqid() . '.jpg',
            'audience' => 'customer', 'is_active' => true, 'sort_order' => 0,
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

    public function test_customer_carousel_api_returns_active_customer_and_both_in_order(): void
    {
        $second = $this->banner(['title' => 'Kedua', 'sort_order' => 2, 'audience' => 'both']);
        $first = $this->banner(['title' => 'Pertama', 'sort_order' => 1]);
        $this->banner(['title' => 'Mati', 'is_active' => false]);
        $this->banner(['title' => 'Khusus Partner', 'audience' => 'partner']);

        $data = $this->getJson('/api/carousels')->assertSuccessful()->json('data');

        $this->assertSame([$first->id, $second->id], collect($data)->pluck('id')->all());
        $this->assertSame(['id', 'title', 'subtitle', 'image', 'link_url'], array_keys($data[0]));
    }

    public function test_carousel_image_url_is_absolute_and_null_when_missing(): void
    {
        $withImage = $this->banner(['image' => 'carousel/banner-a.jpg', 'sort_order' => 1]);
        $noImage = $this->banner(['image' => '', 'sort_order' => 2]);

        $data = collect($this->getJson('/api/carousels')->json('data'));

        $this->assertStringEndsWith('/storage/carousel/banner-a.jpg', $data->firstWhere('id', $withImage->id)['image']);
        $this->assertStringStartsWith('http', $data->firstWhere('id', $withImage->id)['image']);
        $this->assertNull($data->firstWhere('id', $noImage->id)['image']);
    }

    public function test_carousel_api_is_empty_when_nothing_is_active(): void
    {
        $this->banner(['is_active' => false]);

        $this->getJson('/api/carousels')->assertSuccessful()->assertExactJson(['success' => true, 'data' => []]);
    }

    public function test_partner_promos_require_login_and_show_partner_and_both_only(): void
    {
        $partnerOnly = $this->banner(['title' => 'Partner', 'audience' => 'partner', 'sort_order' => 1]);
        $both = $this->banner(['title' => 'Keduanya', 'audience' => 'both', 'sort_order' => 2]);
        $this->banner(['title' => 'Customer', 'audience' => 'customer']);
        $this->banner(['title' => 'Partner Mati', 'audience' => 'partner', 'is_active' => false]);

        $this->getJson('/api/partner/promos')->assertStatus(401);

        Role::findOrCreate('partner', 'web');
        $partner = User::create(['name' => 'Mitra', 'email' => 'mitra@test.local', 'password' => 'x']);
        $partner->assignRole('partner');

        $ids = collect($this->actingAs($partner, 'api')->getJson('/api/partner/promos')->assertSuccessful()->json('data'))->pluck('id')->all();

        $this->assertSame([$partnerOnly->id, $both->id], $ids);
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_and_orders_by_sort_order(): void
    {
        $this->asAdmin();
        $b = $this->banner(['sort_order' => 2]);
        $a = $this->banner(['sort_order' => 1]);

        Livewire::test(ListCarousels::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$a, $b], inOrder: true);
    }

    public function test_admin_creates_a_banner_with_an_uploaded_image(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateCarousel::class)
            ->fillForm([
                'image' => UploadedFile::fake()->image('banner.jpg', 1200, 600),
                'title' => 'Diskon Akhir Tahun', 'link_url' => 'https://ginnva.id/promo',
                'audience' => 'both', 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = Carousel::where('title', 'Diskon Akhir Tahun')->firstOrFail();
        $this->assertStringStartsWith('carousel/', $banner->image);
        Storage::disk('public')->assertExists($banner->image);
        $this->assertSame('both', $banner->audience);
        $this->assertTrue($banner->is_active);
    }

    public function test_image_is_required_and_link_must_be_http_or_https(): void
    {
        Storage::fake('public');
        $this->asAdmin();

        Livewire::test(CreateCarousel::class)
            ->fillForm(['title' => 'Tanpa Gambar', 'audience' => 'customer'])
            ->call('create')
            ->assertHasFormErrors(['image' => 'required']);

        foreach (['ftp://example.com/x', 'javascript:alert(1)', 'bukan url'] as $bad) {
            Livewire::test(CreateCarousel::class)
                ->fillForm(['image' => UploadedFile::fake()->image('b.jpg', 1200, 600), 'title' => 'Link Buruk', 'link_url' => $bad, 'audience' => 'customer'])
                ->call('create')
                ->assertHasFormErrors(['link_url']);
        }

        $this->assertSame(0, Carousel::count());
    }

    public function test_deactivating_a_banner_removes_it_from_the_api_and_is_audited(): void
    {
        // Form Edit memvalidasi bahwa berkas gambar benar-benar ada di disk.
        Storage::fake('public');
        $realPath = UploadedFile::fake()->image('asli.jpg', 1200, 600)->store('carousel', 'public');

        $this->asAdmin();
        $banner = $this->banner(['image' => $realPath]);
        $this->assertCount(1, $this->getJson('/api/carousels')->json('data'));

        Livewire::test(EditCarousel::class, ['record' => $banner->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $this->getJson('/api/carousels')->json('data'));
        $this->assertTrue(Activity::where('log_name', 'carousel')->where('subject_id', $banner->id)->exists());
    }

    public function test_reordering_changes_the_api_order(): void
    {
        $this->asAdmin();
        $a = $this->banner(['title' => 'A', 'sort_order' => 1]);
        $b = $this->banner(['title' => 'B', 'sort_order' => 2]);

        Livewire::test(ListCarousels::class)->call('reorderTable', [$b->id, $a->id]);

        $this->assertSame([$b->id, $a->id], collect($this->getJson('/api/carousels')->json('data'))->pluck('id')->all());
    }

    public function test_admin_deletes_a_banner(): void
    {
        $this->asAdmin();
        $banner = $this->banner();

        Livewire::test(ListCarousels::class)->callTableAction('delete', $banner);

        $this->assertNull(Carousel::find($banner->id));
        $this->assertCount(0, $this->getJson('/api/carousels')->json('data'));
    }

    public function test_menu_access_controls_who_can_manage_banners(): void
    {
        $withMenu = User::create(['name' => 'Kasir A', 'email' => 'a@test.local', 'password' => 'x']);
        $withMenu->assignRole('kasir');
        $noMenu = User::create(['name' => 'Kasir B', 'email' => 'b@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');

        $this->actingAs($withMenu, 'web');
        $this->assertTrue(CarouselResource::canViewAny());
        $this->assertTrue(CarouselResource::canCreate());

        $this->actingAs($noMenu, 'web');
        $this->assertFalse(CarouselResource::canViewAny());
        $this->assertFalse(CarouselResource::canCreate());
    }
}
