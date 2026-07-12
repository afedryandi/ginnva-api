<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom denormalized supaya "tahap instalasi saat ini" bisa dibaca cepat
 * (mis. untuk kartu ringkasan di beranda mobile app) tanpa perlu query
 * booking_messages tiap saat. Diupdate otomatis oleh BookingMessageObserver
 * setiap kali admin mengirim pesan bertipe 'stage'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('current_stage', ['received', 'preparation', 'installation', 'qc', 'completed'])
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('current_stage');
        });
    }
};
