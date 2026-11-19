<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan sisa (wasted) dipakai langsung untuk sebuah SPK, tanpa lewat memo (PPF/WF berdiri sendiri, keputusan pemilik 2026-12-20). used_in_spk_id terisi bila SPK
 * dipilih atau nomor yang diketik cocok dengan SPK toko roll itu; used_spk_number menyimpan nomor yang diketik bebas. Kolom pemakaian lewat memo (used_in_memo_item_id)
 * tetap untuk riwayat lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scroll_code_offcuts', function (Blueprint $table) {
            $table->foreignId('used_in_spk_id')->nullable()->after('used_in_memo_item_id')->constrained('spks')->nullOnDelete();
            $table->string('used_spk_number', 100)->nullable()->after('used_in_spk_id');
        });
    }

    public function down(): void
    {
        Schema::table('scroll_code_offcuts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('used_in_spk_id');
            $table->dropColumn('used_spk_number');
        });
    }
};
