<?php

namespace Tests\Feature;

use App\Filament\Pages\Panduan;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminTour;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tur pengenalan panel admin: tampil sekali per staf (sampai ditandai selesai), bisa diulang lewat ?tur=1, hanya untuk
 * staf panel, dan penandaan selesai tersimpan di database.
 */
class AdminTourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('installer', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role): User
    {
        $store = Store::first() ?? Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_the_tour_shows_once_until_it_is_marked_done(): void
    {
        $staff = $this->user('kasir');

        $this->assertNull($staff->admin_tour_seen_at);
        $this->assertTrue(AdminTour::shouldShow($staff));

        $staff->forceFill(['admin_tour_seen_at' => now()])->save();

        $this->assertFalse(AdminTour::shouldShow($staff->fresh()));
    }

    public function test_the_tour_can_be_replayed_on_demand(): void
    {
        $staff = $this->user('kasir');
        $staff->forceFill(['admin_tour_seen_at' => now()])->save();

        $this->assertTrue(AdminTour::shouldShow($staff->fresh(), forced: true));
    }

    public function test_the_tour_never_shows_for_guests_or_non_panel_roles(): void
    {
        $this->assertFalse(AdminTour::shouldShow(null));
        $this->assertFalse(AdminTour::shouldShow(null, forced: true));
        $this->assertFalse(AdminTour::shouldShow($this->user('installer'), forced: true), 'Installer tidak memakai panel admin.');
    }

    public function test_finishing_the_tour_is_saved_for_the_logged_in_user_only(): void
    {
        $staff = $this->user('kasir');
        $other = $this->user('kasir');

        $this->actingAs($staff, 'web')->get('/admin-tour/selesai')->assertNoContent();
        $this->get('/admin-tour/selesai')->assertNoContent(); // idempoten

        $this->assertNotNull($staff->fresh()->admin_tour_seen_at);
        $this->assertNull($other->fresh()->admin_tour_seen_at);
    }

    public function test_the_done_endpoint_requires_login(): void
    {
        $this->getJson('/admin-tour/selesai')->assertUnauthorized();
    }

    public function test_the_tour_view_lists_the_steps_and_the_done_url(): void
    {
        $html = view('filament.admin-tour')->render();

        foreach (AdminTour::steps() as $step) {
            $this->assertStringContainsString($step['title'], $html);
        }

        $this->assertStringContainsString('admin-tour\/selesai', $html, 'Alamat penanda selesai ikut ditulis ke skrip.');
        $this->assertGreaterThanOrEqual(4, count(AdminTour::steps()));
    }

    public function test_the_guide_page_offers_to_replay_the_tour(): void
    {
        $this->actingAs($this->user('kasir'), 'web');

        Livewire::test(Panduan::class)->assertSee('Ulangi tur pengenalan')->assertSee('?tur=1', false);
    }
}
