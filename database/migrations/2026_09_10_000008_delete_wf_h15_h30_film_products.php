<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus WF-H15 & WF-H30 — user 2026-09-10: produk film Ginnva yang tidak
 * ada di katalog Majoo dihapus saja (DB development, cascade tidak masalah).
 * Majoo cuma punya "Panoramic H15/H30" yang sudah dibuat sebagai
 * WF-PANORAMIC / WF-PANORAMIC-L / WF-SUNROOF (migrasi ...000007).
 *
 * FK `film_product_id` di scroll_codes / case_studies / quotation_items =
 * restrictOnDelete → dependennya dibersihkan manual dulu (FK checks
 * dimatikan sementara supaya urutan tidak masalah).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('film_products')->whereIn('sku', ['WF-H15', 'WF-H30'])->pluck('id')->all();

        if (empty($ids)) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $scrollIds = DB::table('scroll_codes')->whereIn('film_product_id', $ids)->pluck('id')->all();

            if (! empty($scrollIds)) {
                DB::table('scroll_code_usages')->whereIn('scroll_code_id', $scrollIds)->delete();
                DB::table('inventory_items')->whereIn('scroll_code_id', $scrollIds)->update(['scroll_code_id' => null]);
                DB::table('scroll_codes')->whereIn('id', $scrollIds)->delete();
            }

            DB::table('case_studies')->whereIn('film_product_id', $ids)->delete();
            DB::table('quotation_items')->whereIn('film_product_id', $ids)->delete();
            DB::table('film_product_recipe_items')->whereIn('film_product_id', $ids)->delete();
            DB::table('film_product_prices')->whereIn('film_product_id', $ids)->delete();
            DB::table('bookings')->whereIn('film_product_id', $ids)->update(['film_product_id' => null]);

            DB::table('film_products')->whereIn('id', $ids)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // Tidak bisa di-rollback — data produk + turunannya sudah dihapus.
    }
};
