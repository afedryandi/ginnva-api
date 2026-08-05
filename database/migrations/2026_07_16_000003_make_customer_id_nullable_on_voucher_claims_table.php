<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher fisik yang diambil customer WALK-IN (belum/tidak install
 * mobile app) tidak punya akun customer untuk ditempeli — customer_id
 * dijadikan nullable, dan ditambah walkin_name/walkin_phone supaya staff
 * tetap bisa catat siapa yang ambil tanpa perlu akun app. Unique index
 * (voucher_id, customer_id) yang sudah ada TETAP aman dengan NULL —
 * MySQL tidak menganggap NULL=NULL untuk unique index, jadi banyak baris
 * walk-in (customer_id null) untuk voucher yang sama tidak akan bentrok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->string('walkin_name', 255)->nullable()->after('customer_id');
            $table->string('walkin_phone', 30)->nullable()->after('walkin_name');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->dropColumn(['walkin_name', 'walkin_phone']);
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }
};
