<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Installer hanya mengerjakan di lapangan (2026-11-02): API booking tidak lagi mengirim telepon pelanggan, nominal, pembayaran,
 * referral, voucher, atau partner kepada installer. Akun lain tetap menerima data lengkap.
 */
class InstallerBookingPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private const SECRET_FIELDS = [
        'phone_number', 'transaction_amount', 'saving', 'dpp_amount', 'ppn_amount', 'amount_received', 'payment_method',
        'referral_code', 'partner_id', 'voucher_claim_id', 'consent_at', 'source', 'cancel_reason',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['store_manager', 'installer', 'installer_leader'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function booking(): Booking
    {
        $customer = Customer::create(['name' => 'Budi Santoso', 'phone_number' => '081234567890']);

        return Booking::create([
            'booking_number' => 'BKG-P-' . uniqid(), 'customer_id' => $customer->id, 'customer_name' => 'Budi Santoso', 'phone_number' => '081234567890',
            'store_id' => $this->store->id, 'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true,
            'preferred_date' => now()->addDays(3)->toDateString(), 'status' => 'confirmed', 'transaction_amount' => 7500000, 'amount_received' => 2000000,
            'payment_method' => 'transfer', 'referral_code' => 'REF-ABC', 'notes' => 'Hati-hati bumper depan', 'vehicle_plate' => 'B 1234 XYZ',
            'vehicle_brand' => 'Toyota', 'vehicle_model' => 'Fortuner', 'vehicle_color' => 'Hitam',
        ]);
    }

    public function test_an_installer_receives_only_the_work_fields_of_a_booking(): void
    {
        $installer = $this->staff('installer');
        $booking = $this->booking();
        $booking->installers()->attach($installer->id);

        $data = $this->actingAs($installer, 'api')->getJson("/api/staff/bookings/{$booking->id}")->assertSuccessful()->json('data');

        foreach (self::SECRET_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $data, "Installer tidak boleh menerima {$field}.");
        }
        $this->assertArrayNotHasKey('phone_number', $data['customer'], 'Kontak pelanggan tidak ikut.');
        $this->assertArrayNotHasKey('watchers', $data);
        $this->assertSame('Budi Santoso', $data['customer']['name']);
        $this->assertSame('B 1234 XYZ', $data['vehicle_plate']);
        $this->assertSame('Hati-hati bumper depan', $data['notes']);
        $this->assertSame([$installer->id], array_column($data['installers'], 'id'));
        $this->assertArrayHasKey('film_products', $data);
        $this->assertSame('Toko A', $data['store']['name']);
    }

    public function test_an_installers_list_has_no_contact_or_money_and_phone_search_is_ignored(): void
    {
        $installer = $this->staff('installer');
        $booking = $this->booking();
        $booking->installers()->attach($installer->id);

        $row = $this->actingAs($installer, 'api')->getJson('/api/staff/bookings')->assertSuccessful()->json('data.0');

        foreach (self::SECRET_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }
        $this->assertArrayNotHasKey('phone_number', $row['customer']);
        $this->assertSame($booking->id, $row['id']);

        $this->actingAs($installer, 'api')->getJson('/api/staff/bookings?q=081234567890')->assertSuccessful()->assertJsonCount(0, 'data');
        $this->actingAs($installer, 'api')->getJson('/api/staff/bookings?q=Budi')->assertSuccessful()->assertJsonCount(1, 'data');
    }

    public function test_managers_and_leaders_still_receive_the_full_booking(): void
    {
        $booking = $this->booking();

        foreach (['store_manager', 'installer_leader'] as $role) {
            $data = $this->actingAs($this->staff($role), 'api')->getJson("/api/staff/bookings/{$booking->id}")->assertSuccessful()->json('data');

            $this->assertSame('081234567890', $data['customer']['phone_number'], $role);
            $this->assertArrayHasKey('transaction_amount', $data, $role);
        }

        $this->assertSame(1, count($this->actingAs($this->staff('store_manager'), 'api')->getJson('/api/staff/bookings?q=081234567890')->json('data')));
    }
}
