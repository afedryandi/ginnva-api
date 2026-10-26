<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pemasok utama per bahan baku dan barang habis pakai (audit ACCURATE 5, "pemasok utama" pada barang). Opsional dan hanya petunjuk:
     * dipakai sebagai isian awal Supplier saat permohonan pembelian ditandai Terpenuhi dan ditampilkan di peringatan stok menipis.
     * Kosong untuk semua barang yang ada; menghapus supplier hanya mengosongkan kolom ini.
     */
    public function up(): void
    {
        foreach (['raw_materials', 'consumable_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('preferred_supplier_id')->nullable()->after('reorder_point')->constrained('suppliers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['raw_materials', 'consumable_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('preferred_supplier_id');
            });
        }
    }
};
