<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - product_import_logs.changes: daftar perubahan per baris (SKU, nilai lama dan baru) supaya Lihat Detail menunjukkan apa yang berubah.
 * - scheduled_price_change_items: harga SEBELUM dan SESUDAH tiap harga yang diubah oleh satu perubahan harga terjadwal, dasar tombol
 *   "Kembalikan". scheduled_price_changes.reverted_at/reverted_by mencatat kapan dan siapa yang mengembalikannya.
 * Perubahan harga yang sudah berlaku sebelum migrasi ini tidak punya baris item, jadi tidak bisa dikembalikan lewat tombol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_import_logs', function (Blueprint $table) {
            $table->json('changes')->nullable()->after('errors');
        });

        Schema::table('scheduled_price_changes', function (Blueprint $table) {
            $table->timestamp('reverted_at')->nullable()->after('applied_at');
            $table->foreignId('reverted_by')->nullable()->after('reverted_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('scheduled_price_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_price_change_id')->constrained('scheduled_price_changes')->cascadeOnDelete();
            // Kelas model harga (produk, harga per ukuran, atau harga per grup), id barisnya, dan kolom yang diubah.
            $table->string('model_type', 100);
            $table->unsignedBigInteger('model_id');
            $table->string('price_column', 20);
            $table->decimal('old_value', 15, 2);
            $table->decimal('new_value', 15, 2);
            $table->string('label', 190)->nullable();
            $table->timestamps();

            $table->index('scheduled_price_change_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_price_change_items');

        Schema::table('scheduled_price_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverted_by');
            $table->dropColumn('reverted_at');
        });

        Schema::table('product_import_logs', function (Blueprint $table) {
            $table->dropColumn('changes');
        });
    }
};
