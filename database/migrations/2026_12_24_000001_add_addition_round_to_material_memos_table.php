<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengambilan tambahan memo (2026-11-24): berapa kali memo ini diajukan lagi untuk pengambilan kedua dan seterusnya setelah selesai
     * diserahkan. 0 = masih pengambilan pertama (disetujui store manager, dicatat staf gudang). Lebih dari 0 = pengambilan tambahan
     * (disetujui dan dicatat admin atau spv finance). Murni tambahan kolom, aman dibalik; memo lama tetap 0.
     */
    public function up(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->unsignedSmallInteger('addition_round')->default(0)->after('request_note');
        });
    }

    public function down(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->dropColumn('addition_round');
        });
    }
};
