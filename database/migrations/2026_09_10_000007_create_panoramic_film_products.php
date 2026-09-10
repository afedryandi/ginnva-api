<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3 produk panoramic dari ekspor Majoo (dikonfirmasi user 2026-09-10 =
 * produk terpisah, pakai film H15/H30 di kaca panoramic/sunroof).
 * Harga FLAT (base_price), tidak per ukuran mobil.
 *
 * WF-H15 & WF-H30 sendiri TETAP produk terpisah dengan harga flat
 * masing-masing — angkanya belum ada, diisi admin lewat field "Harga
 * Jual (Dasar / Flat)" di Produk Film.
 */
return new class extends Migration
{
    public function up(): void
    {
        $products = [
            ['sku' => 'WF-PANORAMIC', 'name' => 'Panoramic H15/H30', 'base_price' => 1300000],
            ['sku' => 'WF-PANORAMIC-L', 'name' => 'Large Panoramic H15/H30', 'base_price' => 1950000],
            ['sku' => 'WF-SUNROOF', 'name' => 'Panoramic Sunroof', 'base_price' => 650000],
        ];

        $now = now();

        foreach ($products as $p) {
            if (DB::table('film_products')->where('sku', $p['sku'])->exists()) {
                continue;
            }

            DB::table('film_products')->insert([
                'sku' => $p['sku'],
                'name' => $p['name'],
                'product_type' => 'window_film',
                'position' => 'side_rear',
                'base_price' => $p['base_price'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('film_products')
            ->whereIn('sku', ['WF-PANORAMIC', 'WF-PANORAMIC-L', 'WF-SUNROOF'])
            ->delete();
    }
};
