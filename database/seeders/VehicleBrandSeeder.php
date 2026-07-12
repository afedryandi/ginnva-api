<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle;

/**
 * Seed merek kendaraan per kategori ukuran berdasarkan Price List PPF &
 * Kaca Film Ginnva. Kolom `model` sengaja dikosongkan — admin mengisi
 * tipe/model spesifik masing-masing merek melalui panel Filament.
 *
 * Jalankan: php artisan db:seed --class=VehicleBrandSeeder
 */
class VehicleBrandSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'S' => [
                'AION', 'BMW', 'BYD', 'FIAT', 'HONDA', 'HYUNDAI', 'MAZDA',
                'MERCY', 'MINI', 'MITSUBISHI', 'NETA', 'PORSCHE', 'SUBARU',
                'TOYOTA', 'VINFAST', 'WULING',
            ],
            'M' => [
                'BMW', 'BAIC', 'BYD', 'CHERRY', 'GEELY', 'GWM', 'HONDA',
                'HYUNDAI', 'JEEP', 'JETOUR', 'KIA', 'LAND ROVER', 'LEXUS',
                'MAZDA', 'MERCY', 'MG', 'MINI', 'MITSUBISHI', 'NISSAN',
                'PEUGEOT', 'PORSCHE', 'SUBARU', 'SUZUKI', 'TOYOTA',
                'VOLKSWAGEN', 'VOLVO', 'WULING', 'XPENG',
            ],
            'L' => [
                'AION', 'AUDI', 'BMW', 'BAIC', 'BYD', 'CHERRY', 'FORD',
                'GWM', 'HONDA', 'JAECOO', 'JEEP', 'JETOUR', 'KIA',
                'LAND ROVER', 'LEXUS', 'MAZDA', 'MERCY', 'MAXUS', 'NISSAN',
                'PORSCHE', 'SUBARU', 'SUZUKI', 'TESLA', 'TOYOTA', 'VOLVO',
                'WULING',
            ],
            'XL' => [
                'BMW', 'DENZA', 'FERRARI', 'FORD', 'GWM', 'HONDA', 'HYUNDAI',
                'JEEP', 'KIA', 'LAMBORGHINI', 'LAND ROVER', 'LEXUS', 'MAZDA',
                'MCLAREN', 'MERCY', 'MITSUBISHI', 'MAXUS', 'PORSCHE',
                'TOYOTA', 'VOLVO', 'XPENG',
            ],
            'XXL' => [
                'BENTLEY', 'BMW', 'FORD', 'GMC', 'KIA', 'LAND ROVER', 'LEXUS',
                'MCLAREN', 'MERCY', 'NISSAN', 'ROLLS ROYCE',
            ],
        ];

        foreach ($data as $size => $brands) {
            foreach ($brands as $brand) {
                // Hindari duplikasi jika seeder dijalankan ulang
                Vehicle::firstOrCreate(
                    ['brand' => $brand, 'model' => null, 'size_category' => $size],
                    ['variant' => null]
                );
            }
        }
    }
}
