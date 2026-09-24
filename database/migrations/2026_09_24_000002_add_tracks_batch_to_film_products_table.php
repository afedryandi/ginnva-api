<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            // f28 — toggle "Wajib Lacak Roll (Batch Number)" PER PRODUK.
            // Default false supaya produk yang sudah ada TIDAK berubah
            // perilaku saat migrasi jalan (lihat SpkService::assertBatchTrackingSatisfied()).
            $table->boolean('tracks_batch')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->dropColumn('tracks_batch');
        });
    }
};
