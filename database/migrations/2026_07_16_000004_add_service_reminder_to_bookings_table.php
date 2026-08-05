<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminder servis berkala TIDAK dihitung otomatis oleh sistem (interval
 * beda-beda per kasus, misal Kaca Film vs PPF) — store manager yang
 * menentukan tanggalnya manual per booking lewat Filament
 * (BookingResource), lalu command terjadwal (SendServiceReminders) yang
 * mengeksekusi pengiriman WhatsApp+Push+Email otomatis begitu tanggal itu
 * tiba. `service_reminder_sent_at` mencegah reminder yang sama terkirim
 * berkali-kali kalau command jalan lebih dari 1x di hari yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('next_service_reminder_at')->nullable()->after('current_stage');
            $table->timestamp('service_reminder_sent_at')->nullable()->after('next_service_reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['next_service_reminder_at', 'service_reminder_sent_at']);
        });
    }
};
