<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banner sebelumnya cuma tampil ke Customer (app utama). Kolom ini
     * memisahkan target audiens supaya banner promo Partner (mitra
     * referral) bisa dikelola dari resource yang sama tanpa tercampur ke
     * beranda Customer. Default 'customer' supaya semua baris lama tetap
     * tampil persis seperti sebelumnya di app Customer.
     */
    public function up(): void
    {
        Schema::table('carousels', function (Blueprint $table) {
            $table->enum('audience', ['customer', 'partner', 'both'])
                ->default('customer')
                ->after('link_url');
        });
    }

    public function down(): void
    {
        Schema::table('carousels', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
