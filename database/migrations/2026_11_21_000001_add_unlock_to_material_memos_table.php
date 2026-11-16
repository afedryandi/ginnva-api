<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memo Pengambilan/Pengembalian terkunci otomatis begitu booking-nya Selesai (pemakaian bahan sudah dibukukan sebagai HPP). Akses penuh
 * bisa membuka kunci dengan alasan wajib; kolom ini mencatat kapan, siapa, dan alasannya. Null = tidak pernah dibuka (tetap terkunci).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->timestamp('unlocked_at')->nullable()->after('notes');
            $table->unsignedBigInteger('unlocked_by')->nullable()->after('unlocked_at');
            $table->string('unlock_reason', 500)->nullable()->after('unlocked_by');
        });
    }

    public function down(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->dropColumn(['unlocked_at', 'unlocked_by', 'unlock_reason']);
        });
    }
};
