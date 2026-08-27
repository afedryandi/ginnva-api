<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tanggal barang fisik masuk gudang — bisa beda dari created_at (kapan
     * datanya didaftarkan di sistem), misal input data menyusul beberapa
     * hari setelah barang fisik sebenarnya diterima. Nullable karena data
     * lama tidak punya nilai ini.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->date('received_date')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('received_date');
        });
    }
};
