<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sama pola dengan add_correction_type_to_consumable_item_movements —
 * dipakai RawMaterial::reverseLastMovement() (audit 2026-09-14, temuan
 * inkonsistensi: RawMaterial satu-satunya dari 3 resource "riwayat
 * lintas barang" yang belum punya aksi "Batalkan") untuk mencatat
 * "movement X barusan dibatalkan karena salah input" sebagai 1 baris
 * riwayat yang jelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE raw_material_movements MODIFY type ENUM('in', 'out', 'adjustment', 'correction') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE raw_material_movements MODIFY type ENUM('in', 'out', 'adjustment') NOT NULL");
    }
};
