<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pekerjaan ulang pada booking (Karyawan Fase 4 lanjutan, 2026-11-03): catatan bahwa sebuah booking perlu dikerjakan ulang, apa sumbernya
 * (QC gagal, keluhan pelanggan, temuan internal), dan kapan selesai. Dipakai laporan Kompetensi Installer untuk pembinaan, bukan potongan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_reworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20); // qc_gagal | keluhan_pelanggan | temuan_internal
            $table->text('reason');
            $table->date('reported_on');
            $table->date('resolved_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id']);
            $table->index(['store_id', 'reported_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reworks');
    }
};
