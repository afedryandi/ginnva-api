<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Import harga jual dari ekspor Majoo 2026-09-10 (docs/majoo-price-list-2026-09-10.md).
 *
 * Sekaligus RENAME lini window film sesuai konfirmasi user:
 *   - WF-A70 -> "Ginnva Signature"  (SKU tetap — dipakai matching roll)
 *   - WF-H70 -> "Ginnva Platinum"
 *
 * base_price di-set ke harga ukuran M (acuan/fallback). Baris per ukuran
 * masuk film_product_prices. Idempoten (cek exists dulu).
 *
 * BELUM termasuk: lini Panoramic H15/H30 (WF-H15/WF-H30) — struktur
 * produknya masih perlu dikonfirmasi user (Majoo punya 3 produk
 * panoramic: Panoramic / Large Panoramic / Sunroof). Detailing: cuma
 * ukuran M yang diketahui (Rp 1.5jt) -> di-set sebagai base_price flat.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'WF-A70' => [
                'name' => 'Ginnva Signature',
                'sizes' => ['S' => 6110000, 'M' => 7020000, 'L' => 7930000, 'XL' => 10660000],
            ],
            'WF-H70' => [
                'name' => 'Ginnva Platinum',
                'sizes' => ['S' => 3510000, 'M' => 4160000, 'L' => 5200000, 'XL' => 6500000],
            ],
            'PPF-GREEN-CRYSTAL-EV7' => [
                'sizes' => ['S' => 13800000, 'M' => 15000000, 'L' => 16000000, 'XL' => 20000000, 'XXL' => 22000000],
            ],
            'PPF-ORANGE-CRYSTAL-H10' => [
                'sizes' => ['S' => 27000000, 'M' => 28000000, 'L' => 30000000, 'XL' => 33000000, 'XXL' => 36000000],
            ],
            'PPF-ORANGE-CRYSTAL-M10' => [
                'sizes' => ['S' => 34000000, 'M' => 35000000, 'L' => 38000000, 'XL' => 40000000, 'XXL' => 45000000],
            ],
            'PPF-BLACK-CRYSTAL-M8M' => [
                'sizes' => ['S' => 35000000, 'M' => 36000000, 'L' => 39000000, 'XL' => 42000000, 'XXL' => 46000000],
            ],
        ];

        $now = now();

        foreach ($map as $sku => $cfg) {
            $id = DB::table('film_products')->where('sku', $sku)->value('id');

            if ($id === null) {
                continue;
            }

            $update = ['base_price' => $cfg['sizes']['M'] ?? current($cfg['sizes']), 'updated_at' => $now];
            if (isset($cfg['name'])) {
                $update['name'] = $cfg['name'];
            }
            DB::table('film_products')->where('id', $id)->update($update);

            foreach ($cfg['sizes'] as $size => $price) {
                $exists = DB::table('film_product_prices')
                    ->where('film_product_id', $id)
                    ->where('vehicle_size', $size)
                    ->exists();

                if (! $exists) {
                    DB::table('film_product_prices')->insert([
                        'film_product_id' => $id,
                        'vehicle_size' => $size,
                        'price' => $price,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        // Detailing — cuma ukuran M diketahui, perlakukan sebagai flat.
        DB::table('film_products')
            ->where('sku', 'SVC-DETAILING')
            ->where('base_price', 0)
            ->update(['base_price' => 1500000, 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Sengaja tidak me-rollback rename / harga — data bisa sudah
        // diedit admin. Kalau perlu reset, lakukan manual.
    }
};
