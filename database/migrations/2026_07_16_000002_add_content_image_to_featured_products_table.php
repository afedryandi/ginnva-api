<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `image` (thumbnail beranda) dan `content_image` (gambar utuh yang
 * ditampilkan saat kartu di-tap) SENGAJA dipisah — admin bisa upload 2
 * gambar berbeda per kartu, bukan cuma 1 gambar yang sama dipakai dua
 * kali (thumbnail dipotong vs full). Nullable karena kartu lama (dibuat
 * sebelum kolom ini ada) belum tentu punya gambar isi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('featured_products', function (Blueprint $table) {
            $table->string('content_image')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('featured_products', function (Blueprint $table) {
            $table->dropColumn('content_image');
        });
    }
};
