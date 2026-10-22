<?php

namespace Tests\Feature;

use App\Filament\Pages\Panduan;
use App\Models\User;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tampilan pengelompokan Panduan: API mengurutkan menurut kelompok, dan halaman panel punya judul kelompok yang bisa dibuka-tutup
 * (tombol dengan aria-expanded), dengan pencarian tetap berjalan di browser.
 */
class GuideGroupsDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        return tap(User::create(['name' => 'Admin ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin'));
    }

    public function test_the_api_returns_the_category_in_display_order(): void
    {
        $guides = $this->actingAs($this->admin(), 'api')->getJson('/api/staff/guides')->assertOk()->json('guides');

        $this->assertSame('Mulai dari peran Anda', $guides[0]['category_label']);

        $order = array_flip(array_keys(GuideCatalog::CATEGORIES));
        $positions = array_map(fn ($guide) => $order[$guide['category']], $guides);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Panduan harus berurutan menurut kelompok.');
    }

    public function test_the_panel_has_collapsible_group_headings(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin(), 'web');

        $html = Livewire::test(Panduan::class)->html();

        $this->assertStringContainsString('data-guide-group="peran"', $html);
        $this->assertStringContainsString('data-guide-group="keuangan"', $html);
        $this->assertStringContainsString('aria-expanded', $html, 'Judul kelompok harus berupa tombol buka-tutup.');
        $this->assertStringContainsString("toggle('keuangan')", $html);
        $this->assertStringContainsString('data-guide="booking"', $html, 'Isi panduan tetap ada di halaman agar bisa dicari.');
    }
}
