<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FilmProduct;

class FilmProductSeeder extends Seeder
{
    /**
     * Jalankan dengan: php artisan db:seed --class=FilmProductSeeder
     *
     * base_price adalah harga dasar SEBELUM dikali coefficient dari PriceRule.
     * product_type dibatasi enum migration: window_film, ppf, color_change
     * (architectural_film tidak masuk quotation system kendaraan ini).
     */
    public function run(): void
    {
        $products = [
            // Window Film (kaca film mobil) — sesuai seri Ziwei di site.ts
            ['sku' => 'WF-ZIWEI-70', 'name' => 'Ziwei 70 (Depan)', 'product_type' => 'window_film', 'base_price' => 350000, 'is_active' => true],
            ['sku' => 'WF-ZIWEI-40', 'name' => 'Ziwei 40 (Samping/Belakang)', 'product_type' => 'window_film', 'base_price' => 300000, 'is_active' => true],
            ['sku' => 'WF-ZIWEI-20', 'name' => 'Ziwei 20 (Samping/Belakang Gelap)', 'product_type' => 'window_film', 'base_price' => 300000, 'is_active' => true],

            // Paint Protection Film
            ['sku' => 'PPF-MATTE', 'name' => 'Ginnva PPF Matte', 'product_type' => 'ppf', 'base_price' => 4500000, 'is_active' => true],
            ['sku' => 'PPF-GLOSSY', 'name' => 'Ginnva PPF Glossy', 'product_type' => 'ppf', 'base_price' => 4200000, 'is_active' => true],

            // Color Change Film
            ['sku' => 'CCF-SATIN', 'name' => 'Color Change - Satin Series', 'product_type' => 'color_change', 'base_price' => 5000000, 'is_active' => true],
            ['sku' => 'CCF-GLOSSY', 'name' => 'Color Change - Glossy Series', 'product_type' => 'color_change', 'base_price' => 5200000, 'is_active' => true],
        ];

        foreach ($products as $product) {
            FilmProduct::create($product);
        }
    }
}