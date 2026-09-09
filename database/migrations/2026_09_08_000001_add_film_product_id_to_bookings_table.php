<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diminta 2026-09-08 supaya laporan "Produk Terlaris" bisa dihitung dari
 * transaksi SUNGGUHAN (Booking), bukan cuma dari Quotation (penawaran/
 * lead yang belum tentu jadi transaksi). Sebelumnya Booking cuma simpan
 * boolean product_kaca_film/product_ppf, tidak pernah tahu SKU/varian
 * FilmProduct spesifik mana yang dipasang.
 *
 * NULLABLE & tanpa default -- booking lama (dan booking baru yang belum
 * sempat diisi staff) tetap valid, kolom ini murni tambahan opsional,
 * TIDAK mengubah alur booking yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('film_product_id')->nullable()->after('product_ppf')
                ->constrained('film_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('film_product_id');
        });
    }
};
