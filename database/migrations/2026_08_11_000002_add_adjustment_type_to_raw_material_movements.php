<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tipe baru untuk fitur stock opname (RawMaterial::adjustStock()) —
     * beda dari 'in'/'out' biasa karena quantity-nya BOLEH negatif (delta
     * dari selisih hitung fisik vs sistem, bukan jumlah barang masuk/
     * keluar sungguhan), supaya kelihatan jelas di riwayat mana yang
     * transaksi asli vs mana yang cuma koreksi.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE raw_material_movements MODIFY type ENUM('in', 'out', 'adjustment') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE raw_material_movements MODIFY type ENUM('in', 'out') NOT NULL");
    }
};
