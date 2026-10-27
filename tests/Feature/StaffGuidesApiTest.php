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

        foreach (['kasir', 'installer', 'installer_leader', 'store_manager', 'super_admin'] as $role) {
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

        foreach (['konfirmasi dan pembatalan booking', 'chat dengan pelanggan', 'Quality Check', 'serah terima'] as $leaderOnly) {
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

    public function test_the_leader_guide_is_only_for_leaders_and_full_access(): void
    {
        $leader = $this->ids($this->actingAs($this->user('installer_leader'), 'api')->getJson('/api/staff/guides'));
        $this->assertContains('leader-harian', $leader);
        $this->assertContains('booking', $leader, 'Leader adalah staff toko dan tetap melihat panduan Booking.');
        $this->assertNotContains('installer-mulai', $leader);

        foreach (['installer', 'kasir'] as $role) {
            $ids = $this->ids($this->actingAs($this->user($role), 'api')->getJson('/api/staff/guides'));
            $this->assertNotContains('leader-harian', $ids, "{$role} tidak boleh melihat panduan leader.");
        }

        $admin = $this->ids($this->actingAs($this->user('super_admin'), 'api')->getJson('/api/staff/guides'));
        $this->assertContains('leader-harian', $admin);
    }

    public function test_the_leader_guide_matches_what_the_api_allows_and_denies_a_leader(): void
    {
        $leader = $this->user('installer_leader');
        $customer = \App\Models\Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = \App\Models\Booking::create([
            'booking_number' => 'BKG-LD-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $leader->store_id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(4)->toDateString(),
            'status' => 'confirmed',
        ]);

        // Yang panduan sebut TIDAK bisa dilakukan leader.
        $this->actingAs($leader, 'api')->postJson("/api/staff/bookings/{$booking->id}/cancel", ['reason' => 'tes'])->assertStatus(403);
        $this->actingAs($leader, 'api')->postJson("/api/staff/bookings/{$booking->id}/no-show", [])->assertStatus(403);
        $this->actingAs($leader, 'api')->postJson("/api/staff/bookings/{$booking->id}/stage-correction", ['stage' => 'qc', 'reason' => 'tes'])->assertStatus(403);

        // Yang panduan sebut bisa dilakukan leader: tidak ditolak karena hak akses (boleh gagal validasi).
        foreach ([
            ['post', "/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Unit sudah diterima']],
            ['post', "/api/staff/bookings/{$booking->id}/handover", []],
            ['post', "/api/staff/bookings/{$booking->id}/intake-photos", []],
            ['put', "/api/staff/bookings/{$booking->id}/installers", ['installer_user_ids' => []]],
            ['get', "/api/staff/bookings/{$booking->id}/qc", []],
        ] as [$method, $url, $payload]) {
            $status = $this->actingAs($leader, 'api')->json($method, $url, $payload)->status();
            $this->assertNotSame(403, $status, "Leader seharusnya tidak ditolak di {$method} {$url} (panduan menyebut ini tugas leader).");
        }

        $this->assertTrue($leader->canCreateSpk(), 'Panduan menyebut leader membuat SPK.');
    }

    public function test_the_store_manager_guide_is_only_for_store_managers_and_full_access(): void
    {
        $sm = $this->ids($this->actingAs($this->user('store_manager'), 'api')->getJson('/api/staff/guides'));
        $this->assertContains('store-manager-harian', $sm);
        $this->assertNotContains('leader-harian', $sm);

        foreach (['installer_leader', 'kasir', 'installer'] as $role) {
            $ids = $this->ids($this->actingAs($this->user($role), 'api')->getJson('/api/staff/guides'));
            $this->assertNotContains('store-manager-harian', $ids, "{$role} tidak boleh melihat panduan Store Manager.");
        }

        $admin = $this->ids($this->actingAs($this->user('super_admin'), 'api')->getJson('/api/staff/guides'));
        $this->assertContains('store-manager-harian', $admin);
    }

    public function test_the_store_manager_guide_matches_what_only_a_store_manager_can_do(): void
    {
        $sm = $this->user('store_manager');
        $leader = $this->user('installer_leader');
        $customer = \App\Models\Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = \App\Models\Booking::create([
            'booking_number' => 'BKG-SM-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $sm->store_id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(4)->toDateString(),
            'status' => 'confirmed',
        ]);

        // Wewenang yang panduan sebut milik Store Manager: leader ditolak, Store Manager tidak.
        foreach ([
            ["/api/staff/bookings/{$booking->id}/cancel", ['reason' => 'tes']],
            ["/api/staff/bookings/{$booking->id}/no-show", []],
            ["/api/staff/bookings/{$booking->id}/stage-correction", ['stage' => 'qc', 'reason' => 'tes']],
        ] as [$url, $payload]) {
            $this->actingAs($leader, 'api')->postJson($url, $payload)->assertStatus(403);
            $this->assertNotSame(403, $this->actingAs($sm, 'api')->postJson($url, $payload)->status(), "Store Manager seharusnya tidak ditolak di {$url}.");
        }

        // Persetujuan pengeluaran di aplikasi: Store Manager boleh, leader tidak.
        $this->actingAs($sm, 'api')->getJson('/api/staff/finance/approvals')->assertOk();
        $this->actingAs($leader, 'api')->getJson('/api/staff/finance/approvals')->assertStatus(403);
    }

    public function test_the_dashboard_guide_is_open_to_restricted_panel_staff_but_not_installers(): void
    {
        $kasir = $this->ids($this->actingAs($this->user('kasir', ['InvoiceResource']), 'api')->getJson('/api/staff/guides'));
        $this->assertContains('dashboard', $kasir, 'Dashboard utama terbuka untuk semua staff panel, jadi panduannya juga.');

        $installer = $this->ids($this->actingAs($this->user('installer'), 'api')->getJson('/api/staff/guides'));
        $this->assertNotContains('dashboard', $installer);
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
