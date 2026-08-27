<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sama pola dengan add_correction_type_to_inventory_movements — dipakai
 * ConsumableItem::reverseLastMovement() untuk mencatat "movement X barusan
 * dibatalkan karena salah input" sebagai 1 baris riwayat yang jelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE consumable_item_movements MODIFY type ENUM('in', 'out', 'adjustment', 'correction') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE consumable_item_movements MODIFY type ENUM('in', 'out', 'adjustment') NOT NULL");
    }
};
