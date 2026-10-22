<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan perusahaan di bawah panduan (Panduan Fase 4, 2026-10-22): teks tambahan yang diubah pemilik di panel tanpa rilis. Satu catatan
 * per panduan (guide_id = id di GuideCatalog, bukan foreign key karena panduan hidup di kode). Hanya menambah tabel baru, aman dibalik.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('guide_notes', function (Blueprint $table) {
            $table->id();
            $table->string('guide_id', 100)->unique();
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_notes');
    }
};
