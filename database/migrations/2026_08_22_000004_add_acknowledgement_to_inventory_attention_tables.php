<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard Inventaris sebelumnya murni read-only — tidak ada cara
 * "menyelesaikan" item yang di-flag langsung dari layar itu, staff harus
 * pindah ke menu masing-masing. reviewed_at/reviewed_by dipakai sebagai
 * tombol "Tandai Ditinjau": begitu di-set, baris HILANG dari widget
 * "Perlu Perhatian" — TAPI cuma sampai kondisinya berubah lagi (dicek
 * reviewed_at >= updated_at, lihat isAcknowledged() di masing-masing
 * model) — kalau stok/status berubah lagi setelah ditinjau, otomatis
 * muncul lagi (updated_at ikut berubah > reviewed_at), supaya "ditinjau"
 * tidak dipakai untuk menyembunyikan masalah baru yang beda.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['raw_materials', 'consumable_items', 'assets'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('reviewed_at')->nullable()->after('notes');
                $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['raw_materials', 'consumable_items', 'assets'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['reviewed_by']);
                $table->dropColumn(['reviewed_at', 'reviewed_by']);
            });
        }
    }
};
