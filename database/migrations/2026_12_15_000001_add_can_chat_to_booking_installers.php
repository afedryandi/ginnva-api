<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izin chat booking per installer yang ditugaskan (2026-12-15): bawaan mati, dinyalakan saat Assignment oleh store manager atau
 * akses penuh. Installer yang diizinkan boleh teks, foto, dan menandai tahap di chat booking tersebut saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('booking_installers', 'can_chat')) {
            Schema::table('booking_installers', function (Blueprint $table) {
                $table->boolean('can_chat')->default(false)->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('booking_installers', 'can_chat')) {
            Schema::table('booking_installers', function (Blueprint $table) {
                $table->dropColumn('can_chat');
            });
        }
    }
};
