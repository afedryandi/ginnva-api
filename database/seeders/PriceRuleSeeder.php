<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PriceRule;

class PriceRuleSeeder extends Seeder
{
    /**
     * Jalankan dengan: php artisan db:seed --class=PriceRuleSeeder
     *
     * Setiap kombinasi vehicle_size x car_part harus unik (sesuai
     * constraint unique di migration), jadi seeder ini mengisi
     * SEMUA kombinasi 4 size x 4 car_part = 16 baris, supaya
     * QuotationController tidak pernah gagal cari rule yang cocok.
     *
     * Koefisien di sini contoh logis (semakin besar mobil = makin mahal),
     * SESUAIKAN dengan harga riil dari kantor pusat China kalau sudah ada.
     */
    public function run(): void
    {
        $coefficients = [
            'S'   => ['front' => 0.80, 'back' => 0.80, 'side' => 0.70, 'full_set' => 3.00],
            'M'   => ['front' => 1.00, 'back' => 1.00, 'side' => 0.90, 'full_set' => 3.80],
            'L'   => ['front' => 1.20, 'back' => 1.20, 'side' => 1.10, 'full_set' => 4.60],
            'XL'  => ['front' => 1.50, 'back' => 1.50, 'side' => 1.40, 'full_set' => 5.80],
            'XXL' => ['front' => 1.80, 'back' => 1.80, 'side' => 1.70, 'full_set' => 7.00],
        ];

        foreach ($coefficients as $size => $parts) {
            foreach ($parts as $part => $coefficient) {
                PriceRule::firstOrCreate(
                    ['vehicle_size' => $size, 'car_part' => $part],
                    ['coefficient'  => $coefficient]
                );
            }
        }
    }
}