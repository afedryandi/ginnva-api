<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3C pengerjaan ulang booking (2026-10-19):
 *  - bookings.no_show_at: pelanggan terkonfirmasi tidak datang. Booking tetap berstatus 'cancelled' (slot kapasitas langsung
 *    terlepas, DP memakai alur batal yang sudah teruji); kolom ini hanya menandai alasannya untuk laporan.
 *  - booking_intake_photos: foto penerimaan kendaraan (4 sudut baku + foto kerusakan tambahan) sebagai bukti kondisi awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'no_show_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('no_show_at')->nullable();
            });
        }

        if (! Schema::hasTable('booking_intake_photos')) {
            Schema::create('booking_intake_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
                $table->string('angle', 20); // depan, belakang, kiri, kanan, kerusakan
                $table->string('path');
                $table->string('note', 255)->nullable();
                $table->foreignId('taken_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['booking_id', 'angle']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_intake_photos');

        if (Schema::hasColumn('bookings', 'no_show_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('no_show_at');
            });
        }
    }
};
