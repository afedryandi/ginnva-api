<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedupe pendaftaran "Become a Partner" (/giias) sekarang utamanya
     * lewat nomor WA (email jadi opsional) — sebelumnya email yang
     * UNIQUE di level database jadi jaring pengaman terakhir kalau 2
     * submit nyaris bersamaan lolos cek aplikasi. phone TIDAK pernah
     * punya constraint itu, jadi race yang sama sekarang bisa bikin 2
     * akun partner untuk 1 nomor WA yang sama. Constraint ini yang
     * menutup celah itu — nullable-safe (MySQL boleh banyak baris NULL
     * di kolom UNIQUE, partner yang dibuat manual tanpa nomor WA tetap
     * aman).
     *
     * Duplikat yang SUDAH ADA (kalau race ini sempat kejadian sebelum
     * fix ini) di-null-kan salah satu barisnya dulu (bukan dihapus —
     * datanya tetap ada, cuma nomor WA-nya perlu digabung manual oleh
     * admin) supaya index unique bisa dibuat tanpa gagal.
     */
    public function up(): void
    {
        $duplicates = DB::table('partners')
            ->select('phone')
            ->whereNotNull('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        foreach ($duplicates as $phone) {
            $ids = DB::table('partners')->where('phone', $phone)->orderBy('id')->pluck('id');

            // Baris PALING LAMA (id terkecil) yang pertahankan nomor WA-nya
            // apa adanya — sisanya di-null-kan supaya tidak hilang jejak
            // record-nya, cuma tidak lagi bentrok di index unique.
            DB::table('partners')->whereIn('id', $ids->skip(1))->update(['phone' => null]);
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }
};
