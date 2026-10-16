<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing/Konten Fase 2: sumber tunggal klaim produk (TSER, UV, VLT, garansi, dst.) dengan bukti dan persetujuan. Hanya klaim berstatus
 * "approved" yang boleh tampil di web/app (UU Perlindungan Konsumen Pasal 9/10: angka yang diiklankan harus benar dan dapat dibuktikan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_claims')) {
            return;
        }

        Schema::create('product_claims', function (Blueprint $table) {
            $table->id();
            $table->string('series', 60);                  // mis. "A70", "H30", atau "Umum"
            $table->string('claim_type', 20);              // tser | uv | vlt | ir | clarity | warranty_years | other
            $table->string('label', 120);                  // teks yang tampil, mis. "Heat Rejection (TSER)"
            $table->string('value', 60);                   // mis. "61%" atau "10 tahun"
            $table->string('evidence_path')->nullable();   // berkas laporan uji / sertifikat
            $table->text('source_note')->nullable();       // asal angka bila tidak ada berkas (mis. "Lembar data pabrikan rev. 2026-03")
            $table->date('tested_at')->nullable();         // tanggal uji / tanggal dokumen
            $table->string('status', 12)->default('draft'); // draft | approved | withdrawn
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'series']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_claims');
    }
};
