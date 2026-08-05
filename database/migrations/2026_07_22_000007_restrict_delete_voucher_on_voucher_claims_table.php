<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * voucher_claims.voucher_id sebelumnya cascadeOnDelete() — hapus
 * kampanye Voucher yang masih punya klaim (termasuk yang sudah 'used'
 * dalam transaksi nyata) akan MENGHAPUS SELURUH RIWAYAT KLAIMNYA, sama
 * seperti bug reward_redemptions yang sudah diperbaiki. Diganti
 * restrictOnDelete() — konsisten dengan film_products, vehicles, dan
 * rewards yang sudah lebih dulu diperbaiki dengan pola sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->foreign('voucher_id')
                  ->references('id')->on('vouchers')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->foreign('voucher_id')
                  ->references('id')->on('vouchers')
                  ->cascadeOnDelete();
        });
    }
};
