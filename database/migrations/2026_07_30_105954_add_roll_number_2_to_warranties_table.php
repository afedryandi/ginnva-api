<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sebagian mobil (biasanya bodi besar) butuh 2 gulungan PPF sekaligus
     * untuk 1 kali instalasi — sebelumnya kolom `roll_number` cuma
     * menampung 1 kode. `roll_number_2` OPSIONAL, dipakai kalau memang
     * butuh gulungan kedua (lihat Warranty::booted() & WarrantyObserver
     * untuk logic penandaan ScrollCode-nya — diperlakukan sama seperti
     * roll_number: single-use, langsung 'used' begitu dipakai).
     */
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->string('roll_number_2')->nullable()->after('roll_number');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropColumn('roll_number_2');
        });
    }
};
