<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap "standar enterprise" diperbaiki 2026-09-25 (audit Daftar Produk) --
 * sebelumnya hanya restrictOnDelete() di scroll_codes/quotation_items yang
 * mencegah hard-delete produk yang masih direferensikan, cukup untuk
 * integritas data tapi bukan pola SoftDeletes formal seperti Customer.
 * Produk yang di-nonaktifkan/dihapus tetap perlu tampil di riwayat
 * quotation/booking/warranty lama lewat withTrashed(), bukan hilang total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
