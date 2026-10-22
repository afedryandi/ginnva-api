<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Support\GuideCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panduan di aplikasi staf (Panduan Fase 3): isinya sama dengan panel, installer hanya mendapat panduan aplikasi, dan butuh login.
 */
class StaffGuidesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['kasir', 'installer', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function user(string $role, ?array $menuAccess = null): User
    {
        $store = Store::first() ?? Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function ids($response): array
    {
        return collect($response->assertOk()->json('guides'))->pluck('id')->all();
    }

    public function test_the_guides_require_a_staff_login(): void
    {
        $this->getJson('/api/staff/guides')->assertStatus(401);
    }

    public function test_the_app_only_guide_list_names_real_guides(): void
    {
        $all = collect(GuideCatalog::all())->pluck('id')->all();

        foreach (GuideCatalog::APP_ONLY_GUIDE_IDS as $id) {
            $this->assertContains($id, $all, "Panduan aplikasi `{$id}` tidak ada di katalog.");
        }
    }

    public function test_an_installer_only_gets_the_installer_guides_even_without_a_menu_limit(): void
    {
        $installerGuides = collect(GuideCatalog::all())->where('audience', 'installer')->pluck('id')->all();
        $this->assertNotEmpty($installerGuides);

        $ids = $this->ids($this->actingAs($this->user('installer'), 'api')->getJson('/api/staff/guides'));

        $this->assertEqualsCanonicalizing(array_merge(GuideCatalog::APP_ONLY_GUIDE_IDS, $installerGuides), $ids);
        foreach (['penggajian', 'transaksi-keuangan', 'booking', 'layanan-ppf'] as $hidden) {
            $this->assertNotContains($hidden, $ids, "Installer tidak boleh melihat `{$hidden}`: menjelaskan aksi yang ditolak untuk installer.");
        }
    }

    public function test_installer_guides_are_hidden_from_other_panel_staff(): void
    {
        $kasir = $this->ids($this->actingAs($this->user('kasir'), 'api')->getJson('/api/staff/guides'));

        $this->assertNotContains('installer-mulai', $kasir);
        $this->assertNotContains('installer-pekerjaan', $kasir);
        $this->assertContains('booking', $kasir);
    }

    public function test_the_installer_guides_describe_what_the_api_really_denies_installers(): void
    {
        $start = collect(GuideCatalog::all())->firstWhere('id', 'installer-mulai');
        $text = implode(' ', $start['steps']);

        foreach (['konfirmasi dan pembatalan booking', 'chat dengan customer', 'Quality Check', 'serah terima'] as $leaderOnly) {
            $this->assertStringContainsString($leaderOnly, $text);
        }

        $installer = $this->user('installer');
        $this->assertFalse($installer->canCreateSpk(), 'Panduan menyebut installer biasa tidak membuat SPK baru.');

        $customer = \App\Models\Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = \App\Models\Booking::create([
            'booking_number' => 'BKG-GD-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $installer->store_id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(4)->toDateString(),
            'status' => 'confirmed',
        ]);
        $booking->installers()->attach($installer->id);

        $this->actingAs($installer, 'api')->postJson("/api/staff/bookings/{$booking->id}/handover", [])->assertStatus(403);
        $this->actingAs($installer, 'api')->postJson("/api/staff/bookings/{$booking->id}/intake-photos", [])->assertStatus(403);
        $this->actingAs($installer, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['message' => 'tes'])->assertStatus(403);
    }

    public function test_full_access_gets_every_guide_and_restricted_staff_only_their_modules(): void
    {
        $all = collect(GuideCatalog::all())->pluck('id')->all();

        $admin = $this->ids($this->actingAs($this->user('super_admin'), 'api')->getJson('/api/staff/guides'));
        $this->assertEqualsCanonicalizing($all, $admin);

        $kasir = $this->ids($this->actingAs($this->user('kasir', ['InvoiceResource']), 'api')->getJson('/api/staff/guides'));
        $this->assertContains('invoice', $kasir);
        $this->assertNotContains('penggajian', $kasir);
    }

    public function test_the_app_returns_the_same_text_as_the_panel(): void
    {
        $guide = collect($this->actingAs($this->user('super_admin'), 'api')->getJson('/api/staff/guides')->assertOk()->json('guides'))
            ->firstWhere('id', 'absensi');
        $catalog = collect(GuideCatalog::all())->firstWhere('id', 'absensi');

        $this->assertSame($catalog['steps'], $guide['steps']);
        $this->assertSame($catalog['tips'], $guide['tips']);
        $this->assertSame($catalog['title'], $guide['title']);
    }
}
