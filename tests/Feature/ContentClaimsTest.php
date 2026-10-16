<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductClaimResource\Pages\ListProductClaims;
use App\Models\Carousel;
use App\Models\News;
use App\Models\ProductClaim;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Marketing/Konten Fase 2 "Klaim dan konten" (2026-10-24): jadwal tayang dan teks alternatif banner, SEO berita, serta klaim produk
 * dengan bukti dan persetujuan sebagai satu-satunya sumber angka yang boleh tayang.
 */
class ContentClaimsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'super_admin'): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]);
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        return $user;
    }

    private function claim(array $over = []): ProductClaim
    {
        return ProductClaim::create($over + ['series' => 'A70', 'claim_type' => 'tser', 'label' => 'TSER', 'value' => '61%', 'status' => 'draft']);
    }

    // ------------------------------------------------------------- banner

    public function test_banners_only_air_inside_their_schedule_window(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');

        Carousel::create(['title' => 'Tanpa jadwal', 'image' => 'a.jpg', 'audience' => 'customer', 'is_active' => true, 'alt_text' => 'Promo A']);
        Carousel::create(['title' => 'Sedang tayang', 'image' => 'b.jpg', 'audience' => 'both', 'is_active' => true, 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-30']);
        Carousel::create(['title' => 'Belum mulai', 'image' => 'c.jpg', 'audience' => 'customer', 'is_active' => true, 'starts_at' => '2026-12-01']);
        Carousel::create(['title' => 'Sudah berakhir', 'image' => 'd.jpg', 'audience' => 'customer', 'is_active' => true, 'ends_at' => '2026-11-05']);
        Carousel::create(['title' => 'Dimatikan', 'image' => 'e.jpg', 'audience' => 'customer', 'is_active' => false]);

        $titles = collect($this->getJson('/api/carousels')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(['Tanpa jadwal', 'Sedang tayang'], $titles->pluck('title')->all());
        $this->assertSame('Promo A', $titles->firstWhere('title', 'Tanpa jadwal')['alt_text']);

        $this->assertSame('terjadwal', Carousel::where('title', 'Belum mulai')->first()->airingStatus());
        $this->assertSame('berakhir', Carousel::where('title', 'Sudah berakhir')->first()->airingStatus());
        $this->assertSame('tayang', Carousel::where('title', 'Sedang tayang')->first()->airingStatus());
        $this->assertSame('nonaktif', Carousel::where('title', 'Dimatikan')->first()->airingStatus());

        Carbon::setTestNow();
    }

    // ------------------------------------------------------------- berita

    public function test_the_news_api_exposes_the_seo_fields(): void
    {
        News::create([
            'title' => 'Tips Merawat Kaca Film', 'slug' => 'tips-merawat-kaca-film', 'excerpt' => 'Ringkas', 'content' => '<p>Isi</p>',
            'meta_title' => 'Merawat Kaca Film | Ginnva', 'meta_description' => 'Cara merawat kaca film agar awet.', 'category' => 'Tips Perawatan',
            'cover_alt' => 'Teknisi membersihkan kaca film', 'is_published' => true, 'published_at' => now()->subDay(),
        ]);

        $this->getJson('/api/news/tips-merawat-kaca-film')->assertOk()
            ->assertJsonPath('data.meta_title', 'Merawat Kaca Film | Ginnva')
            ->assertJsonPath('data.meta_description', 'Cara merawat kaca film agar awet.')
            ->assertJsonPath('data.category', 'Tips Perawatan')
            ->assertJsonPath('data.cover_alt', 'Teknisi membersihkan kaca film');
    }

    // ------------------------------------------------------------- klaim produk

    public function test_only_approved_claims_are_public(): void
    {
        $this->claim(['value' => '61%']);                                                              // draft
        $this->claim(['claim_type' => 'uv', 'label' => 'UV', 'value' => '99%', 'status' => 'approved', 'tested_at' => '2026-03-01', 'source_note' => 'Lembar data']);
        $this->claim(['series' => 'H30', 'value' => '56%', 'status' => 'withdrawn']);

        $data = $this->getJson('/api/product-claims')->assertOk()->json('data');

        $this->assertSame(['A70'], array_keys($data));
        $this->assertCount(1, $data['A70']);
        $this->assertSame('99%', $data['A70'][0]['value']);
        $this->assertSame('uv', $data['A70'][0]['type']);

        $this->getJson('/api/product-claims?series=H30')->assertOk()->assertJsonPath('data', []);
    }

    public function test_a_claim_cannot_be_approved_without_evidence_and_a_date(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(), 'web');
        $claim = $this->claim();

        $this->assertNotNull($claim->approvalBlocker());

        Livewire::test(ListProductClaims::class)->callTableAction('approve', $claim);
        $this->assertSame('draft', $claim->fresh()->status, 'Tanpa bukti tidak boleh disetujui.');

        $claim->update(['source_note' => 'Lembar data pabrikan rev. 2026-03']);
        $this->assertSame('Isi tanggal uji atau tanggal dokumen sumber.', $claim->fresh()->approvalBlocker());

        $claim->update(['tested_at' => '2026-03-01']);
        $this->assertNull($claim->fresh()->approvalBlocker());

        Livewire::test(ListProductClaims::class)->callTableAction('approve', $claim);

        $claim = $claim->fresh();
        $this->assertSame('approved', $claim->status);
        $this->assertNotNull($claim->approved_by);
        $this->assertNotNull($claim->approved_at);
        $this->assertSame(1, count($this->getJson('/api/product-claims')->json('data.A70')));
    }

    public function test_editing_an_approved_claim_sends_it_back_to_draft_and_off_the_site(): void
    {
        $claim = $this->claim(['status' => 'approved', 'source_note' => 'Lembar data', 'tested_at' => '2026-03-01', 'approved_by' => $this->user()->id, 'approved_at' => now()]);
        $this->assertCount(1, $this->getJson('/api/product-claims')->json('data.A70'));

        $claim->update(['value' => '65%']);

        $claim = $claim->fresh();
        $this->assertSame('draft', $claim->status);
        $this->assertNull($claim->approved_by);
        $this->assertNull($claim->approved_at);
        $this->getJson('/api/product-claims')->assertJsonPath('data', []);
    }

    public function test_only_full_access_users_can_approve_or_withdraw_and_withdrawn_claims_leave_the_site(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $draft = $this->claim(['source_note' => 'Lembar data', 'tested_at' => '2026-03-01']);
        $approved = $this->claim(['claim_type' => 'uv', 'label' => 'UV', 'value' => '99%', 'status' => 'approved', 'source_note' => 'x', 'tested_at' => '2026-03-01']);

        $this->actingAs($this->user('kasir'), 'web');
        Livewire::test(ListProductClaims::class)->assertTableActionHidden('approve', $draft)->assertTableActionHidden('withdraw', $approved);

        $this->actingAs($this->user('direksi'), 'web');
        Livewire::test(ListProductClaims::class)->callTableAction('withdraw', $approved);

        $this->assertSame('withdrawn', $approved->fresh()->status);
        $this->getJson('/api/product-claims')->assertJsonPath('data', []);
    }

    public function test_the_draft_seeding_command_is_idempotent_and_publishes_nothing(): void
    {
        $this->artisan('claims:seed-drafts')->assertSuccessful();
        $first = ProductClaim::count();
        $this->assertGreaterThan(10, $first);

        $this->artisan('claims:seed-drafts')->assertSuccessful();
        $this->assertSame($first, ProductClaim::count());

        $this->assertSame(0, ProductClaim::approved()->count());
        $this->getJson('/api/product-claims')->assertJsonPath('data', []);
    }
}
