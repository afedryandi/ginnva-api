<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menambah nilai enum 'correction' ke inventory_movements.type — dipakai
 * InventoryItem::reverseLastMovement() untuk mencatat "movement X barusan
 * dibatalkan karena salah input" sebagai 1 baris riwayat yang jelas,
 * bukan cuma menghapus baris yang salah tanpa jejak apa pun bahwa koreksi
 * pernah terjadi.
 *
 * ALTER TABLE mentah (bukan Schema::table()->enum()->change()) karena
 * doctrine/dbal (dipakai Laravel untuk introspeksi ->change()) tidak
 * menangani kolom ENUM MySQL dengan baik.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('in', 'out', 'correction') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE inventory_movements MODIFY type ENUM('in', 'out') NOT NULL");
    }
};
