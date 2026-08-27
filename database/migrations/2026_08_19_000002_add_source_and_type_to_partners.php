<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * source  = halaman mana partner ini SENDIRI daftar (giias/partner) —
     *           beda dari partnership_inquiries.source yang menandai
     *           halaman customer submit form claim, ini soal partner-nya.
     *           Dipakai juga untuk tahu link mana yang harus dipakai
     *           saat cetak ulang QR (lihat PartnerResource::downloadQrPdf()).
     * type    = kategori partner (influencer/komunitas/partner) — rencana
     *           ke depan Ginnva punya beberapa jenis partner, bukan cuma
     *           sales dealer mobil.
     *
     * TIDAK di-backfill "semua partner lama = giias" secara membabi buta
     * — ternyata ADA partner lama yang bukan dari GIIAS (dibuat manual
     * admin untuk keperluan lain), jadi asumsi itu salah. Sinyal yang
     * dipakai di sini: partner yang benar-benar didaftarkan lewat form
     * real-time GIIAS SELALU punya baris partnership_inquiries terkait
     * dengan category='sales' (bagian dari GiiasPartnerSignupController
     * sejak awal, sebelum /partner ada) — partner TANPA baris itu
     * dibiarkan source NULL ("tidak diketahui/manual"), bukan ditebak.
     * Admin bisa isi manual lewat Filament untuk yang butuh QR/link
     * spesifik.
     */
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('status');
            $table->string('type', 20)->default('partner')->after('source');
        });

        DB::table('partners')
            ->whereIn('id', function ($query) {
                $query->select('partner_id')
                    ->from('partnership_inquiries')
                    ->where('category', 'sales')
                    ->whereNotNull('partner_id');
            })
            ->update(['source' => 'giias']);
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['source', 'type']);
        });
    }
};
