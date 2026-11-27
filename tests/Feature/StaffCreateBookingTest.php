<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staf membuat booking untuk pelanggan walk-in atau WhatsApp dari aplikasi (2026-11-27): tanpa akun pelanggan, aturan sama dengan panel
 * (tanggal, toko buka, kapasitas, slot jam layanan ringan, tidak ganda).
 */
class StaffCreateBookingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['store_manager', 'installer', 'partner', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source' => 'walk_in',
            'customer_name' => 'Budi Santoso',
            'phone_number' => '081234567890',
            'services' => ['kaca_film'],
            'preferred_date' => now()->addDays(3)->toDateString(),
        ], $overrides);
    }

    public function test_a_store_manager_creates_a_confirmed_walk_in_booking_without_a_customer_account(): void
    {
        $response = $this->actingAs($this->staff('store_manager'), 'api')
            ->postJson('/api/staff/bookings', $this->payload([
                'services' => ['ppf'], 'ppf_scope' => 'partial', 'vehicle_plate' => 'b1234abc', 'vehicle_brand' => 'Toyota',
                'vehicle_model' => 'Fortuner', 'vehicle_size' => 'L', 'notes' => 'Minta cepat',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'confirmed');

        $booking = Booking::findOrFail($response->json('data.id'));

        $this->assertSame('walk_in', $booking->source);
        $this->assertNull($booking->customer_id, 'Tidak ditautkan ke akun pelanggan, sama seperti booking manual di panel.');
        $this->assertSame('Budi Santoso', $booking->customer_name);
        $this->assertSame('081234567890', $booking->phone_number);
        $this->assertSame($this->store->id, (int) $booking->store_id);
        $this->assertTrue((bool) $booking->product_ppf);
        $this->assertSame('Pelindung Cat (PPF)', $booking->service_type);
        $this->assertSame(4, (int) $booking->duration_days, 'Perkiraan awal PPF 4 hari.');
        $this->assertSame('B 1234 ABC', $booking->vehicle_plate);
        $this->assertNotNull($booking->confirmed_at);
    }

    public function test_ppf_full_body_brings_detailing_and_premium_wash_along(): void
    {
        $id = $this->actingAs($this->staff('store_manager'), 'api')
            ->postJson('/api/staff/bookings', $this->payload(['services' => ['ppf'], 'ppf_exterior' => true, 'ppf_scope' => 'full_body']))
            ->assertStatus(201)->json('data.id');

        $booking = Booking::findOrFail($id);

        $this->assertTrue((bool) $booking->product_ppf);
        $this->assertTrue((bool) $booking->product_detailing);
        $this->assertTrue((bool) $booking->product_premium_wash);
        $this->assertTrue((bool) $booking->ppf_exterior);
        $this->assertSame('full_body', $booking->ppf_scope);
    }

    public function test_light_services_need_an_arrival_slot_when_confirmed_right_away(): void
    {
        $staff = $this->staff('store_manager');
        $light = $this->payload(['services' => ['detailing']]);

        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $light)->assertStatus(422)->assertJsonPath('errors.preferred_time.0', 'Pilih jam kedatangan untuk layanan ini.');

        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $light + ['preferred_time' => Booking::lightSlots()[0]])->assertStatus(201);

        // Tanpa konfirmasi langsung, jam belum wajib.
        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload(['services' => ['premium_wash'], 'confirm' => false]))
            ->assertStatus(201)->assertJsonPath('data.status', 'pending');
    }

    public function test_a_full_day_blocks_a_confirmed_booking_but_not_a_pending_one(): void
    {
        $this->store->update(['install_capacity_per_day' => 1]);
        $date = now()->addDays(3)->toDateString();
        $other = Customer::create(['name' => 'Siti', 'phone_number' => '0813' . random_int(1000000, 9999999)]);
        Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $other->id, 'store_id' => $this->store->id,
            'service_type' => 'Kaca Film (Window Film)', 'product_kaca_film' => true, 'preferred_date' => $date, 'status' => 'confirmed', 'duration_days' => 1,
        ]);
        $staff = $this->staff('store_manager');

        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload(['preferred_date' => $date]))
            ->assertStatus(422)->assertJsonPath('errors.preferred_date.0', 'Tanggal penuh.');

        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload(['preferred_date' => $date, 'confirm' => false]))->assertStatus(201);
    }

    public function test_past_dates_and_duplicates_and_bad_plates_are_refused(): void
    {
        $staff = $this->staff('store_manager');

        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload(['preferred_date' => now()->subDay()->toDateString()]))->assertStatus(422);
        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload(['vehicle_plate' => '!!!']))->assertStatus(422)->assertJsonPath('errors.vehicle_plate.0', 'Format plat nomor tidak valid. Contoh: B 1234 ABC.');
        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload(['vehicle_year' => 1900]))->assertStatus(422);

        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload())->assertStatus(201);
        $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload())->assertStatus(422);
        $this->assertSame(1, Booking::where('phone_number', '081234567890')->count());
    }

    private function product(string $sku, string $type): FilmProduct
    {
        return FilmProduct::create(['sku' => $sku, 'name' => "Produk {$sku}", 'product_type' => $type, 'position' => 'front', 'base_price' => 100000, 'is_active' => true]);
    }

    public function test_the_form_asks_which_parts_and_products_can_be_chosen_for_the_selected_services(): void
    {
        $this->product('WF-1', 'window_film');
        $this->product('PPF-1', 'ppf');
        $staff = $this->staff('store_manager');

        $options = $this->actingAs($staff, 'api')
            ->getJson('/api/staff/bookings/film-product-options?services[]=kaca_film&services[]=ppf&ppf_exterior=1&ppf_scope=partial')
            ->assertOk()->json('data');

        $positions = collect($options['parts'])->pluck('position')->all();
        $this->assertContains('Seluruh Bagian PPF', $positions);
        $this->assertContains('Seluruh Kaca', $positions);
        $this->assertContains('Kaca Depan', $positions);
        $this->assertGreaterThan(2, count(array_filter($positions, fn ($p) => ! in_array($p, ['Seluruh Bagian PPF', 'Seluruh Kaca', 'Kaca Depan', 'Kaca Samping', 'Kaca Belakang', 'Sun Roof'], true))), 'PPF Part membuka panel satu per satu.');
        // Katalog bawaan migrasi ikut terdaftar, jadi yang dicek adalah produk uji ada di jenis yang benar dan tidak tertukar.
        $this->assertContains('PPF-1 — Produk PPF-1', array_column($options['products']['ppf'], 'label'));
        $this->assertNotContains('PPF-1 — Produk PPF-1', array_column($options['products']['window_film'], 'label'));
        $this->assertContains('WF-1 — Produk WF-1', array_column($options['products']['window_film'], 'label'));
        $this->assertNotContains('WF-1 — Produk WF-1', array_column($options['products']['ppf'], 'label'));

        $only = $this->actingAs($staff, 'api')->getJson('/api/staff/bookings/film-product-options?services[]=detailing')->assertOk()->json('data.parts');
        $this->assertSame([], $only, 'Detailing tidak punya bagian produk.');

        $this->actingAs($this->staff('installer'), 'api')->getJson('/api/staff/bookings/film-product-options')->assertForbidden();
    }

    public function test_products_per_part_are_saved_and_the_first_one_becomes_the_main_product(): void
    {
        $wfAll = $this->product('WF-ALL', 'window_film');
        $ppf = $this->product('PPF-ALL', 'ppf');

        $id = $this->actingAs($this->staff('store_manager'), 'api')->postJson('/api/staff/bookings', $this->payload([
            'services' => ['kaca_film', 'ppf'], 'ppf_scope' => 'full_body',
            'film_products' => [
                ['position' => 'Seluruh Kaca', 'film_product_id' => $wfAll->id],
                ['position' => 'Seluruh Bagian PPF', 'film_product_id' => $ppf->id],
            ],
        ]))->assertStatus(201)->json('data.id');

        $booking = Booking::with('filmProducts')->findOrFail($id);

        $this->assertCount(2, $booking->filmProducts);
        $this->assertSame(['Seluruh Kaca', 'Seluruh Bagian PPF'], $booking->filmProducts->pluck('position')->all());
        $this->assertSame($wfAll->id, (int) $booking->film_product_id, 'Produk pertama menjadi produk utama booking.');
    }

    public function test_a_wrong_part_a_wrong_type_a_duplicate_or_an_inactive_product_is_refused(): void
    {
        $wf = $this->product('WF-A', 'window_film');
        $ppf = $this->product('PPF-A', 'ppf');
        $off = $this->product('WF-OFF', 'window_film');
        $off->update(['is_active' => false]);
        $staff = $this->staff('store_manager');
        $post = fn (array $rows, array $services = ['kaca_film']) => $this->actingAs($staff, 'api')->postJson('/api/staff/bookings', $this->payload(['services' => $services, 'film_products' => $rows]));

        $post([['position' => 'Seluruh Bagian PPF', 'film_product_id' => $ppf->id]])->assertStatus(422);            // bagian PPF tanpa layanan PPF
        $post([['position' => 'Seluruh Kaca', 'film_product_id' => $ppf->id]])->assertStatus(422);                  // produk PPF di bagian kaca
        $post([['position' => 'Seluruh Kaca', 'film_product_id' => $wf->id], ['position' => 'Seluruh Kaca', 'film_product_id' => $wf->id]])->assertStatus(422);
        $post([['position' => 'Seluruh Kaca', 'film_product_id' => $off->id]])->assertStatus(422);                  // nonaktif

        $this->assertSame(0, Booking::count(), 'Tidak ada booking tersimpan dari permintaan yang ditolak.');
        $post([['position' => 'Seluruh Kaca', 'film_product_id' => $wf->id]])->assertStatus(201);
    }

    public function test_installers_and_partners_cannot_create_bookings_and_full_access_must_pick_a_store(): void
    {
        $this->actingAs($this->staff('installer'), 'api')->postJson('/api/staff/bookings', $this->payload())->assertForbidden();
        $this->actingAs($this->staff('partner'), 'api')->postJson('/api/staff/bookings', $this->payload())->assertForbidden();

        $director = $this->staff('direksi');
        $this->actingAs($director, 'api')->postJson('/api/staff/bookings', $this->payload())->assertStatus(422)->assertJsonValidationErrors('store_id');

        $id = $this->actingAs($director, 'api')->postJson('/api/staff/bookings', $this->payload(['store_id' => $this->store->id]))->assertStatus(201)->json('data.id');
        $this->assertSame($this->store->id, (int) Booking::findOrFail($id)->store_id);
    }
}
