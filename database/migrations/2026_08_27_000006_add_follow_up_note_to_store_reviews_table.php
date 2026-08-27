<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEBELUMNYA aksi "Tandai Ditindaklanjuti" cuma toggle boolean
 * (followed_up_at/followed_up_by) tanpa catatan apa pun — untuk toko
 * dengan banyak review negatif, informasi APA yang sebenarnya dilakukan
 * untuk follow-up hilang begitu saja, cuma ada timestamp & siapa yang
 * menandai. Lihat audit modul Review Toko 2026-08-27.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_reviews', function (Blueprint $table) {
            $table->text('follow_up_note')->nullable()->after('followed_up_by');
        });
    }

    public function down(): void
    {
        Schema::table('store_reviews', function (Blueprint $table) {
            $table->dropColumn('follow_up_note');
        });
    }
};
