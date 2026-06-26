<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mengubah tabel `quotations` dari "kalkulator harga" jadi "lead capture form".
     * Ginnva Indonesia baru expand dari China, harga jual belum ditentukan —
     * jadi quotation di sini hanya menangkap minat customer, harga dibicarakan
     * manual oleh sales melalui kontak yang diisi customer.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // total_price tidak lagi relevan karena tidak ada kalkulasi harga otomatis
            $table->dropColumn('total_price');

            // Lead capture butuh status follow-up sales, bukan status pembuatan dokumen
            $table->dropColumn('status');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->enum('status', ['new', 'contacted', 'closed', 'cancelled'])->default('new')->after('license_plate');
            $table->text('message')->nullable()->after('status'); // catatan/kebutuhan dari customer
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            // Kolom-kolom harga tidak lagi diisi (sengaja DIBIARKAN ADA di tabel,
            // hanya dibuat nullable supaya insert tanpa harga tidak gagal —
            // ini memudahkan kalau price_rule dipakai lagi di masa depan)
            $table->decimal('base_price_snapshot', 15, 2)->nullable()->change();
            $table->decimal('coefficient_snapshot', 4, 2)->nullable()->change();
            $table->decimal('calculated_price', 15, 2)->nullable()->change();
            $table->unsignedBigInteger('price_rule_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['status', 'message']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('total_price', 15, 2)->default(0);
            $table->enum('status', ['draft', 'sent', 'accepted'])->default('draft');
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->decimal('base_price_snapshot', 15, 2)->nullable(false)->change();
            $table->decimal('coefficient_snapshot', 4, 2)->nullable(false)->change();
            $table->decimal('calculated_price', 15, 2)->nullable(false)->change();
            $table->unsignedBigInteger('price_rule_id')->nullable(false)->change();
        });
    }
};