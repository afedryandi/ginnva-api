<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pastikan 20 baris PriceRule (5 ukuran × 4 bagian) ADA di production —
 * menu "Koefisien Harga" dibuka lagi 2026-09-10 (aktivasi pricing Fase
 * 1), percuma kalau tabelnya kosong. Koefisien di sini nilai CONTOH
 * (sama dengan PriceRuleSeeder) — admin sesuaikan dengan data kantor
 * pusat lewat menu itu.
 *
 * firstOrCreate: idempoten, TIDAK menimpa koefisien yang mungkin sudah
 * diedit admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $coefficients = [
            'S' => ['front' => 0.80, 'back' => 0.80, 'side' => 0.70, 'full_set' => 3.00],
            'M' => ['front' => 1.00, 'back' => 1.00, 'side' => 0.90, 'full_set' => 3.80],
            'L' => ['front' => 1.20, 'back' => 1.20, 'side' => 1.10, 'full_set' => 4.60],
            'XL' => ['front' => 1.50, 'back' => 1.50, 'side' => 1.40, 'full_set' => 5.80],
            'XXL' => ['front' => 1.80, 'back' => 1.80, 'side' => 1.70, 'full_set' => 7.00],
        ];

        $now = now();

        foreach ($coefficients as $size => $parts) {
            foreach ($parts as $part => $coefficient) {
                $exists = DB::table('price_rules')
                    ->where('vehicle_size', $size)
                    ->where('car_part', $part)
                    ->exists();

                if (! $exists) {
                    DB::table('price_rules')->insert([
                        'vehicle_size' => $size,
                        'car_part' => $part,
                        'coefficient' => $coefficient,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Sengaja tidak menghapus apa pun — data koefisien mungkin sudah
        // diedit admin sejak migrasi ini jalan.
    }
};
