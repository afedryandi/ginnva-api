<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rantai kepemilikan terstruktur untuk aset tetap — SEBELUMNYA pindah
 * tangan aset cuma edit field assigned_to biasa, riwayatnya cuma diff
 * before/after generik di activity log (tidak ada tempat wajib isi
 * alasan/kondisi saat serah terima). Dipakai oleh aksi "Serah Terima"
 * (lihat AssetResource) — SETIAP transfer wajib tercatat di sini, tidak
 * cuma perubahan kolom assigned_to/store_id polos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('to_store_id')->nullable()->constrained('stores')->nullOnDelete();

            // Kondisi fisik SAAT transfer — wajib diisi, beda dari status
            // aset yang bisa berubah lagi setelahnya.
            $table->enum('condition_at_transfer', ['baik', 'perlu_perhatian', 'rusak']);
            $table->text('reason');

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_transfers');
    }
};
