<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data Fase 3 (2026-10-21): identitas toko. Kode toko singkat dan unik (kosong untuk toko lama; MySQL mengizinkan banyak null pada
 * indeks unik) dan penanggung jawab opsional. Tidak ada zona waktu: belum ada toko di luar WIB (keputusan pemilik). Aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'code')) {
                $table->string('code', 12)->nullable()->unique()->after('name');
            }

            if (! Schema::hasColumn('stores', 'manager_user_id')) {
                $table->foreignId('manager_user_id')->nullable()->after('code')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'manager_user_id')) {
                $table->dropConstrainedForeignId('manager_user_id');
            }

            if (Schema::hasColumn('stores', 'code')) {
                $table->dropUnique(['code']);
                $table->dropColumn('code');
            }
        });
    }
};
