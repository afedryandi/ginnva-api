<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kelipatan Pengambilan (2026-11-23): bahan baku yang hanya boleh diambil dalam kelipatan tertentu (mis. 250 ml). Kosong = bebas seperti
     * sebelumnya, jadi tidak mengubah perilaku bahan yang sudah ada. Murni tambahan, aman dibalik.
     */
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->decimal('withdrawal_multiple', 12, 2)->nullable()->after('reorder_point');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn('withdrawal_multiple');
        });
    }
};
