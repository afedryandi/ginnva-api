<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FilmProduct;

class FilmProductSeeder extends Seeder
{
    public function run(): void
    {
        // base_price = harga dasar per 1 unit/bagian (front/back/side/full_set),
        // nanti dikali coefficient dari price_rules sesuai vehicle_size + car_part.
        $products = [
            // === Kaca Film Mobil ===
            [
                'sku' => 'GNV-KFM-Z20',
                'name' => 'Ziwei 20',
                'product_type' => 'kaca_film_mobil',
                'base_price' => 800000,
                'is_active' => true,
            ],
            [
                'sku' => 'GNV-KFM-Z40',
                'name' => 'Ziwei 40',
                'product_type' => 'kaca_film_mobil',
                'base_price' => 1000000,
                'is_active' => true,
            ],
            [
                'sku' => 'GNV-KFM-Z70',
                'name' => 'Ziwei 70',
                'product_type' => 'kaca_film_mobil',
                'base_price' => 1200000,
                'is_active' => true,
            ],

            // === Paint Protection Film (PPF) ===
            [
                'sku' => 'GNV-PPF-GLS',
                'name' => 'Ginnva PPF Gloss',
                'product_type' => 'ppf',
                'base_price' => 5000000,
                'is_active' => true,
            ],
            [
                'sku' => 'GNV-PPF-MAT',
                'name' => 'Ginnva PPF Matte',
                'product_type' => 'ppf',
                'base_price' => 5500000,
                'is_active' => true,
            ],

            // === Color Change Film ===
            [
                'sku' => 'GNV-CCF-SAT',
                'name' => 'Ginnva Color Change Satin',
                'product_type' => 'color_change',
                'base_price' => 6000000,
                'is_active' => true,
            ],
            [
                'sku' => 'GNV-CCF-GLS',
                'name' => 'Ginnva Color Change Gloss',
                'product_type' => 'color_change',
                'base_price' => 6500000,
                'is_active' => true,
            ],

            // === Kaca Film Bangunan ===
            [
                'sku' => 'GNV-KFB-SR80',
                'name' => 'Ginnva Building Film SR-80',
                'product_type' => 'kaca_bangunan',
                'base_price' => 150000, // per m2, car_part tidak relevan untuk tipe ini
                'is_active' => true,
            ],
        ];

        foreach ($products as $product) {
            FilmProduct::updateOrCreate(
                ['sku' => $product['sku']],
                $product
            );
        }
    }
}