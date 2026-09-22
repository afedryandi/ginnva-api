<?php

namespace Tests\Feature;

use App\Models\CustomerGroup;
use App\Models\FilmProduct;
use App\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Majoo f40 ("Grup Pelanggan ... Atur Harga"), dibangun
 * 2026-09-22 atas keputusan user. Fokus test: harga grup MENANG atas
 * harga normal (matriks/flat), tapi HANYA kalau grup itu punya override
 * untuk produk itu -- kalau tidak, jatuh balik ke harga normal seperti
 * biasa (tidak pernah dipaksa Rp 0 atau error).
 */
class PriceCalculatorGroupPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_price_wins_over_flat_base_price(): void
    {
        $product = FilmProduct::create([
            'sku' => 'PPF-TEST-01',
            'name' => 'PPF Test',
            'product_type' => 'ppf',
            'base_price' => 1_000_000,
        ]);
        $group = CustomerGroup::create(['name' => 'Member']);
        $product->groupPrices()->create(['customer_group_id' => $group->id, 'price' => 800_000]);

        $this->assertEquals(800_000.0, PriceCalculator::priceFor($product->fresh(), null, $group->id));
    }

    public function test_group_price_wins_over_size_matrix(): void
    {
        $product = FilmProduct::create([
            'sku' => 'PPF-TEST-02',
            'name' => 'PPF Test 2',
            'product_type' => 'ppf',
            'base_price' => 1_000_000,
        ]);
        $product->prices()->create(['vehicle_size' => 'L', 'price' => 1_200_000]);
        $group = CustomerGroup::create(['name' => 'Korporat']);
        $product->groupPrices()->create(['customer_group_id' => $group->id, 'price' => 900_000]);

        $this->assertEquals(900_000.0, PriceCalculator::priceFor($product->fresh(), 'L', $group->id));
    }

    public function test_falls_back_to_normal_price_when_group_has_no_override(): void
    {
        $product = FilmProduct::create([
            'sku' => 'PPF-TEST-03',
            'name' => 'PPF Test 3',
            'product_type' => 'ppf',
            'base_price' => 1_000_000,
        ]);
        $group = CustomerGroup::create(['name' => 'Reseller']);
        // Tidak ada override untuk produk ini.

        $this->assertEquals(1_000_000.0, PriceCalculator::priceFor($product->fresh(), null, $group->id));
    }

    public function test_null_customer_group_id_uses_normal_pricing(): void
    {
        $product = FilmProduct::create([
            'sku' => 'PPF-TEST-04',
            'name' => 'PPF Test 4',
            'product_type' => 'ppf',
            'base_price' => 1_000_000,
        ]);

        $this->assertEquals(1_000_000.0, PriceCalculator::priceFor($product->fresh(), null, null));
    }
}
