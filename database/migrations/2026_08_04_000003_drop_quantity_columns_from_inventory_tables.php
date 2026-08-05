<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perbaikan skema — desain awal sempat punya kolom kuantitas
     * (initial_quantity/current_quantity di inventory_items,
     * quantity di inventory_movements) sebelum diputuskan 1 kardus =
     * 1 unit (tidak ada kuantitas untuk dilacak). Kalau migration
     * 000001/000002 SUDAH sempat dijalankan dengan versi lama sebelum
     * file-nya direvisi, tabel yang sudah ada masih punya kolom NOT
     * NULL itu — bikin INSERT dari form (yang sekarang tidak kirim
     * data kolom itu) gagal dengan error 500. Migration ini idempotent
     * (cek hasColumn dulu) supaya aman dijalankan baik di database yang
     * sempat kena skema lama maupun yang belum pernah migrate sama sekali.
     */
    public function up(): void
    {
        if (Schema::hasTable('inventory_items')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                if (Schema::hasColumn('inventory_items', 'initial_quantity')) {
                    $table->dropColumn('initial_quantity');
                }
                if (Schema::hasColumn('inventory_items', 'current_quantity')) {
                    $table->dropColumn('current_quantity');
                }
            });
        }

        if (Schema::hasTable('inventory_movements') && Schema::hasColumn('inventory_movements', 'quantity')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropColumn('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_items') && ! Schema::hasColumn('inventory_items', 'initial_quantity')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->unsignedInteger('initial_quantity')->default(1);
                $table->unsignedInteger('current_quantity')->default(1);
            });
        }

        if (Schema::hasTable('inventory_movements') && ! Schema::hasColumn('inventory_movements', 'quantity')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->unsignedInteger('quantity')->default(1);
            });
        }
    }
};
