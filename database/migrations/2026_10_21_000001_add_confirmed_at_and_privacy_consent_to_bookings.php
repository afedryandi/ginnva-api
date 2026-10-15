<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 pengerjaan ulang booking (2026-10-21):
 *  - bookings.confirmed_at: kapan booking dikonfirmasi toko (dasar laporan SLA konfirmasi). Terisi mulai migrasi ini; booking
 *    lama dibiarkan kosong (waktunya tidak tercatat andal).
 *  - bookings.consent_at / consent_version: catatan persetujuan pemberitahuan privasi saat booking (UU PDP): kapan dan versi teks
 *    (hash singkat dari teks yang disetujui) supaya terbukti teks mana yang disetujui.
 *  - Baris awal app_settings untuk teks pemberitahuan privasi (diedit admin di Operasional → Teks Aplikasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'consent_at')) {
                $table->timestamp('consent_at')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'consent_version')) {
                $table->string('consent_version', 16)->nullable();
            }
        });

        if (Schema::hasTable('app_settings') && ! DB::table('app_settings')->where('key', 'booking.privacy_notice')->exists()) {
            DB::table('app_settings')->insert([
                'key' => 'booking.privacy_notice',
                'value' => 'Kami memakai nama, nomor HP, data kendaraan, dan foto kendaraan Anda hanya untuk memproses booking, pengerjaan, garansi, dan komunikasi layanan. Foto kendaraan disimpan 24 bulan setelah layanan selesai, lalu dihapus. Anda dapat meminta akses, koreksi, atau penghapusan data dengan menghubungi toko, atau menghapus akun dari aplikasi.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'booking.privacy_notice')->delete();

        Schema::table('bookings', function (Blueprint $table) {
            foreach (['confirmed_at', 'consent_at', 'consent_version'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
