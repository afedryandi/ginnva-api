<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Support\GuideCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panduan Admin/Kasir toko (admin-toko-harian) muncul bagi akun dengan menu Booking atau Invoice yang dicentang eksplisit, dan batas yang
 * ditulisnya (tidak bisa membatalkan, menandai tidak datang, mengoreksi tahap, atau menghapus; tetap bisa membuat dan mengonfirmasi) harus
 * benar-benar ditegakkan.
 */
class AdminKasirGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['kasir', 'installer', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role, ?array $menuAccess): User
    {
        $store = Store::first() ?? Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);

        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store->id, 'menu_access' => $menuAccess, 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function ids(User $user): array
    {
        return collect(GuideCatalog::forUser($user))->pluck('id')->all();
    }

    public function test_the_guide_needs_an_explicit_booking_or_invoice_menu(): void
    {
        $this->assertContains('admin-toko-harian', $this->ids($this->user('kasir', ['BookingResource'])));
        $this->assertContains('admin-toko-harian', $this->ids($this->user('kasir', ['InvoiceResource'])));
        $this->assertContains('admin-toko-harian', $this->ids($this->user('super_admin', null)));
        $this->assertNotContains('admin-toko-harian', $this->ids($this->user('kasir', null)), 'Akun tanpa batas menu tidak boleh otomatis melihat panduan ini.');
        $this->assertNotContains('admin-toko-harian', $this->ids($this->user('kasir', ['StoreResource'])));
        $this->assertNotContains('admin-toko-harian', $this->ids($this->user('installer', null)));
    }

    public function test_the_limits_the_guide_states_are_enforced(): void
    {
        $kasir = $this->user('kasir', ['BookingResource']);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-KS-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $kasir->store_id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => now()->addDays(4)->toDateString(),
            'status' => 'confirmed',
        ]);

        $this->actingAs($kasir, 'web');
        $this->assertTrue(BookingResource::canCreate(), 'Panduan menyebut admin toko membuat booking di panel.');
        $this->assertFalse(BookingResource::canDelete($booking), 'Panduan menyebut hapus tidak bisa tanpa hak tambahan.');

        foreach ([
            ["/api/staff/bookings/{$booking->id}/cancel", ['reason' => 'tes']],
            ["/api/staff/bookings/{$booking->id}/no-show", []],
            ["/api/staff/bookings/{$booking->id}/stage-correction", ['stage' => 'qc', 'reason' => 'tes']],
        ] as [$url, $payload]) {
            $this->actingAs($kasir, 'api')->postJson($url, $payload)->assertStatus(403);
        }

        $this->assertNotSame(403, $this->actingAs($kasir, 'api')->postJson("/api/staff/bookings/{$booking->id}/messages", ['type' => 'text', 'body' => 'Halo'])->status(), 'Panduan menyebut admin toko bisa chat dengan pelanggan.');
    }
}
