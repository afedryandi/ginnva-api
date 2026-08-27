<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1 baris = 1 karyawan per hari (clock_in/clock_out diisi progresif di
 * hari yang sama), bukan 1 baris per kejadian — supaya agregasi bulanan
 * (total menit telat sebulan, dasar potongan gaji nanti di Penggajian)
 * tinggal SUM tanpa perlu gabung baris masuk+keluar dulu.
 *
 * entry_type membedakan 3 skenario dari requirement Bu Yennie:
 * - 'clock'      : absen normal via app, GPS direkam & dicek jarak ke toko.
 * - 'manual'     : device/wifi toko mati, admin/atasan input manual dengan
 *                  catatan alasan — TIDAK melalui cek GPS.
 * - 'field_duty' : staff langsung dinas luar/ambil kendaraan client, tidak
 *                  wajib hadir ke lokasi toko dulu — juga tidak dihitung
 *                  telat (late_minutes selalu 0 untuk 2 tipe non-'clock').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->enum('entry_type', ['clock', 'manual', 'field_duty'])->default('clock');

            $table->dateTime('clock_in_at')->nullable();
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->unsignedInteger('clock_in_distance_meters')->nullable();

            $table->dateTime('clock_out_at')->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();

            $table->unsignedInteger('late_minutes')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
