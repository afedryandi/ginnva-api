<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customer_notifications.read_at TIDAK cukup untuk notifikasi broadcast
 * (customer_id = null, 1 baris dibagi ke SEMUA customer) — kalau 1
 * customer menandai baca, read_at di baris itu ke-update dan notifikasi
 * jadi "sudah dibaca" untuk SEMUA customer lain juga. Tabel ini menyimpan
 * status baca PER CUSTOMER PER NOTIFIKASI khusus untuk kasus broadcast;
 * notifikasi bertarget (customer_id terisi) tetap pakai read_at langsung
 * di tabel customer_notifications seperti sebelumnya karena memang cuma
 * relevan untuk 1 customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_notification_id')->constrained('customer_notifications')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['customer_id', 'customer_notification_id'], 'cust_notif_reads_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notification_reads');
    }
};
