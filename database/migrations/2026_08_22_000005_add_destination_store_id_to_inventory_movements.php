<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sebelumnya "ke toko mana" barang keluar cuma bisa dibaca dari catatan
 * teks bebas opsional (kolom note) — sekarang dicatat terstruktur di
 * kolom sendiri, diisi otomatis dari toko akun staff yang scan (atau
 * dipilih manual oleh full-access), BUKAN diketik manual. Cuma relevan
 * untuk movement type 'in' — 'keluar' berarti pindah ke toko, sedangkan
 * 'masuk' berarti balik ke gudang pusat/tidak terikat 1 toko.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('destination_store_id')->nullable()->after('note')->constrained('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['destination_store_id']);
            $table->dropColumn('destination_store_id');
        });
    }
};
