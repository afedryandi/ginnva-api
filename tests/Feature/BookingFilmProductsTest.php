<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingFilmProduct;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keputusan atasan 2026-09-19 (Topik 3, "Keputusan-PPN-DP-Produk-Stok-
 * Ginnva.docx"): 1 booking BISA pakai lebih dari 1 produk, dicatat
 * staff toko saat booking dikonfirmasi, per bagian kendaraan. SENGAJA
 * additif -- film_product_id (produk utama) TIDAK berubah perilakunya.
 */
class BookingFilmProductsTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $overrides = []): Booking
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'Kaca Film (Window Film)',
            'product_kaca_film' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'pending',
        ], $overrides));
    }

    private function makeFilmProduct(string $sku): FilmProduct
    {
        return FilmProduct::create([
            'sku' => $sku,
            'name' => "Produk {$sku}",
            'product_type' => 'window_film',
            'position' => 'front',
            'base_price' => 100_000,
            'is_active' => true,
        ]);
    }

    public function test_booking_can_have_primary_product_untouched_plus_multiple_additional_products(): void
    {
        $primary = $this->makeFilmProduct('WF-PRIMARY');
        $depan = $this->makeFilmProduct('WF-DEPAN');
        $belakang = $this->makeFilmProduct('WF-BELAKANG');

        $booking = $this->makeBooking(['film_product_id' => $primary->id]);
        $booking->filmProducts()->create(['film_product_id' => $depan->id, 'position' => 'Kaca Depan']);
        $booking->filmProducts()->create(['film_product_id' => $belakang->id, 'position' => 'Kaca Belakang']);

        $fresh = $booking->fresh();

        // Produk UTAMA tetap field tunggal seperti sebelumnya -- TIDAK
        // berubah jadi array/relasi, backward-compat 15+ file lain.
        $this->assertEquals($primary->id, $fresh->film_product_id);
        $this->assertEquals($primary->id, $fresh->filmProduct->id);

        // Produk TAMBAHAN ada di relasi terpisah, masing-masing dgn posisi.
        $this->assertCount(2, $fresh->filmProducts);
        $this->assertEquals(['Kaca Depan', 'Kaca Belakang'], $fresh->filmProducts->pluck('position')->all());
    }

    public function test_booking_without_additional_products_has_empty_collection(): void
    {
        $primary = $this->makeFilmProduct('WF-PRIMARY');
        $booking = $this->makeBooking(['film_product_id' => $primary->id]);

        $this->assertCount(0, $booking->filmProducts);
    }

    public function test_deleting_booking_cascades_to_its_additional_products(): void
    {
        $product = $this->makeFilmProduct('WF-DEPAN');
        $booking = $this->makeBooking();
        $booking->filmProducts()->create(['film_product_id' => $product->id, 'position' => 'Kaca Depan']);

        $bookingFilmProductId = $booking->filmProducts()->first()->id;

        $booking->delete();

        $this->assertNull(BookingFilmProduct::find($bookingFilmProductId));
    }

    public function test_deleting_film_product_still_referenced_as_additional_product_is_restricted(): void
    {
        $product = $this->makeFilmProduct('WF-DEPAN');
        $booking = $this->makeBooking();
        $booking->filmProducts()->create(['film_product_id' => $product->id, 'position' => 'Kaca Depan']);

        $this->expectException(QueryException::class);

        $product->delete();
    }

    public function test_position_is_free_text_not_restricted_to_glass_positions(): void
    {
        // Keputusan atasan tidak membatasi istilah posisi ke kaca saja --
        // PPF pakai istilah berbeda (bumper/full body/dst), position
        // SENGAJA string bebas (lihat migrasi).
        $product = $this->makeFilmProduct('PPF-BUMPER');
        $booking = $this->makeBooking();

        $item = $booking->filmProducts()->create(['film_product_id' => $product->id, 'position' => 'Bumper Depan']);

        $this->assertEquals('Bumper Depan', $item->fresh()->position);
    }
}
