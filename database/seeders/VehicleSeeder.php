<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $vehicles = [
            // === Size: small (city car/hatchback) ===
            ['brand' => 'Toyota', 'model' => 'Agya', 'size_category' => 'small'],
            ['brand' => 'Daihatsu', 'model' => 'Ayla', 'size_category' => 'small'],
            ['brand' => 'Honda', 'model' => 'Brio', 'size_category' => 'small'],
            ['brand' => 'Suzuki', 'model' => 'Ignis', 'size_category' => 'small'],

            // === Size: medium (sedan/MPV kecil) ===
            ['brand' => 'Toyota', 'model' => 'Avanza', 'size_category' => 'medium'],
            ['brand' => 'Daihatsu', 'model' => 'Xenia', 'size_category' => 'medium'],
            ['brand' => 'Honda', 'model' => 'Mobilio', 'size_category' => 'medium'],
            ['brand' => 'Toyota', 'model' => 'Vios', 'size_category' => 'medium'],
            ['brand' => 'Honda', 'model' => 'City', 'size_category' => 'medium'],

            // === Size: large (SUV/MPV besar) ===
            ['brand' => 'Toyota', 'model' => 'Innova', 'size_category' => 'large'],
            ['brand' => 'Toyota', 'model' => 'Fortuner', 'size_category' => 'large'],
            ['brand' => 'Honda', 'model' => 'CR-V', 'size_category' => 'large'],
            ['brand' => 'Mitsubishi', 'model' => 'Pajero Sport', 'size_category' => 'large'],
            ['brand' => 'Toyota', 'model' => 'Alphard', 'size_category' => 'large'],

            // === Size: luxury (premium/eksekutif) ===
            ['brand' => 'Mercedes-Benz', 'model' => 'C-Class', 'size_category' => 'luxury'],
            ['brand' => 'BMW', 'model' => '3 Series', 'size_category' => 'luxury'],
            ['brand' => 'Lexus', 'model' => 'RX', 'size_category' => 'luxury'],
            ['brand' => 'Toyota', 'model' => 'Land Cruiser', 'size_category' => 'luxury'],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::updateOrCreate(
                ['brand' => $vehicle['brand'], 'model' => $vehicle['model']],
                $vehicle
            );
        }
    }
}