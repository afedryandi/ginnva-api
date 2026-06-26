<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle;

class VehicleSeeder extends Seeder
{
    /**
     * Jalankan dengan: php artisan db:seed --class=VehicleSeeder
     *
     * size_category menentukan koefisien harga yang dipakai di PriceRule.
     * Pembagian S/M/L/XL di sini berdasarkan dimensi umum kendaraan,
     * bukan merek/harga jual mobil.
     */
    public function run(): void
    {
        $vehicles = [
            // Size S — city car / hatchback kecil
            ['brand' => 'Honda', 'model' => 'Brio', 'size_category' => 'S'],
            ['brand' => 'Toyota', 'model' => 'Agya', 'size_category' => 'S'],
            ['brand' => 'Daihatsu', 'model' => 'Sirion', 'size_category' => 'S'],

            // Size M — sedan / MPV kecil
            ['brand' => 'Toyota', 'model' => 'Avanza', 'size_category' => 'M'],
            ['brand' => 'Honda', 'model' => 'Civic', 'size_category' => 'M'],
            ['brand' => 'Honda', 'model' => 'HR-V', 'size_category' => 'M'],

            // Size L — SUV / MPV besar
            ['brand' => 'Toyota', 'model' => 'Innova', 'size_category' => 'L'],
            ['brand' => 'Honda', 'model' => 'CR-V', 'size_category' => 'L'],
            ['brand' => 'Mitsubishi', 'model' => 'Pajero Sport', 'size_category' => 'L'],

            // Size XL — premium SUV / minibus
            ['brand' => 'Toyota', 'model' => 'Alphard', 'size_category' => 'XL'],
            ['brand' => 'Toyota', 'model' => 'Fortuner', 'size_category' => 'XL'],
            ['brand' => 'Isuzu', 'model' => 'Elf (Minibus)', 'size_category' => 'XL'],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::create($vehicle);
        }
    }
}