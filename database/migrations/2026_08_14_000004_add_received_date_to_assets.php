<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beda dari purchase_date (kapan aset dibeli) — received_date adalah
     * kapan aset fisik sampai/diterima di toko, bisa beda hari kalau
     * dibeli pusat lalu didistribusikan.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->date('received_date')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('received_date');
        });
    }
};
