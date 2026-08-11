<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opsional — sebagian bahan baku (adhesive, primer) punya masa pakai.
     * Nullable karena tidak semua bahan baku kedaluwarsa (mis. sparepart
     * logam, backing paper).
     */
    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->date('expiry_date')->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropColumn('expiry_date');
        });
    }
};
