<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sama seperti customer_notification_reads — read_at di
 * partner_notifications tidak cukup untuk broadcast (1 baris dibagi
 * SEMUA partner), jadi status baca per-partner untuk broadcast disimpan
 * di sini. Notifikasi bertarget (partner_id terisi) tetap pakai read_at
 * langsung di partner_notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('partner_notification_id')->constrained('partner_notifications')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['partner_id', 'partner_notification_id'], 'partner_notif_reads_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_notification_reads');
    }
};
