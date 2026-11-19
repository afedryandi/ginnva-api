<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memo Barang tertaut ke SPK (alur Booking -> SPK -> Memo, permintaan pemilik 2026-12-17). Kolom spk_number (teks bebas) tetap ada untuk memo
 * tanpa SPK dan memo lama; spk_id baru terisi bila SPK dipilih. Memo lama tidak diisi otomatis (keputusan pemilik: dibiarkan). SPK yang
 * dihapus hanya melepas tautan (nullOnDelete), memo dan stoknya tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->foreignId('spk_id')->nullable()->after('booking_id')->constrained('spks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spk_id');
        });
    }
};
