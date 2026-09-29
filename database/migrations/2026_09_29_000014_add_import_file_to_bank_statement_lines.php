<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Rekonsiliasi Bank 2026-09-29 (gap enterprise): arsipkan file mutasi yang diimpor (sebelumnya
 * dihapus langsung setelah diproses) supaya bisa ditelusuri "file apa yang diimpor tanggal X" saat
 * audit -- disimpan di tabel batch tersendiri (1 file bisa menghasilkan banyak baris mutasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch')->unique();
            $table->foreignId('chart_of_account_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename')->nullable();
            $table->string('archived_path')->nullable();
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_import_batches');
    }
};
