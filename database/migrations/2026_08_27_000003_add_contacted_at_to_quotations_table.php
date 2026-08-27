<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA follow-up lead — sebelumnya tidak ada cara mengukur seberapa cepat
 * staff menindaklanjuti lead baru. contacted_at diisi OTOMATIS (lihat
 * QuotationObserver::updating()) begitu status pertama kali berubah dari
 * 'new' ke apa pun — baik lewat Filament maupun mobile app staff, satu
 * titik logika, bukan diulang di 2 tempat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestamp('contacted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('contacted_at');
        });
    }
};
