<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability roll_number ke Booking (diminta user 2026-09-15) — staff
 * pilih booking 'confirmed' yang mana saat "Catat Pemakaian" dari layar
 * Inventaris, supaya kalau 1 roll ternyata cacat, semua booking yang
 * pakai roll itu bisa ditemukan lewat ScrollCodeResource > Riwayat
 * Pemakaian. Nullable -- booking lama/histori sebelum fitur ini tidak
 * akan punya link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scroll_code_usages', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('scroll_code_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scroll_code_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
        });
    }
};
