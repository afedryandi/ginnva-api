<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen personalia (KTP/NPWP/Ijazah/Kontrak/dll) per karyawan (audit
 * Majoo, f54). File disimpan di disk PRIVATE (bukan public) -- akses
 * baca lewat aksi "Download" yang terautentikasi Filament, BUKAN URL
 * langsung, sama pola dengan file upload sensitif lain di aplikasi ini
 * (mis. product-imports di FilmProductResource).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
