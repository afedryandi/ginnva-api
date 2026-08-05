<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * warranty_code TIDAK LAGI di-generate random saat submit — sekarang
 * warranty_code = kode gulungan fisik (lot no dari kardus PPF/Kaca Film,
 * lihat Warranty::syncWarrantyCodeFromRoll()), yang baru diketahui saat
 * staff pilih ScrollCode di Filament. Jadi warranty_code kosong dulu
 * sampai kode gulungan dipilih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->string('warranty_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->string('warranty_code')->nullable(false)->change();
        });
    }
};
