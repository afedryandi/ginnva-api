<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nama bagian kendaraan seluruhnya bahasa Indonesia (keputusan pemilik 2026-11-27): "Hood" menjadi "Kap Mesin", dst. Nama bagian tersimpan sebagai
 * teks (sama persis dengan label di `Booking::PPF_PARTS`), jadi booking lama harus ikut diganti supaya tetap cocok dengan pilihan bagian.
 *
 * Yang diganti hanya teks yang SAMA PERSIS dengan nama lama (tanpa membedakan huruf besar atau kecil), di empat kolom:
 *  - booking_film_products.position   (Produk per Bagian di booking)
 *  - booking_sale_lines.position      (rincian penjualan)
 *  - material_memo_items.car_part     (bagian kendaraan di baris memo, diketik bebas)
 *  - scroll_code_offcuts.car_part     (bagian kendaraan di sisa potongan roll, diketik bebas)
 * Teks lain (catatan bebas, deskripsi invoice lama) dibiarkan. down() mengembalikan nama lama.
 */
return new class extends Migration
{
    private const MAP = [
        'Hood' => 'Kap Mesin',
        'Front Bumper' => 'Bumper Depan',
        'Rear Bumper' => 'Bumper Belakang',
        'Left Front Fender' => 'Fender Depan Kiri',
        'Right Front Fender' => 'Fender Depan Kanan',
        'Left Side Skirt' => 'Side Skirt Kiri',
        'Right Side Skirt' => 'Side Skirt Kanan',
        'Roof' => 'Atap',
        'Rear Liftgate' => 'Pintu Bagasi',
        'Left Outside Mirror' => 'Spion Kiri',
        'Right Outside Mirror' => 'Spion Kanan',
        'Left Front Door' => 'Pintu Depan Kiri',
        'Right Front Door' => 'Pintu Depan Kanan',
        'Left Rear Door' => 'Pintu Belakang Kiri',
        'Right Rear Door' => 'Pintu Belakang Kanan',
        'Left Rear Fender' => 'Fender Belakang Kiri',
        'Right Rear Fender' => 'Fender Belakang Kanan',
    ];

    private const COLUMNS = [
        ['booking_film_products', 'position'],
        ['booking_sale_lines', 'position'],
        ['material_memo_items', 'car_part'],
        ['scroll_code_offcuts', 'car_part'],
    ];

    public function up(): void
    {
        $this->rename(self::MAP);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::MAP));
    }

    /** @param  array<string, string>  $map */
    private function rename(array $map): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach ($map as $from => $to) {
                DB::table($table)->whereRaw("LOWER({$column}) = ?", [mb_strtolower($from)])->update([$column => $to]);
            }
        }
    }
};
