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
            'S'  => ['front' => 0.8, 'back' => 0.8, 'side' => 0.7, 'full_set' => 3.0],
            'M'  => ['front' => 1.0, 'back' => 1.0, 'side' => 0.9, 'full_set' => 3.8],
            'L'  => ['front' => 1.2, 'back' => 1.2, 'side' => 1.1, 'full_set' => 4.6],
            'XL' => ['front' => 1.5, 'back' => 1.5, 'side' => 1.4, 'full_set' => 5.8],
        ];

        foreach ($coefficients as $size => $parts) {
            foreach ($parts as $part => $coefficient) {
                PriceRule::create([
                    'vehicle_size' => $size,
                    'car_part' => $part,
                    'coefficient' => $coefficient,
                ]);
            }
        }
    }
}