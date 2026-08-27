<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Landing page "Become a Partner" sekarang ada 2: /giias (khusus
     * event GIIAS) dan /partner (umum, terbuka ke event/audience apa
     * pun). Keduanya nulis ke PartnershipInquiry yang SAMA (category
     * tetap 'sales' untuk keduanya), jadi butuh kolom terpisah ini untuk
     * membedakan asalnya di Filament — tanpa ini, pengajuan dari 2
     * sumber itu tidak bisa dibedakan sama sekali di tabel yang sama.
     * Nullable — data lama (sebelum kolom ini ada) dibiarkan null.
     */
    public function up(): void
    {
        Schema::table('partnership_inquiries', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('partnership_inquiries', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
