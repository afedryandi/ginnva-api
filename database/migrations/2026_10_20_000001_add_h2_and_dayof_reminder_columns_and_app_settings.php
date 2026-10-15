<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 pengerjaan ulang booking (2026-10-20):
 *  - bookings.h2_reminder_sent_at / dayof_reminder_sent_at: penanda pengingat H-2 dan pagi hari-H ke pelanggan (sekali per booking;
 *    direset saat jadwal dipindah, sama seperti h1_reminder_sent_at).
 *  - app_settings: penyimpanan pengaturan teks sederhana (kunci → nilai) yang bisa diedit admin; dipakai pertama kali untuk
 *    teks kebijakan pembatalan yang tampil ke pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['h2_reminder_sent_at', 'dayof_reminder_sent_at'] as $column) {
            if (! Schema::hasColumn('bookings', $column)) {
                Schema::table('bookings', function (Blueprint $table) use ($column) {
                    $table->timestamp($column)->nullable();
                });
            }
        }

        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('key', 80)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });

            // Baris awal supaya muncul di menu admin; nilai bawaan netral (tanpa angka), diubah admin.
            DB::table('app_settings')->insert([
                'key' => 'booking.cancellation_policy',
                'value' => 'Jika berhalangan, mohon ubah jadwal atau batalkan booking sedini mungkin agar slot bisa dipakai pelanggan lain. Tidak ada biaya pembatalan.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');

        foreach (['h2_reminder_sent_at', 'dayof_reminder_sent_at'] as $column) {
            if (Schema::hasColumn('bookings', $column)) {
                Schema::table('bookings', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
