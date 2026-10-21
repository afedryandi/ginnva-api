<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi Fase 5 (2026-10-21): kampanye (setiap kiriman manual dari menu Kirim Notifikasi, langsung atau terjadwal, beserta
 * hasilnya), templat pesan, dan penautan catatan pengiriman push ke kampanyenya (hasil per kampanye). Tanpa foreign key ke akun
 * supaya catatan bertahan walau akun dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 20);
            $table->string('category', 10)->nullable();
            $table->string('title', 200);
            $table->text('body');
            $table->json('data')->nullable();
            $table->json('customer_ids')->nullable();
            $table->json('partner_ids')->nullable();
            $table->string('status', 20)->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients')->nullable();
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('devices_sent')->default(0);
            $table->unsignedInteger('devices_failed')->default(0);
            $table->string('campaign_key', 36)->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('audience', 20);
            $table->string('category', 10)->nullable();
            $table->string('title', 200);
            $table->text('body');
            $table->json('data')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['audience', 'name']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->after('customer_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_campaigns');
    }
};
