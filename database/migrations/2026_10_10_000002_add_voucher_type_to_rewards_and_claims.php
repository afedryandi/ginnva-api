<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher dipindah ke menu Reward (keputusan 2026-10-10): voucher fisik (kode dicetak, di-assign staf) dihapus.
 * Staf membuat Reward bertipe "Voucher" (nominal diskon + masa berlaku), customer menukar poin, dan voucher langsung
 * muncul di "Voucher Saya" dengan kode unik yang otomatis dibuat.
 *
 * Aditif: tabel vouchers & klaim lama tidak diubah/dihapus (klaim fisik lama tetap bisa dipakai dan tetap masuk laporan).
 * Klaim baru tidak punya voucher_id (kampanye); nominal & masa berlakunya di-snapshot di klaim itu sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->string('type', 20)->default('item')->after('name');
            $table->decimal('voucher_discount', 12, 2)->nullable()->after('stock');
            $table->unsignedSmallInteger('voucher_valid_days')->nullable()->after('voucher_discount');
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
        });

        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_id')->nullable()->change();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->restrictOnDelete();

            $table->foreignId('reward_id')->nullable()->after('voucher_id')->constrained('rewards')->restrictOnDelete();
            $table->unsignedBigInteger('reward_redemption_id')->nullable()->unique()->after('reward_id');
            $table->decimal('discount_amount', 12, 2)->nullable()->after('reward_redemption_id');
            $table->date('expires_at')->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_claims', function (Blueprint $table) {
            $table->dropForeign(['reward_id']);
            $table->dropUnique(['reward_redemption_id']);
            $table->dropColumn(['reward_id', 'reward_redemption_id', 'discount_amount', 'expires_at']);
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->dropColumn(['type', 'voucher_discount', 'voucher_valid_days']);
        });
    }
};
