<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Import daftar Bahan Baku dari ekspor Majoo (ekspor_daftar_bahan_baku_
 * 10_09_26_13_46.xlsx). Semua bahan detailing / prep PPF.
 *
 * current_stock = 0 (ekspor tidak memuat stok — Harga Beli & stok awal
 * semua 0 di Majoo). reorder_point diambil dari kolom "Stok Minimum"
 * (0 -> null = tidak ada ambang; >0 -> nilainya). unit "Pieces" ->
 * "pcs" (konvensi Ginnva). SKU dipertahankan apa adanya termasuk titik
 * di belakang supaya tetap unik (mis. "Tyre Gloss 1" vs "Tyre Gloss"
 * beda cuma di titik).
 *
 * Idempoten: lewati kalau code sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // [nama, code (SKU), satuan, reorder_point]
        $items = [
            ['Plastic Care Engine',      'CMSX0102055.',   'ml',   null],
            ['NP 03-06',                 'CMSX0102083',    'ml',   null],
            ['Tyre Gloss 1',             'CMSX0102355',    'ml',   1],
            ['Tyre Gloss',               'CMSX0102355.',   'ml',   1],
            ['Cut Max',                  'CMSX0102463',    'ml',   null],
            ['Glass Polish',             'CMSX0102731.',   'ml',   null],
            ['Actifoam Energy (pouch)',  'CMSX0103145',    'ml',   null],
            ['Interior Cleaner',         'CMSX0103216.',   'ml',   1],
            ['Clear Glass 1',            'CMSX01033850',   'ml',   5000],
            ['Clay Blue',                'CMSX01045020',   'gram', null],
            ['SX90',                     'CMSX0104743',    'ml',   null],
            ['SXMulti star',             'CMSX010627600.', 'ml',   null],
            ['Engine Cold Cleanner',     'CMSX106076',     'ml',   null],
            ['Rim Cleaner Acid Free',    'CSMX010230500',  'ml',   null],
            ['Clear Glass',              'CSMX01033850.',  'ml',   null],
            ['Feltpad',                  'Feltpad',        'pcs',  null],
            ['Polishing pad yellow DA',  'Polishing pad yellow DA', 'pcs', null],
            ['Polishing Sponge Red DA',  'Polishing Sponge Red DA', 'pcs', null],
        ];

        $now = now();

        foreach ($items as [$name, $code, $unit, $reorder]) {
            if (DB::table('raw_materials')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('raw_materials')->insert([
                'name' => $name,
                'code' => $code,
                'category' => 'Detailing',
                'unit' => $unit,
                'current_stock' => 0,
                'reorder_point' => $reorder,
                'unit_cost' => null,
                'notes' => 'Impor dari daftar Majoo, 10 Sep 2026.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('raw_materials')
            ->whereIn('code', [
                'CMSX0102055.', 'CMSX0102083', 'CMSX0102355', 'CMSX0102355.', 'CMSX0102463',
                'CMSX0102731.', 'CMSX0103145', 'CMSX0103216.', 'CMSX01033850', 'CMSX01045020',
                'CMSX0104743', 'CMSX010627600.', 'CMSX106076', 'CSMX010230500', 'CSMX01033850.',
                'Feltpad', 'Polishing pad yellow DA', 'Polishing Sponge Red DA',
            ])
            ->delete();
    }
};
