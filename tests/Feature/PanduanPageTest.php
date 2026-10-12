<?php

namespace Tests\Feature;

use App\Filament\Pages\Panduan;
use App\Models\Store;
use App\Models\User;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman Panduan staf: terbuka untuk semua staf panel, hanya menampilkan panduan modul yang bisa diakses, bisa dicari,
 * dan setiap panduan di katalog lengkap.
 */
class PanduanPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        $store = Store::first() ?? Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function titles($component): array
    {
        return collect($component->instance()->getGuides())->pluck('title')->all();
    }

    public function test_every_guide_in_the_catalog_is_complete(): void
    {
        $ids = [];

        foreach (GuideCatalog::all() as $guide) {
            $this->assertNotEmpty($guide['id']);
            $this->assertNotEmpty($guide['title']);
            $this->assertNotEmpty($guide['summary']);
            $this->assertNotEmpty($guide['steps'], "Panduan {$guide['id']} tanpa langkah.");
            $this->assertIsArray($guide['menus']);
            $this->assertIsArray($guide['tips']);
            $ids[] = $guide['id'];
        }

        $this->assertSame(array_unique($ids), $ids, 'ID panduan harus unik.');
    }

    public function test_full_access_sees_every_guide(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $page = Livewire::test(Panduan::class)->assertSuccessful();

        $this->assertCount(count(GuideCatalog::all()), $page->instance()->getGuides());
        $page->assertSee('Booking: dari masuk sampai selesai')->assertSee('Reward dan Voucher');
    }

    public function test_staff_only_sees_guides_for_menus_they_can_open(): void
    {
        // Akses menu dibatasi ke Booking saja: panduan Booking dan Istilah tampil, Reward dan Stok tidak.
        $this->actingAs($this->user('kasir', ['BookingResource']), 'web');

        $titles = $this->titles(Livewire::test(Panduan::class));

        $this->assertContains('Booking: dari masuk sampai selesai', $titles);
        $this->assertContains('Istilah yang sering muncul', $titles);
        $this->assertNotContains('Reward dan Voucher', $titles);
        $this->assertNotContains('Stok dan Inventaris', $titles);
    }

    public function test_search_runs_in_the_browser_without_livewire_requests(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $page = Livewire::test(Panduan::class);

        // Semua panduan dirender sekali dan disaring di browser (Alpine), bukan lewat permintaan Livewire.
        $page->assertSee('x-model="q"', false)->assertSee('data-guide="booking"', false);

        // Teks pencarian tiap panduan memuat isinya (huruf kecil, tanpa penanda tebal).
        $voucher = collect(GuideCatalog::all())->firstWhere('id', 'reward-voucher');
        $text = Panduan::searchText($voucher);
        $this->assertStringContainsString('voucher diskon', $text);
        $this->assertStringNotContainsString('**', $text);
        $this->assertSame(mb_strtolower($text), $text);
    }
    public function test_the_page_is_open_to_staff_even_with_a_restricted_menu(): void
    {
        $this->actingAs($this->user('kasir', ['BookingResource']), 'web');

        $this->assertTrue(Panduan::canAccess());
    }

    public function test_the_guide_is_reachable_from_the_menu_without_typing_the_url(): void
    {
        // Dropdown "Lainnya" di bar atas.
        $this->assertSame('Lainnya', Panduan::getNavigationGroup());

        // Pintasan di menu avatar.
        $labels = collect(Filament::getPanel('admin')->getUserMenuItems())->map(fn ($item) => $item->getLabel())->all();
        $this->assertContains('Panduan', $labels);
    }

    public function test_bold_markers_are_rendered_safely(): void
    {
        $this->assertSame('a <strong>b</strong> c', Panduan::format('a **b** c'));
        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', Panduan::format('<script>x</script>'));
    }
}
