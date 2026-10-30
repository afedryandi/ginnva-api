<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            // Bulan terakhir komponen ikut disalin otomatis ke bulan berikutnya (cicilan kasbon, tunjangan kontrak). Kosong = tanpa batas.
            $table->date('repeat_until')->nullable();
        });

        // Nama di slip yang kosong (artinya "pakai nama kategori") dibekukan jadi nama kategori saat ini, supaya mengganti nama kategori
        // kelak tidak mengubah slip lama. Isi nama sebelumnya tidak bisa dipulihkan ke kosong oleh down().
        DB::statement('UPDATE payroll_items SET label = (SELECT name FROM payroll_item_categories WHERE payroll_item_categories.id = payroll_items.payroll_item_category_id) WHERE label IS NULL');
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn('repeat_until');
        });
    }
};