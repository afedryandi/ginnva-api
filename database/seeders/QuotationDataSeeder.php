<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class QuotationDataSeeder extends Seeder
{
    /**
     * Jalankan semua seeder untuk Quotation System.
     * Urutan PENTING: FilmProduct dan Vehicle dulu, baru PriceRule
     * (karena PriceRule butuh film_product_id yang sudah ada).
     */
    public function run(): void
    {
        $this->call([
            FilmProductSeeder::class,
            VehicleSeeder::class,
            PriceRuleSeeder::class,
        ]);
    }
}