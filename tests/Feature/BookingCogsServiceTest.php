<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ConsumableItem;
use App\Models\Customer;
use App\Models\MaterialMemo;
use App\Models\RawMaterial;
use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
use App\Models\Store;
use App\Services\BookingCogsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Majoo f7 ("Kolom Laba Kotor per periode ... harus include HPP
 * bahan baku") — service ini menjumlahkan biaya film (ScrollCodeUsage ×
 * ScrollCode::costPerMeter()) + bahan pendukung (MaterialMemoItem ×
 * unit_cost RawMaterial/ConsumableItem) per booking. Fokus test: harga
 * yang belum diisi ditandai `hasMissingCost`, BUKAN diam-diam dihitung
 * Rp 0 tanpa peringatan.
 */
class BookingCogsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(): Booking
    {
        $store = Store::create(['name' => 'Toko Test', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '081200000001']);

        return Booking::create([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
    }

    public function test_film_cost_computed_from_scroll_code_usage(): void
    {
        $booking = $this->makeBooking();

        $scrollCode = ScrollCode::create([
            'code' => 'SC-TEST-' . uniqid(),
            'status' => 'allocated',
            'total_length_meters' => 15,
            'purchase_cost' => 1_500_000, // Rp100.000/meter
        ]);

        ScrollCodeUsage::create([
            'scroll_code_id' => $scrollCode->id,
            'booking_id' => $booking->id,
            'meters' => 3,
        ]);

        $result = app(BookingCogsService::class)->forBookings([$booking->id]);

        $this->assertEquals(300_000.0, $result[$booking->id]['cost']);
        $this->assertFalse($result[$booking->id]['hasMissingCost']);
    }

    public function test_film_cost_flags_missing_purchase_cost_instead_of_zero_silently(): void
    {
        $booking = $this->makeBooking();

        $scrollCode = ScrollCode::create([
            'code' => 'SC-TEST-' . uniqid(),
            'status' => 'allocated',
            'total_length_meters' => 15,
            'purchase_cost' => null,
        ]);

        ScrollCodeUsage::create([
            'scroll_code_id' => $scrollCode->id,
            'booking_id' => $booking->id,
            'meters' => 3,
        ]);

        $result = app(BookingCogsService::class)->forBookings([$booking->id]);

        $this->assertEquals(0.0, $result[$booking->id]['cost']);
        $this->assertTrue($result[$booking->id]['hasMissingCost']);
    }

    public function test_supporting_material_cost_from_memo_items(): void
    {
        $booking = $this->makeBooking();

        $glue = RawMaterial::create([
            'name' => 'Lem Test', 'code' => 'RM-TEST-' . uniqid(),
            'category' => 'chemical', 'unit' => 'liter',
            'current_stock' => 10, 'unit_cost' => 50_000,
        ]);

        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-TEST-' . uniqid(),
            'store_id' => $booking->store_id,
            'booking_id' => $booking->id,
        ]);

        $memo->items()->create([
            'item_type' => 'raw_material',
            'item_id' => $glue->id,
            'item_name' => $glue->name,
            'unit' => 'liter',
            'qty_taken' => 2,
        ]);

        $result = app(BookingCogsService::class)->forBookings([$booking->id]);

        // qty_used masih null (belum ada pengembalian dicatat) — fallback
        // ke qty_taken, sesuai catatan di MaterialMemoStockService.
        $this->assertEquals(100_000.0, $result[$booking->id]['cost']);
        $this->assertFalse($result[$booking->id]['hasMissingCost']);
    }

    public function test_supporting_material_flags_missing_unit_cost(): void
    {
        $booking = $this->makeBooking();

        $glue = ConsumableItem::create([
            'name' => 'Kain Microfiber', 'code' => 'CI-TEST-' . uniqid(),
            'category' => 'supplies', 'unit' => 'pcs',
            'current_stock' => 10, 'unit_cost' => null,
        ]);

        $memo = MaterialMemo::create([
            'memo_number' => 'MEMO-TEST-' . uniqid(),
            'store_id' => $booking->store_id,
            'booking_id' => $booking->id,
        ]);

        $memo->items()->create([
            'item_type' => 'consumable_item',
            'item_id' => $glue->id,
            'item_name' => $glue->name,
            'unit' => 'pcs',
            'qty_taken' => 5,
        ]);

        $result = app(BookingCogsService::class)->forBookings([$booking->id]);

        $this->assertEquals(0.0, $result[$booking->id]['cost']);
        $this->assertTrue($result[$booking->id]['hasMissingCost']);
    }
}
