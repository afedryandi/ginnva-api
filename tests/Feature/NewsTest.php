<?php

namespace Tests\Feature;

use App\Filament\Resources\NewsResource\Pages\CreateNews;
use App\Filament\Resources\NewsResource\Pages\EditNews;
use App\Filament\Resources\NewsResource\Pages\ListNews;
use App\Models\News;
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
 * Berita: API publik (hanya yang tayang, terjadwal tidak bocor, urutan,
 * limit, detail per slug), slug otomatis unik, dan pengelolaan di Filament
 * (buat dengan cover, slug unik, publish/jadwal/draft, filter, hapus,
 * izin per aksi, audit).
 */
class NewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function news(array $overrides = []): News
    {
        return News::create(array_merge([
            'title' => 'Berita ' . uniqid(), 'excerpt' => 'Ringkasan', 'content' => '<p>Isi berita</p>',
            'is_published' => true, 'published_at' => now()->subHour(),
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

    public function test_list_shows_only_live_news_newest_first(): void
    {
        $older = $this->news(['title' => 'Lama', 'published_at' => now()->subDays(5)]);
        $newer = $this->news(['title' => 'Baru', 'published_at' => now()->subHour()]);
        $noDate = $this->news(['title' => 'Tanpa Tanggal', 'published_at' => null]);
        $this->news(['title' => 'Draft', 'is_published' => false]);
        $this->news(['title' => 'Terjadwal', 'published_at' => now()->addDays(2)]);

        $ids = collect($this->getJson('/api/news')->assertSuccessful()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$older->id, $newer->id, $noDate->id], $ids);
        $this->assertSame($newer->id, $ids[0], 'Yang terbaru tampil paling atas (berita tanpa tanggal tidak mengalahkannya).');
        $this->assertSame($older->id, $ids[1]);
    }

    public function test_list_limit_parameter_and_shape(): void
    {
        foreach (range(1, 5) as $n) {
            $this->news(['title' => "Berita {$n}", 'published_at' => now()->subHours($n)]);
        }

        $data = $this->getJson('/api/news?limit=3')->assertSuccessful()->json('data');

        $this->assertCount(3, $data);
        $this->assertSame(['id', 'title', 'slug', 'excerpt', 'cover_image', 'source_url', 'published_at'], array_keys($data[0]));
        $this->assertArrayNotHasKey('content', $data[0], 'Isi lengkap hanya di detail.');
    }

    public function test_cover_image_is_absolute_url_or_null(): void
    {
        $withCover = $this->news(['cover_image' => 'news/cover.jpg', 'published_at' => now()->subHour()]);
        $noCover = $this->news(['cover_image' => null, 'published_at' => now()->subHours(2)]);

        $data = collect($this->getJson('/api/news')->json('data'));

        $url = $data->firstWhere('id', $withCover->id)['cover_image'];
        $this->assertStringStartsWith('http', $url);
        $this->assertStringEndsWith('/storage/news/cover.jpg', $url);
        $this->assertNull($data->firstWhere('id', $noCover->id)['cover_image']);
    }

    public function test_detail_by_slug_includes_content_and_hides_unpublished(): void
    {
        $live = $this->news(['title' => 'Berita Tayang']);
        $draft = $this->news(['title' => 'Berita Draft', 'is_published' => false]);
        $scheduled = $this->news(['title' => 'Berita Terjadwal', 'published_at' => now()->addDay()]);

        $this->getJson("/api/news/{$live->slug}")->assertSuccessful()
            ->assertJsonPath('data.title', 'Berita Tayang')
            ->assertJsonPath('data.content', '<p>Isi berita</p>');

        $this->getJson("/api/news/{$draft->slug}")->assertStatus(404);
        $this->getJson("/api/news/{$scheduled->slug}")->assertStatus(404);
        $this->getJson('/api/news/slug-tidak-ada')->assertStatus(404);
    }

    // ------------------------------------------------------------- slug

    public function test_slug_is_generated_and_made_unique(): void
    {
        $first = News::create(['title' => 'Judul Sama']);
        $second = News::create(['title' => 'Judul Sama']);
        $third = News::create(['title' => 'Judul Sama']);
        $custom = News::create(['title' => 'Apa Saja', 'slug' => 'slug-pilihan']);

        $this->assertSame('judul-sama', $first->slug);
        $this->assertSame('judul-sama-2', $second->slug);
        $this->assertSame('judul-sama-3', $third->slug);
        $this->assertSame('slug-pilihan', $custom->slug, 'Slug yang diisi manual dipertahankan.');
    }

    // ------------------------------------------------------------- Filament

    public function test_filament_list_renders_with_status_labels(): void
    {
        $this->asAdmin();
        $live = $this->news();
        $draft = $this->news(['is_published' => false]);
        $scheduled = $this->news(['published_at' => now()->addDays(3)]);

        Livewire::test(ListNews::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$live, $draft, $scheduled])
            ->assertTableColumnStateSet('status_tayang', 'live', $live)
            ->assertTableColumnStateSet('status_tayang', 'draft', $draft)
            ->assertTableColumnStateSet('status_tayang', 'scheduled', $scheduled);
    }

    public function test_publish_filter_separates_published_from_drafts(): void
    {
        $this->asAdmin();
        $live = $this->news();
        $draft = $this->news(['is_published' => false]);

        Livewire::test(ListNews::class)
            ->filterTable('is_published', true)
            ->assertCanSeeTableRecords([$live])->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_admin_creates_a_published_article_with_cover_and_author(): void
    {
        Storage::fake('public');
        $admin = $this->asAdmin();

        Livewire::test(CreateNews::class)
            ->fillForm([
                'title' => 'Peluncuran Seri Baru', 'slug' => 'peluncuran-seri-baru', 'excerpt' => 'Seri terbaru kami',
                'content' => '<p>Detail peluncuran</p>', 'cover_image' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
                'is_published' => true, 'published_at' => now()->subMinute()->toDateTimeString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $news = News::where('slug', 'peluncuran-seri-baru')->firstOrFail();
        $this->assertSame($admin->id, $news->created_by);
        Storage::disk('public')->assertExists($news->cover_image);
        $this->assertSame('peluncuran-seri-baru', $this->getJson('/api/news')->json('data.0.slug'));
    }

    public function test_title_is_required_and_slug_must_be_unique(): void
    {
        $this->asAdmin();
        $this->news(['title' => 'Sudah Ada', 'slug' => 'sudah-ada']);

        Livewire::test(CreateNews::class)
            ->fillForm(['title' => '', 'slug' => 'apa-saja'])
            ->call('create')
            ->assertHasFormErrors(['title' => 'required']);

        Livewire::test(CreateNews::class)
            ->fillForm(['title' => 'Judul Baru', 'slug' => 'sudah-ada'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        $this->assertSame(1, News::count());
    }

    public function test_new_article_defaults_to_draft_and_stays_off_the_api(): void
    {
        $this->asAdmin();

        Livewire::test(CreateNews::class)
            ->fillForm(['title' => 'Masih Draf', 'slug' => 'masih-draf'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(News::where('slug', 'masih-draf')->firstOrFail()->is_published);
        $this->assertCount(0, $this->getJson('/api/news')->json('data'));
        $this->getJson('/api/news/masih-draf')->assertStatus(404);
    }

    public function test_publishing_a_draft_and_unpublishing_again_is_audited(): void
    {
        $this->asAdmin();
        $news = $this->news(['title' => 'Draf Awal', 'is_published' => false]);
        $this->assertCount(0, $this->getJson('/api/news')->json('data'));

        Livewire::test(EditNews::class, ['record' => $news->getKey()])
            ->fillForm(['is_published' => true, 'published_at' => now()->subMinute()->toDateTimeString()])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertCount(1, $this->getJson('/api/news')->json('data'));

        Livewire::test(EditNews::class, ['record' => $news->getKey()])
            ->fillForm(['is_published' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertCount(0, $this->getJson('/api/news')->json('data'));

        $this->assertGreaterThanOrEqual(2, Activity::where('log_name', 'news')->where('subject_id', $news->id)->where('event', 'updated')->count());
    }

    public function test_admin_deletes_an_article(): void
    {
        $this->asAdmin();
        $news = $this->news();

        Livewire::test(ListNews::class)->callTableAction('delete', $news);

        $this->assertNull(News::find($news->id));
        $this->getJson("/api/news/{$news->slug}")->assertStatus(404);
    }

    public function test_permissions_per_action(): void
    {
        $news = $this->news();
        $editor = User::create(['name' => 'Editor', 'email' => 'e@test.local', 'password' => 'x']);
        $editor->assignRole('kasir');
        $noMenu = User::create(['name' => 'Tanpa Menu', 'email' => 'n@test.local', 'password' => 'x', 'menu_access' => ['SomeOtherResource']]);
        $noMenu->assignRole('kasir');
        $admin = $this->asAdmin();

        // Staf dengan menu: boleh melihat/buat/ubah, TIDAK boleh menghapus (default ketat).
        $this->assertTrue($editor->can('viewAny', News::class));
        $this->assertTrue($editor->can('create', News::class));
        $this->assertTrue($editor->can('update', $news));
        $this->assertFalse($editor->can('delete', $news));

        // Tanpa menu: tidak boleh apa pun.
        $this->assertFalse($noMenu->can('viewAny', News::class));
        $this->assertFalse($noMenu->can('create', News::class));
        $this->assertFalse($noMenu->can('delete', $news));

        $this->assertTrue($admin->can('delete', $news));
    }
}
