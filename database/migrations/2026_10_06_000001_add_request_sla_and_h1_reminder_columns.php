<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pelacak SLA pengajuan customer (jadwal ulang & pembatalan) dan penanda
 * pengingat H-1 booking (keputusan user 2026-10-06). Hanya menambah kolom
 * nullable/default, jadi aman dan reversibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['booking_reschedule_requests', 'booking_cancellation_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedTinyInteger('reminder_count')->default(0);
                $t->timestamp('reminder_sent_at')->nullable();
            });
        }

        Schema::table('bookings', function (Blueprint $t) {
            $t->timestamp('h1_reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['booking_reschedule_requests', 'booking_cancellation_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['reminder_count', 'reminder_sent_at']);
            });
        }

        Schema::table('bookings', function (Blueprint $t) {
            $t->dropColumn('h1_reminder_sent_at');
        });
    }
};
