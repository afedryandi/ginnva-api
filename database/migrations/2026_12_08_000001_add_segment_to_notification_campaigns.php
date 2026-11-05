<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segmen penerima kampanye (Grup Pelanggan, pernah booking, garansi aktif, belum booking sejak N bulan). Disimpan sebagai kriteria, bukan daftar
 * pelanggan, supaya kampanye terjadwal memakai keanggotaan segmen pada saat dikirim, bukan saat dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->json('segment')->nullable()->after('partner_ids');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn('segment');
        });
    }
};
