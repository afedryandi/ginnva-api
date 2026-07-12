<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration sebelumnya (2026_07_09_000001) menghapus baris "HYUNDAI · SATRIA"
 * karena dikira data salah (nama itu kependekan Proton Satria). Ternyata itu
 * cuma typo dari "HYUNDAI · STARIA" — model van/MPV besar Hyundai yang resmi
 * dijual di Indonesia. Migration ini menambahkannya kembali dengan ejaan
 * yang benar, kategori ukuran XL (sama seperti posisi asalnya sebelum
 * terhapus).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicles')->insertOrIgnore([
            'brand'         => 'HYUNDAI',
            'model'         => 'STARIA',
            'variant'       => null,
            'size_category' => 'XL',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('vehicles')
            ->where('brand', 'HYUNDAI')
            ->where('model', 'STARIA')
            ->delete();
    }
};
