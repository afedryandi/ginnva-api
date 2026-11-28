<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingFilmProduct;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nama bagian kendaraan seluruhnya bahasa Indonesia (2026-11-27). Migrasi mengganti nama lama yang tersimpan di booking, hanya yang sama
 * persis dengan nama lama, dan bisa dikembalikan.
 */
class PartNamesIndonesianTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_part_label_is_indonesian_and_unique(): void
    {
        $labels = array_values(Booking::PPF_PARTS);

        $this->assertCount(17, $labels);
        $this->assertSame($labels, array_unique($labels));
        foreach (['Hood', 'Roof', 'Front Bumper', 'Rear Liftgate', 'Left Front Door', 'Right Rear Fender', 'Left Outside Mirror'] as $english) {
            $this->assertNotContains($english, $labels);
        }
        $this->assertContains('Kap Mesin', $labels);
        $this->assertContains('Pintu Bagasi', $labels);
        $this->assertContains('Fender Belakang Kanan', $labels);
        $this->assertContains('Spion Kiri', $labels);
    }

    public function test_the_migration_renames_stored_names_exactly_and_can_be_reversed(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko A', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $booking = Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $store->id,
            'service_type' => 'Pelindung Cat (PPF)', 'product_ppf' => true, 'preferred_date' => now()->addDays(3)->toDateString(), 'status' => 'confirmed',
        ]);
        $product = FilmProduct::create(['sku' => 'PPF-T', 'name' => 'PPF T', 'product_type' => 'ppf', 'position' => 'front', 'base_price' => 1000, 'is_active' => true]);

        foreach (['Hood', 'right rear fender', 'Kaca Depan', 'Seluruh Bagian PPF', 'Hood tambahan'] as $position) {
            BookingFilmProduct::create(['booking_id' => $booking->id, 'film_product_id' => $product->id, 'position' => $position]);
        }

        $migration = require database_path('migrations/2026_12_28_000001_rename_ppf_parts_to_indonesian.php');
        $migration->up();

        $positions = fn () => BookingFilmProduct::where('booking_id', $booking->id)->orderBy('id')->pluck('position')->all();

        $this->assertSame(['Kap Mesin', 'Fender Belakang Kanan', 'Kaca Depan', 'Seluruh Bagian PPF', 'Hood tambahan'], $positions(), 'Hanya yang sama persis yang diganti; teks lain dibiarkan.');

        $migration->down();
        $this->assertSame(['Hood', 'Right Rear Fender', 'Kaca Depan', 'Seluruh Bagian PPF', 'Hood tambahan'], $positions());
    }
}
