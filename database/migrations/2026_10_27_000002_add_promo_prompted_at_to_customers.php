<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda bahwa pelanggan sudah pernah ditanya soal notifikasi promo (Marketing/Konten Fase 5), supaya pertanyaan persetujuan di aplikasi
 * hanya muncul sekali. Terisi saat pelanggan memilih "ya" maupun "tidak".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'promo_prompted_at')) {
                $table->dateTime('promo_prompted_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'promo_prompted_at')) {
                $table->dropColumn('promo_prompted_at');
            }
        });
    }
};
