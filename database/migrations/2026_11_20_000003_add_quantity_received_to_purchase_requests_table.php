<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jumlah yang benar-benar diterima saat permohonan ditandai Terpenuhi (audit ACCURATE 5, kolom "Qty Received" pada permintaan barang).
     * Kosong untuk permohonan lama (tidak diketahui), jadi tidak dianggap kurang.
     */
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('quantity_received', 12, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('quantity_received');
        });
    }
};
