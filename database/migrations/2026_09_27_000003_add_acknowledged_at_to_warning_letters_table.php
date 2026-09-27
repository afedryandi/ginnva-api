<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap standar enterprise (audit Surat Peringatan 2026-09-27): sebelumnya
 * tidak ada bukti karyawan sudah diberi tahu/membaca SP-nya sendiri --
 * cuma kolom "document" (scan fisik opsional). acknowledged_at diisi
 * lewat aksi "Tandai Sudah Dibaca" di mobile app (staff), read-only di
 * Filament (bukan sesuatu yang bisa diklik admin atas nama karyawan --
 * harus benar-benar karyawannya sendiri yang membuka & menekan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warning_letters', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('valid_until');
        });
    }

    public function down(): void
    {
        Schema::table('warning_letters', function (Blueprint $table) {
            $table->dropColumn('acknowledged_at');
        });
    }
};
