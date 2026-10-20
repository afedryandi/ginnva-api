<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keuangan Fase 6 (2026-11-09): penutupan tahun buku. Satu baris per tahun yang ditutup; jurnal penutup (satu per toko ditambah satu untuk
 * jurnal tanpa toko) mengacu ke tahun itu lewat reference_type "year_end_closing". Pembatalan membalik jurnal penutup dan menandai baris ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_end_closings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->decimal('net_income', 15, 2);
            $table->unsignedSmallInteger('entries_count');
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['year', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_closings');
    }
};
