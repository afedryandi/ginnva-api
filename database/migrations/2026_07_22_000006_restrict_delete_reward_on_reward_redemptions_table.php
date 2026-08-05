<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reward_redemptions.reward_id sebelumnya cascadeOnDelete() — hapus
 * Reward yang masih punya redemption (bahkan yang sudah fulfilled) akan
 * MENGHAPUS SELURUH RIWAYAT PENUKARANNYA. Poin yang sudah dibelanjakan
 * customer/partner untuk reward itu jadi tidak punya bukti/audit trail
 * sama sekali — lebih parah dari bug "cancel tanpa refund" yang sudah
 * diperbaiki lewat RewardRedemptionObserver, karena di situ row-nya
 * setidaknya masih ada (status='cancelled'); di sini row-nya lenyap
 * total. Diganti restrictOnDelete(), konsisten dengan film_products dan
 * vehicles yang sudah lebih dulu diperbaiki dengan pola sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->dropForeign(['reward_id']);
        });

        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->foreign('reward_id')
                  ->references('id')->on('rewards')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->dropForeign(['reward_id']);
        });

        Schema::table('reward_redemptions', function (Blueprint $table) {
            $table->foreign('reward_id')
                  ->references('id')->on('rewards')
                  ->cascadeOnDelete();
        });
    }
};
