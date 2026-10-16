<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komunikasi promosi (Marketing/Konten Fase 5). Persetujuan menerima promosi bersifat opt-in eksplisit (keputusan pemilik; UU PDP): semua
 * pelanggan yang sudah ada dimulai TANPA persetujuan, jadi promosi tidak lagi sampai ke mereka sampai mereka sendiri mengaktifkannya.
 * Pesan transaksional (booking, garansi, chat) tidak terpengaruh. Notifikasi diberi kategori dan kunci kampanye supaya jumlah kiriman
 * promosi per minggu bisa dibatasi. Banner menghitung klik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'promo_opt_in')) {
                $table->boolean('promo_opt_in')->default(false);
            }
            if (! Schema::hasColumn('customers', 'promo_opt_in_at')) {
                $table->dateTime('promo_opt_in_at')->nullable();
            }
            if (! Schema::hasColumn('customers', 'promo_opt_in_source')) {
                $table->string('promo_opt_in_source', 12)->nullable();
            }
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_notifications', 'category')) {
                $table->string('category', 10)->default('info');
            }
            if (! Schema::hasColumn('customer_notifications', 'campaign_key')) {
                $table->string('campaign_key', 36)->nullable()->index();
            }
        });

        Schema::table('carousels', function (Blueprint $table) {
            if (! Schema::hasColumn('carousels', 'click_count')) {
                $table->unsignedInteger('click_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['promo_opt_in', 'promo_opt_in_at', 'promo_opt_in_source'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('customer_notifications', 'campaign_key')) {
                $table->dropIndex(['campaign_key']);
                $table->dropColumn('campaign_key');
            }
            if (Schema::hasColumn('customer_notifications', 'category')) {
                $table->dropColumn('category');
            }
        });

        Schema::table('carousels', function (Blueprint $table) {
            if (Schema::hasColumn('carousels', 'click_count')) {
                $table->dropColumn('click_count');
            }
        });
    }
};
