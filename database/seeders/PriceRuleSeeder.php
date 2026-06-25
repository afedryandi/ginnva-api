<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PriceRule;

class PriceRuleSeeder extends Seeder
{
    public function run(): void
    {
        // coefficient mengalikan base_price produk, berdasarkan ukuran mobil + bagian yang dipasang.
        // Logic: semakin besar mobil & semakin luas bagian (full_set), semakin besar coefficient.
        $rules = [
            // === small ===
            ['vehicle_size' => 'small', 'car_part' => 'front',    'coefficient' => 0.40],
            ['vehicle_size' => 'small', 'car_part' => 'side',     'coefficient' => 0.80],
            ['vehicle_size' => 'small', 'car_part' => 'back',     'coefficient' => 0.30],
            ['vehicle_size' => 'small', 'car_part' => 'full_set', 'coefficient' => 1.00],

            // === medium ===
            ['vehicle_size' => 'medium', 'car_part' => 'front',    'coefficient' => 0.45],
            ['vehicle_size' => 'medium', 'car_part' => 'side',     'coefficient' => 0.90],
            ['vehicle_size' => 'medium', 'car_part' => 'back',     'coefficient' => 0.35],
            ['vehicle_size' => 'medium', 'car_part' => 'full_set', 'coefficient' => 1.20],

            // === large ===
            ['vehicle_size' => 'large', 'car_part' => 'front',    'coefficient' => 0.55],
            ['vehicle_size' => 'large', 'car_part' => 'side',     'coefficient' => 1.10],
            ['vehicle_size' => 'large', 'car_part' => 'back',     'coefficient' => 0.40],
            ['vehicle_size' => 'large', 'car_part' => 'full_set', 'coefficient' => 1.50],

            // === luxury ===
            ['vehicle_size' => 'luxury', 'car_part' => 'front',    'coefficient' => 0.65],
            ['vehicle_size' => 'luxury', 'car_part' => 'side',     'coefficient' => 1.30],
            ['vehicle_size' => 'luxury', 'car_part' => 'back',     'coefficient' => 0.50],
            ['vehicle_size' => 'luxury', 'car_part' => 'full_set', 'coefficient' => 1.90],
        ];

        foreach ($rules as $rule) {
            PriceRule::updateOrCreate(
                [
                    'vehicle_size' => $rule['vehicle_size'],
                    'car_part'     => $rule['car_part'],
                ],
                [
                    'coefficient' => $rule['coefficient'],
                ]
            );
        }
    }
}